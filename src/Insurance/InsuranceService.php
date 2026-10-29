<?php
// Path: src/Insurance/InsuranceService.php
// 機能: 個人会員の保険加入意思を受付け、対象クラブ代表/事務局に確認用Excelを提供。加入確定は行わない。
declare(strict_types=1);
namespace Jmrc\Insurance;
use PDO;
use Jmrc\Auth\HttpFailure;
final class InsuranceService
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}

    /** 本人の個人会員登録だけに紐付ける。Generalのみを対象とし、Temporary・ライセンスなしは受付しない。 */
    public function request(int $actor,int $membership,bool $consent): array
    {
        $this->enabled();if(!$consent){throw new HttpFailure(422,'加入意思の確認が必要です');}
        return $this->tx(function()use($actor,$membership):array{
            $this->actor($actor);
            $m=$this->one('SELECT * FROM portal_memberships WHERE id=? AND user_id=? FOR UPDATE',[$membership,$actor]);
            if(!$m){throw new HttpFailure(404,'本人の個人会員登録がありません');}
            if($m['member_type']!=='general'){throw new HttpFailure(422,'スポーツ安全保険はGeneralのみです。Temporaryは加入できません');}
            $club=$this->currentClub($membership);
            if(!$club){throw new HttpFailure(409,'現在の所属・主催クラブ登録を確認してください');}
            $old=$this->one('SELECT * FROM portal_insurance_requests WHERE membership_id=? FOR UPDATE',[$membership]);
            if($old && (int)$old['club_year_id']===$club){return $this->publicRow($old)+['replayed'=>true];}
            if($old){
                $this->run('UPDATE portal_insurance_requests SET club_year_id=?,consented_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?',[$club,$old['id']]);$id=(int)$old['id'];
            }else{
                $this->run('INSERT INTO portal_insurance_requests(membership_id,year,club_year_id,consented_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$membership,$m['year'],$club]);$id=(int)$this->db->lastInsertId();
            }
            $this->audit($actor,'INSURANCE_INTENT_RECORDED',$id);
            return $this->publicRow($this->one('SELECT * FROM portal_insurance_requests WHERE id=?',[$id]))+['replayed'=>false];
        });
    }
    public function mine(int $actor): array
    {
        $this->enabled();$this->actor($actor);
        return $this->run('SELECT r.id,r.membership_id,r.year,r.club_year_id,r.consented_at,r.row_version FROM portal_insurance_requests r JOIN portal_memberships m ON m.id=r.membership_id WHERE m.user_id=? ORDER BY r.id',[$actor])->fetchAll(PDO::FETCH_ASSOC);
    }
    /** 操作対象の年度クラブも現在の承認済みgrantから選ぶ。 */
    public function clubs(int $actor): array
    {
        $this->enabled();$this->actor($actor);
        return $this->run("SELECT cy.id,cy.year,cy.name_full FROM portal_club_years cy WHERE EXISTS(
            SELECT 1 FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=r.id
            WHERE g.user_id=? AND p.permission_code='insurance.export' AND g.status='active' AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL
            AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)
            AND ((r.code='OFFICE' AND g.scope_type='global') OR (r.code='CLUB_REP' AND g.scope_type='club' AND g.scope_club_id=cy.club_id))) ORDER BY cy.year DESC,cy.id",[$actor])->fetchAll(PDO::FETCH_ASSOC);
    }
    /** 氏名等の一覧もダウンロードと同じ対象クラブの明示権限で保護する。 */
    public function listing(int $actor,int $clubYear): array
    {
        $this->enabled();$this->access($actor,$clubYear);
        $rows=$this->run('SELECT r.*,m.user_id,m.member_type FROM portal_insurance_requests r JOIN portal_memberships m ON m.id=r.membership_id WHERE r.club_year_id=? ORDER BY r.id',[$clubYear])->fetchAll(PDO::FETCH_ASSOC);$out=[];
        foreach($rows as $r){if($this->currentClub((int)$r['membership_id'])===$clubYear){$out[]=$this->publicRow($r)+$this->person($r)+['member_type'=>$r['member_type']];}}
        return $out;
    }
    /** 初回4名はクラブ・年度単位。有効Generalの意思だけを数え、確認票の生成を加入実績にしない。 */
    public function readiness(int $actor,int $clubYear): array
    {
        $rows=$this->listing($actor,$clubYear);$count=0;
        foreach($rows as $row){if((new \Jmrc\Billing\Eligibility($this->db))->evaluate((int)$row['membership_id'])['active']){$count++;}}
        return ['eligible_count'=>$count,'initial_required'=>4,'initial_minimum_met'=>$count>=4,
            'policy_hold'=>true,'can_submit'=>false];
    }
    /** 出力はPOST+CSRFから呼ぶ。帳票生成・対象版の記録・監査を同一トランザクションで確定する。 */
    public function export(int $actor,int $clubYear,array $ids): array
    {
        $this->enabled();
        if(count($ids)<1 || count($ids)>24 || count(array_unique($ids,SORT_REGULAR))!==count($ids)){throw new HttpFailure(422,'重複なく1～24件を選択してください');}
        foreach($ids as $id){if(!is_int($id) || $id<1){throw new HttpFailure(422,'申請IDを確認してください');}}
        sort($ids,SORT_NUMERIC);
        return $this->tx(function()use($actor,$clubYear,$ids):array{
            $club=$this->access($actor,$clubYear);$members=[];$requests=[];
            foreach($ids as $id){
                $r=$this->one('SELECT r.*,m.user_id,m.member_type FROM portal_insurance_requests r JOIN portal_memberships m ON m.id=r.membership_id WHERE r.id=? AND r.club_year_id=? FOR UPDATE',[$id,$clubYear]);
                if(!$r){throw new HttpFailure(404,'対象クラブの申請がありません');}
                if($this->currentClub((int)$r['membership_id'])!==$clubYear){throw new HttpFailure(409,'所属・会員登録が変わっています。本人の再申請を確認してください');}
                $members[]=$this->person($r);$requests[]=$r;
            }
            $bytes=(new InsuranceWorkbook())->build($this->config['insurance_template']??'', $club,$members);
            $this->run('INSERT INTO portal_insurance_exports(club_year_id,generated_by,template_sha256,output_sha256,member_count) VALUES(?,?,?,?,?)',[$clubYear,$actor,InsuranceWorkbook::TEMPLATE_HASH,hash('sha256',$bytes),count($members)]);$export=(int)$this->db->lastInsertId();
            foreach($requests as $r){$this->run('INSERT INTO portal_insurance_export_items(export_id,request_id,request_version) VALUES(?,?,?)',[$export,$r['id'],$r['row_version']]);}
            $this->audit($actor,'INSURANCE_PREVIEW_EXPORTED',$export);
            return ['id'=>$export,'bytes'=>$bytes];
        });
    }
    /** 保険料・条件の確定まで、運用者が明示していない環境では受付/出力を開始しない。 */
    private function enabled(): void {if(($this->config['insurance_intents_enabled']??false)!==true){throw new HttpFailure(503,'保険加入意思の受付は準備中です');}}
    private function actor(int $actor): void
    {
        if(!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$actor])){throw new HttpFailure(403,'有効な本人アカウントが必要です');}
    }
    /** 旧role_id・財務のみ・globalなクラブ代表を許可しない。所属変更後の旧クラブ閲覧も防ぐ。 */
    private function access(int $actor,int $clubYear): array
    {
        $this->actor($actor);$club=$this->one('SELECT cy.*,COALESCE(cy.name_full,c.name_full) AS name_full FROM portal_club_years cy JOIN portal_clubs c ON c.id=cy.club_id WHERE cy.id=?',[$clubYear]);
        if(!$club){throw new HttpFailure(404,'年度クラブがありません');}
        $grant=$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=r.id
            WHERE g.user_id=? AND p.permission_code='insurance.export' AND g.status='active' AND g.approved_by IS NOT NULL
            AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)
            AND ((r.code='OFFICE' AND g.scope_type='global') OR (r.code='CLUB_REP' AND g.scope_type='club' AND g.scope_club_id=?)) LIMIT 1",[$actor,$club['club_id']]);
        if(!$grant){throw new HttpFailure(403,'対象クラブ代表または事務局の帳票権限が必要です');}return $club;
    }
    /** 会員資格の有効化とは別。申請中の加入意思を保持し、停止/却下/年度終了は確認出力対象から外す。 */
    private function currentClub(int $member): ?int
    {
        $rows=$this->run("SELECT m.member_type,f.club_year_id,e.organizer_club_year_id FROM portal_memberships m
            JOIN portal_applications a ON a.id=m.application_id JOIN portal_years y ON y.year=m.year
            JOIN portal_identifiers i ON i.id=m.general_license_identifier_id AND i.user_id=m.user_id AND i.kind=m.general_license_kind AND i.kind IN ('jaf_competition','jaf_official') AND OCTET_LENGTH(i.value_cipher)>0
            AND (i.expires_on IS NULL OR i.expires_on>=DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','+09:00')))
            JOIN users u ON u.id=m.user_id JOIN portal_account_controls c ON c.user_id=u.id
            LEFT JOIN portal_affiliations f ON f.membership_id=m.id AND f.valid_until IS NULL AND f.status IN ('pending','approved')
            LEFT JOIN portal_events e ON e.id=m.event_id AND e.year=m.year
            WHERE m.id=? AND m.member_type='general' AND m.status<>'suspended' AND a.status IN ('submitted','approved') AND y.source_mode='portal'
            AND UTC_TIMESTAMP(6)<y.valid_until AND u.email_verified=1 AND c.status='active'",[$member])->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1){return null;}$r=$rows[0];$id=$r['member_type']==='general'?$r['club_year_id']:$r['organizer_club_year_id'];return $id===null?null:(int)$id;
    }
    /** 確認票には最新プロフィールだけを利用。年齢/保険区分は未決定なので生成しない。 */
    private function person(array $r): array
    {
        $p=$this->one('SELECT * FROM portal_profiles WHERE user_id=?',[$r['user_id']])?:[];
        $name=trim(($p['last_name']??'').' '.($p['first_name']??''));$kana=trim(($p['last_kana']??'').' '.($p['first_kana']??''));
        $gender=match($p['gender']??''){'male','男性','男'=>'男','female','女性','女'=>'女',default=>''};
        $number='';if($r['member_type']==='general'){
            // クラブ範囲を検証済みの内部参照。既存会員証の検証を経た番号のみを確認票へ載せる。
            $card=(new \Jmrc\Card\CardService($this->db,$this->config))->read((int)$r['user_id'],(int)$r['membership_id']);
            if($card['available']){$number=$card['member_number'];}
        }
        return ['name'=>$name,'kana'=>$kana,'gender'=>$gender,'birthday'=>$p['birthday']??null,'member_number'=>$number];
    }
    private function publicRow(array $r): array {return ['id'=>(int)$r['id'],'membership_id'=>(int)$r['membership_id'],'year'=>(int)$r['year'],'club_year_id'=>(int)$r['club_year_id'],'row_version'=>(int)$r['row_version'],'policy_hold'=>true,'insurance_active'=>false];}
    private function one(string $sql,array $args): array|false {return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
    private function run(string $sql,array $args): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function audit(int $actor,string $action,int $id): void
    {
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,?,'insurance',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,bin2hex(random_bytes(16))]);
    }
    private function tx(callable $fn): array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent insurance transaction required');}
        $this->db->beginTransaction();try{$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
