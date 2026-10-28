<?php
// Path: src/Club/ClubService.php
// 機能: 対象クラブ権限と受付期間を検査し、年度登録・継続・訂正・事務局承認・資格再判定を行う。
declare(strict_types=1);
namespace Jmrc\Club;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
final class ClubService
{
    private const FIELDS=['name_full'=>255,'name_short'=>128,'rep_name'=>128,'contact_name'=>128,'postcode'=>16,'address'=>255,'phone'=>32,'email'=>190];
    public function __construct(private readonly PDO $db,private readonly ?string $dataKey=null) {}

    /** クラブは既存マスターと明示grantから解決。名称や旧アカウントroleだけで別クラブを作らない。 */
    public function requireAccess(int $actor,int $club,bool $approve=false): void
    {
        $permission=$approve?'club.registration.approve':'club.registration.write';
        $s=$this->db->prepare("SELECT g.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id
            JOIN portal_user_role_grants g ON g.user_id=u.id JOIN portal_roles r ON r.id=g.role_id
            JOIN portal_role_permissions p ON p.role_id=g.role_id AND p.permission_code=?
            WHERE u.id=? AND u.email_verified=1 AND c.status='active' AND g.status='active' AND g.approved_by IS NOT NULL
            AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)
            AND ((r.code='OFFICE' AND g.scope_type='global') OR (?=0 AND r.code='CLUB_REP' AND g.scope_type='club' AND g.scope_club_id=?)) LIMIT 1");
        $s->execute([$permission,$actor,(int)$approve,$club]);
        if (!$s->fetchColumn()) { throw new HttpFailure(403,'対象クラブの登録権限または事務局承認権限が必要です'); }
    }

