<?php
// Path: src/Card/CardService.php
// 機能: General本人の会員証・不透明QRを発行し、公開照会には最新資格の真偽だけを返す。
declare(strict_types=1);
namespace Jmrc\Card;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
use Jmrc\Billing\Eligibility;
final class CardService
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}

    /** 発行は本人の明示POSTからのみ。同じ採番元の再送は番号・QRを増やさない。 */
    public function issue(int $actor,int $member): array
    {
        return $this->tx(function () use ($actor,$member): array {
            $m=$this->owned($actor,$member,true);$state=(new Eligibility($this->db))->evaluate($member);
            if (!$state['active']) { throw new HttpFailure(409,'現在有効なGeneral資格が必要です'); }
            $basis=$this->basis($member);$box=$this->box();$fingerprint=$this->fingerprint($basis,$box);
            $n=$this->number($member);
            if ($n && !$this->matches($n,$basis,$fingerprint)) { throw new HttpFailure(409,'採番元の変更があります。事務局へ確認してください'); }
            if (!$n) {
                if ($this->hasHistory($member)) { throw new HttpFailure(409,'会員番号の再発行は事務局へ確認してください'); }
                if ((int)$basis['key_version']!==1) { throw new HttpFailure(503,'ライセンス情報の鍵形式を確認してください'); }
                $license=$box->open($basis['value_cipher'],'jmrc:identifier:v1:'.$basis['source_identifier_id']);
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D',$license)) { throw new HttpFailure(422,'ライセンス番号を事務局で確認してください'); }
                $number=$license.'-G-'.$basis['display_code'];
                $this->run('INSERT INTO portal_member_numbers(membership_id,user_id,year,source_kind,source_identifier_id,club_year_id,number_cipher,number_hmac,key_version,valid_from,source_fingerprint) VALUES(?,?,?,?,?,?,?,?,1,UTC_TIMESTAMP(6),?)',[
                    $member,$actor,$m['year'],$basis['kind'],$basis['source_identifier_id'],$basis['club_year_id'],
                    $box->seal($number,'jmrc:member-number:v1:'.$member),$box->digest($number,'member-number'),$fingerprint]);
                $this->audit($actor,'GENERAL_NUMBER_ISSUED',$member);$n=$this->number($member);
            }
            $token=$this->currentToken($member);
            if ($token && ((int)$token['member_number_id']!==(int)$n['id'] || $token['expires_at']<=$this->now())) {
                $this->run('UPDATE portal_card_tokens SET revoked_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?',[$token['id']]);$token=false;
            }
            if (!$token) {
                $plain=bin2hex(random_bytes(32));
                $this->run('INSERT INTO portal_card_tokens(membership_id,token_hash,expires_at,issued_by,member_number_id,token_cipher) VALUES(?,?,?,?,?,?)',[
                    $member,hash('sha256',$plain,true),$state['valid_until'],$actor,$n['id'],$box->seal($plain,'jmrc:card-token:v1:'.$member)]);
                $this->audit($actor,'GENERAL_QR_ISSUED',$member);
            }
            return $this->read($actor,$member);
        });
    }

    /** 本人だけに確定済み会員証項目を返す。未払い・停止・期限切れなら番号やQRも返さない。 */
    public function read(int $actor,int $member): array
    {
        $m=$this->owned($actor,$member);$state=(new Eligibility($this->db))->evaluate($member);
        if (!$state['active']) { return ['available'=>false,'can_issue'=>false]; }
        $n=$this->number($member);
        if (!$n) { return ['available'=>false,'can_issue'=>!$this->hasHistory($member)]; }
        $basis=$this->basis($member);$box=$this->box();
        if (!$this->matches($n,$basis,$this->fingerprint($basis,$box))) { return ['available'=>false,'can_issue'=>false,'notice'=>'採番元の変更を事務局で確認してください']; }
        $t=$this->currentToken($member);
        if (!$t || (int)$t['member_number_id']!==(int)$n['id'] || $t['expires_at']<=$this->now()) { return ['available'=>false,'can_issue'=>true]; }
        $plain=$box->open($t['token_cipher']??'','jmrc:card-token:v1:'.$member);
        if (!hash_equals($t['token_hash'],hash('sha256',$plain,true))) { throw new HttpFailure(503,'QR保管情報を確認してください'); }
        // 氏名は本人プロフィールから毎回取得。採番元・匿名照会には含めない。
        $profile=$this->run('SELECT last_name,first_name FROM portal_profiles WHERE user_id=?',[$actor])->fetch(PDO::FETCH_ASSOC);
        $name=trim(implode(' ',array_filter([$profile['last_name']??'',$profile['first_name']??''],static fn($v)=>$v!=='')));
        return ['available'=>true,'can_issue'=>true,'year'=>(int)$m['year'],'name'=>$name,
            'member_number'=>$box->open($n['number_cipher'],'jmrc:member-number:v1:'.$member),
            'club_abbreviation'=>$basis['club_abbreviation'],
            'license_type'=>$basis['license_type']?:($basis['kind']==='jaf_competition'?'JAF競技':'JAF審判員'),
            'qr_url'=>$this->publicUrl().'#'.$plain];
    }

    /** QRの紛失等に備え本人が失効可能。会員資格や会員番号の取消/再発行にはしない。 */
    public function revoke(int $actor,int $member): array
    {
        return $this->tx(function () use ($actor,$member): array {
            $this->owned($actor,$member,true);
            $s=$this->run('UPDATE portal_card_tokens SET revoked_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE membership_id=? AND revoked_at IS NULL',[$member]);
            if ($s->rowCount()>0) { $this->audit($actor,'GENERAL_QR_REVOKED',$member); }
            return $this->read($actor,$member);
        });
    }

    /** 匿名照会は真偽のみ。ライセンス/番号/氏名/年度/所属/無効理由を外部へ返さない。 */
    public function verify(mixed $token): bool
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) { return false; }
        $s=$this->run("SELECT t.*,n.source_fingerprint,n.source_identifier_id,n.club_year_id,n.source_kind,n.valid_until AS number_until
            FROM portal_card_tokens t JOIN portal_member_numbers n ON n.id=t.member_number_id AND n.membership_id=t.membership_id
            JOIN portal_memberships m ON m.id=t.membership_id AND m.member_type='general'
            WHERE t.token_hash=? AND t.revoked_at IS NULL AND UTC_TIMESTAMP(6)<t.expires_at AND n.valid_until IS NULL",[hash('sha256',$token,true)]);
        $t=$s->fetch(PDO::FETCH_ASSOC);if (!$t) { return false; }
        $member=(int)$t['membership_id'];
        if (!(new Eligibility($this->db))->evaluate($member)['active']) { return false; }
        $basis=$this->basis($member);return $this->matches($t,$basis,$this->fingerprint($basis,$this->box()));
    }

    private function owned(int $actor,int $member,bool $lock=false): array
    {
        $s=$this->run('SELECT * FROM portal_memberships WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),[$member,$actor]);$m=$s->fetch(PDO::FETCH_ASSOC);
        if (!$m) { throw new HttpFailure(404,'対象の会員資格がありません'); }
        if ($m['member_type']!=='general') { throw new HttpFailure(422,'会員証はGeneralのみです'); }return $m;
    }
    /** 採番元は申請時に選択済みの本人ライセンスと、現在の承認済み所属。別番号を自動選択しない。 */
    private function basis(int $member): array
    {
        $s=$this->run("SELECT m.id,m.user_id,m.year,i.id AS source_identifier_id,i.kind,i.key_version,i.value_cipher,i.license_type,
            cy.id AS club_year_id,cy.display_code,COALESCE(cy.name_short,c.name_short) AS club_abbreviation
            FROM portal_memberships m JOIN portal_identifiers i ON i.id=m.general_license_identifier_id AND i.user_id=m.user_id AND i.kind=m.general_license_kind
            JOIN portal_affiliations f ON f.membership_id=m.id AND f.status='approved' AND f.valid_until IS NULL
            JOIN portal_club_years cy ON cy.id=f.club_year_id AND cy.year=m.year JOIN portal_clubs c ON c.id=cy.club_id WHERE m.id=?",[$member]);
        return $s->fetch(PDO::FETCH_ASSOC)?:throw new HttpFailure(409,'会員証の登録元を確認してください');
    }
    private function fingerprint(array $b,SecretBox $box): string
    {
        return $box->digest(json_encode([$b['id'],$b['user_id'],$b['year'],$b['source_identifier_id'],$b['kind'],$b['key_version'],base64_encode($b['value_cipher']),$b['license_type'],$b['club_year_id'],$b['display_code']],JSON_THROW_ON_ERROR),'card-source');
    }
    private function matches(array $n,array $b,string $fingerprint): bool
    {
        return is_string($n['source_fingerprint']??null) && hash_equals($n['source_fingerprint'],$fingerprint)
            && (int)$n['source_identifier_id']===(int)$b['source_identifier_id'] && (int)$n['club_year_id']===(int)$b['club_year_id'] && $n['source_kind']===$b['kind'];
    }
    private function number(int $member): array|false { return $this->run('SELECT * FROM portal_member_numbers WHERE membership_id=? AND valid_until IS NULL',[$member])->fetch(PDO::FETCH_ASSOC); }
    private function hasHistory(int $member): bool { return (bool)$this->run('SELECT id FROM portal_member_numbers WHERE membership_id=? LIMIT 1',[$member])->fetchColumn(); }
    private function currentToken(int $member): array|false { return $this->run('SELECT * FROM portal_card_tokens WHERE membership_id=? AND revoked_at IS NULL',[$member])->fetch(PDO::FETCH_ASSOC); }
    private function box(): SecretBox { return new SecretBox($this->config['data_key']??null); }
    private function now(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private function publicUrl(): string
    {
        $url=$this->config['card_public_url']??'';$p=is_string($url)?parse_url($url):false;
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || ($p['path']??'')!=='/manage/card-check.php'
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) { throw new HttpFailure(503,'QR照会URLの設定を確認してください'); }return $url;
    }
    private function run(string $sql,array $args): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function audit(int $actor,string $action,int $member): void
    {
        $request=bin2hex(random_bytes(16));
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES (?,?,'membership',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$member,$request]);
        $this->run("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$request,$action,$member]);
    }
    private function tx(callable $fn): array
    {
        if($this->db->inTransaction()) { throw new \LogicException('Independent card transaction required'); }
        for($i=0;$i<3;$i++) {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try {$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e) {
                if($this->db->inTransaction()) {$this->db->rollBack();}
                if($e instanceof PDOException) {
                    $n=(int)($e->errorInfo[1]??0);if(in_array($n,[1205,1213],true) && $i<2) {usleep(20000*($i+1));continue;}
                    if($n===1062) {throw new HttpFailure(409,'会員証の同時処理を再確認してください');}
                }throw $e;
            }
        }throw new HttpFailure(503,'会員証処理を再試行してください');
    }
}