    /** 受付開始/締切は年度マスターに従う。前年の最新登録から口座等を明示的に引き継げる。 */
    public function submit(int $actor,int $club,int $year,string $category,array $input,string $key): array
    {
        return $this->tx(function () use ($actor,$club,$year,$category,$input,$key): array {
            $this->requireAccess($actor,$club);$this->key($key);$fields=$this->fields($input);
            $master=$this->one('SELECT * FROM portal_clubs WHERE id=? FOR UPDATE',[$club]);
            if (!$master) { throw new HttpFailure(404,'クラブマスターがありません'); }
            $old=$this->one('SELECT cy.*,a.request_key,a.applicant_user_id FROM portal_club_years cy JOIN portal_applications a ON a.id=cy.application_id WHERE cy.club_id=? AND cy.year=? FOR UPDATE',[$club,$year]);
            if ($old) {
                $same=$old['request_key']===$key && (int)$old['applicant_user_id']===$actor && $old['category_digit']===$category;
                foreach ($fields as $name=>$value) { $same=$same && $old[$name]===$value; }
                if (isset($input['bank_details']) && $input['bank_details']!=='') {
                    if (!is_string($input['bank_details'])) { throw new HttpFailure(422,'口座情報を確認してください'); }
                    $raw=base64_decode(substr((string)$old['bank_details_cipher'],5),true);$dataKey=base64_decode($this->dataKey??'',true);
                    if ($raw===false || strlen($raw)<28 || $dataKey===false || strlen($dataKey)!==32) { throw new HttpFailure(503,'口座保管設定を確認してください'); }
                    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$dataKey,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'jmrc:club-bank:v1');
                    $same=$same && $plain!==false && hash_equals($plain,$input['bank_details']);
                }
                // 口座変更は再送扱いせず専用訂正へ。機密値を冪等記録やログへ保存しない。
                if (!$same) { throw new HttpFailure(409,'年度登録済みです。既存の登録を確認してください'); }
                return $this->read($actor,(int)$old['id'])+['replayed'=>true];
            }
            if (!$this->one("SELECT year FROM portal_years WHERE year=? AND source_mode='portal' AND club_open_at<=UTC_TIMESTAMP(6)
                AND UTC_TIMESTAMP(6)<club_close_at AND UTC_TIMESTAMP(6)<valid_until FOR UPDATE",[$year])) { throw new HttpFailure(422,'クラブ年度登録の受付期間外です'); }
            if (!$this->one('SELECT category_digit FROM portal_club_categories WHERE category_digit=? AND enabled=1',[$category])) { throw new HttpFailure(422,'有効なクラブ区分を指定してください'); }
            $bank=$this->bank($club,$year,$input);
            $this->run("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key,submitted_at) VALUES('club',?,?,'submitted',?,UTC_TIMESTAMP(6))",[$actor,$year,$key]);$app=(int)$this->db->lastInsertId();
            $this->run("INSERT INTO portal_club_years(club_id,year,category_digit,display_code,application_id,name_full,name_short,rep_name,contact_name,postcode,address,phone,email,bank_details_cipher)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",[$club,$year,$category,$category.$master['fixed_code'],$app,...array_values($fields),$bank]);
            $id=(int)$this->db->lastInsertId();$this->audit($actor,'CLUB_REGISTERED',$id,$key);
            return $this->read($actor,$id)+['replayed'=>false];
        });
    }

    /** コード/年度/区分は編集しない。連絡先・名称・振込先を更新し、元の請求スナップショットを保持。 */
    public function update(int $actor,int $id,array $input,int $version): array
    {
        return $this->tx(function () use ($actor,$id,$input,$version): array {
            $r=$this->one('SELECT * FROM portal_club_years WHERE id=? FOR UPDATE',[$id])?:throw new HttpFailure(404,'年度登録がありません');
            $this->requireAccess($actor,(int)$r['club_id']);
            if ((int)$r['row_version']!==$version) { throw new HttpFailure(409,'別の更新があります。再読込してください'); }
            foreach (['year','category_digit','display_code','club_id'] as $immutable) {
                if (isset($input[$immutable]) && (string)$input[$immutable]!== (string)$r[$immutable]) { throw new HttpFailure(409,'年度内のクラブコードは変更できません'); }
            }
            $f=$this->fields($input);$bank=$r['bank_details_cipher'];
            if (isset($input['bank_details']) && $input['bank_details']!=='') { $bank=$this->encrypt($input['bank_details']); }
            $this->run('UPDATE portal_club_years SET name_full=?,name_short=?,rep_name=?,contact_name=?,postcode=?,address=?,phone=?,email=?,bank_details_cipher=?,row_version=row_version+1 WHERE id=?',[...array_values($f),$bank,$id]);
            $this->audit($actor,'CLUB_CONTACT_UPDATED',$id,bin2hex(random_bytes(24)));return $this->read($actor,$id);
        });
    }

    /** 確定要件「申請・入金後に事務局承認」。財務単独や代表の自己承認は許さない。 */
    public function approve(int $actor,int $id): array
    {
        return $this->tx(function () use ($actor,$id): array {
            $r=$this->one('SELECT * FROM portal_club_years WHERE id=? FOR UPDATE',[$id])?:throw new HttpFailure(404,'年度登録がありません');
            $this->requireAccess($actor,(int)$r['club_id'],true);
            $state=(new ClubEligibility($this->db))->evaluate($id);
            if (!$state['paid']) { throw new HttpFailure(409,'クラブ年会費の満額入金確認が必要です'); }
            $a=$this->one('SELECT status FROM portal_applications WHERE id=? FOR UPDATE',[$r['application_id']]);
            if ($a['status']==='approved') { return $this->read($actor,$id); }
            if ($a['status']!=='submitted' || $r['status']!=='pending') { throw new HttpFailure(409,'現在の状態では承認できません'); }
            if (!$this->one('SELECT category_digit FROM portal_club_categories WHERE category_digit=? AND enabled=1',[$r['category_digit']])) { throw new HttpFailure(409,'クラブ区分を確認してください'); }
            $this->run("UPDATE portal_applications SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$actor,$r['application_id']]);
            $this->run('UPDATE portal_club_years SET approved_by=?,approved_at=UTC_TIMESTAMP(6) WHERE id=?',[$actor,$id]);
            $this->refresh($id);$this->audit($actor,'CLUB_APPROVED',$id,'approval:'.$id);return $this->read($actor,$id);
        });
    }

    /** 入金/訂正と同じ取引内で呼ぶ。クラブ年度の状態と配下の個人資格をまとめて再判定。 */
    public function refresh(int $id): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Club refresh requires transaction'); }
        $r=$this->one('SELECT cy.status,a.status AS approval FROM portal_club_years cy JOIN portal_applications a ON a.id=cy.application_id WHERE cy.id=? FOR UPDATE',[$id]);
        $state=(new ClubEligibility($this->db))->evaluate($id);
        if (in_array($r['status'],['pending','approved'],true)) {
            $this->run('UPDATE portal_club_years SET status=?,row_version=row_version+1 WHERE id=?',[$state['paid'] && $r['approval']==='approved'?'approved':'pending',$id]);
        }
        $s=$this->db->prepare("SELECT m.id FROM portal_memberships m JOIN portal_affiliations f ON f.membership_id=m.id WHERE f.club_year_id=? AND f.status='approved' AND f.valid_until IS NULL ORDER BY m.id FOR UPDATE");$s->execute([$id]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $member) { (new \Jmrc\Billing\Eligibility($this->db))->refresh((int)$member); }
    }

    /** 暗号文も平文口座も返さない。継続入力は直近の過年度情報を返す。 */
    public function read(int $actor,int $id): array
    {
        $r=$this->one('SELECT cy.*,COALESCE(cy.name_full,c.name_full) AS name_full,COALESCE(cy.name_short,c.name_short) AS name_short FROM portal_club_years cy JOIN portal_clubs c ON c.id=cy.club_id WHERE cy.id=?',[$id])?:throw new HttpFailure(404,'年度登録がありません');$this->requireAccess($actor,(int)$r['club_id']);
        $r['has_bank_details']=!empty($r['bank_details_cipher']);unset($r['bank_details_cipher']);
        return $r+['eligibility'=>(new ClubEligibility($this->db))->evaluate($id)];
    }
    private function fields(array $input): array
    {
        $out=[];foreach(self::FIELDS as $field=>$max) {
            if (!is_string($input[$field]??null) || trim($input[$field])==='' || preg_match_all('/./us',$input[$field])===false || preg_match_all('/./us',$input[$field])>$max || preg_match('/[\x00-\x1F]/',$input[$field])) { throw new HttpFailure(422,'クラブ登録項目を確認してください'); }
            $out[$field]=trim($input[$field]);
        }
        if (!filter_var($out['email'],FILTER_VALIDATE_EMAIL)) { throw new HttpFailure(422,'メールアドレスを確認してください'); }return $out;
    }
    private function bank(int $club,int $year,array $input): string
    {
        if (isset($input['bank_details']) && $input['bank_details']!=='') { return $this->encrypt($input['bank_details']); }
        if (($input['carry_bank']??'')==='1') {
            $r=$this->one('SELECT bank_details_cipher FROM portal_club_years WHERE club_id=? AND year<? ORDER BY year DESC LIMIT 1',[$club,$year]);
            if (!empty($r['bank_details_cipher'])) { return $r['bank_details_cipher']; }
        }
        throw new HttpFailure(422,'振込先口座を入力するか前年度からの継続を選択してください');
    }
    /** AES-256-GCM。実鍵はWeb非公開設定から取得し、DB・監査・レスポンスへ含めない。 */
    private function encrypt(mixed $value): string
    {
        if (!is_string($value) || trim($value)==='' || strlen($value)>4000) { throw new HttpFailure(422,'振込先口座を確認してください'); }
        $key=base64_decode($this->dataKey??'',true);
        if ($key===false || strlen($key)!==32) { throw new HttpFailure(503,'口座保管用の暗号鍵が設定されていません'); }
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'jmrc:club-bank:v1');
        if ($cipher===false) { throw new \RuntimeException('Club bank encryption failed'); }return 'gcm1:'.base64_encode($iv.$tag.$cipher);
    }
    private function key(string $key): void { if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) { throw new HttpFailure(422,'申請キーを確認してください'); } }
    private function audit(int $actor,string $action,int $id,string $key): void
    {
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES (?,?,'club_year',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,bin2hex(random_bytes(16))]);
        $this->run("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$key,$action,$id]);
    }
    private function run(string $sql,array $args): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function one(string $sql,array $args): array|false { return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC); }
    private function tx(callable $fn): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Independent club transaction required'); }
        for($i=0;$i<3;$i++) {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try { $r=$fn();$this->db->commit();return $r; }
            catch(\Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                if ($e instanceof PDOException) {
                    $n=(int)($e->errorInfo[1]??0);if(in_array($n,[1205,1213],true) && $i<2) { usleep(20000*($i+1));continue; }
                    if($n===1062) { throw new HttpFailure(409,'同じ年度の登録または処理キーが既にあります'); }
                }throw $e;
            }
        }throw new HttpFailure(503,'年度登録を再度お試しください');
    }
}
