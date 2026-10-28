<?php
// Path: src/License/LicenseService.php
// 機能: 本人ライセンスの登録/版管理更新、暗号保管・重複防止・会員証失効と資格再評価を一括実行。
declare(strict_types=1);
namespace Jmrc\License;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class LicenseService
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    /** 公開入口では本人IDを固定する。移行用の他人更新・未知形式上書きへは到達させない。 */
    public function save(int $owner,array $input): array
    {
        if (($this->config['license_self_service_enabled']??false)!==true) {throw new HttpFailure(503,'ライセンス受付は準備中です');}
        return $this->tx(fn()=>$this->write($owner,$owner,$input,false));
    }
    /** 確認済みの移行だけを既存トランザクション内で実行。通常フォームからは呼ばない。 */
    public function importReviewed(int $actor,int $owner,array $input): array
    {
        if(!$this->db->inTransaction()){throw new \LogicException('Migration transaction required');}
        $this->requireMigration($actor);return $this->write($actor,$owner,$input,true);
    }
    public function requireMigration(int $actor): void
    {
        $s=$this->run("SELECT g.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id
         JOIN portal_user_role_grants g ON g.user_id=u.id JOIN portal_roles r ON r.id=g.role_id
         JOIN portal_role_permissions p ON p.role_id=g.role_id AND p.permission_code='identifiers.migrate'
         WHERE u.id=? AND u.email_verified=1 AND c.status='active' AND r.code='OFFICE' AND g.scope_type='global'
         AND g.status='active' AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
         AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1",[$actor]);
        if(!$s->fetchColumn()){throw new HttpFailure(403,'事務局の明示された移行権限が必要です');}
    }
    /** 一覧には番号を含めない。本人が編集対象を選んだ場合だけ復号する。 */
    public function listing(int $owner): array
    {
        $this->actor($owner);
        return $this->run("SELECT id,kind,license_type,expires_on,row_version FROM portal_identifiers WHERE user_id=? AND kind IN ('jaf_competition','jaf_official') ORDER BY id",[$owner])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function read(int $owner,int $id): array
    {
        $this->actor($owner);$r=$this->row($owner,$id);
        $public=$this->publicRow($r);
        try{$public['number']=$this->box()->open($r['value_cipher'],'jmrc:identifier:v1:'.$id);}
        catch(HttpFailure $e){if($e->status!==503){throw $e;}$public['number']=null;$public['notice']='既存情報の保管形式を事務局で確認してください';}
        return $public;
    }
    /** 同じ番号の期限更新では暗号文を維持。番号/種類の変更では旧QRを失効し、番号再発行は保留。 */
    private function write(int $actor,int $owner,array $in,bool $migration): array
    {
        $this->actor($owner);$id=$this->positive($in['id']??0,true);$old=$id?$this->row($owner,$id,true):false;
        if($old && (int)$old['row_version']!==$this->positive($in['row_version']??null)){throw new HttpFailure(409,'別の更新があります。再読込してください');}
        $kind=$in['kind']??null;
        if(!in_array($kind,['jaf_competition','jaf_official'],true) || ($old && $old['kind']!==$kind)){throw new HttpFailure(422,'競技/審判員の区分は登録後に変更できません');}
        $box=$this->box();$plain=null;
        if($old){try{$plain=$box->open($old['value_cipher'],'jmrc:identifier:v1:'.$id);}catch(HttpFailure $e){if(!$migration){throw new HttpFailure(409,'既存形式の移行確認が必要です');}}}
        $number=$in['number']??'';
        if(!is_string($number)){throw new HttpFailure(422,'番号を確認してください');}
        $number=trim($number);if($number==='' && $plain!==null){$number=$plain;}
        if(!preg_match('/^[A-Za-z0-9-]{1,64}$/D',$number)){throw new HttpFailure(422,'JAFライセンス番号を入力してください');}
        $type=$in['license_type']??($old['license_type']??'');
        if(!is_string($type) || preg_match_all('/./us',$type)===false || preg_match_all('/./us',$type)>40 || preg_match('/[\x00-\x1F]/',$type)){throw new HttpFailure(422,'ライセンス種類を確認してください');}
        $type=trim($type);$type=$type===''?null:$type;$expiry=$this->expiry(array_key_exists('expires_on',$in)?$in['expires_on']:($old['expires_on']??null));
        $hmac=$box->digest($number,'identifier:'.$kind);
        $duplicate=$this->run('SELECT id,user_id FROM portal_identifiers WHERE kind=? AND key_version=1 AND lookup_hmac=? AND id<>? LIMIT 1',[$kind,$hmac,$id])->fetch(PDO::FETCH_ASSOC);
        if($duplicate){
            if(!$old && (int)$duplicate['user_id']===$owner){$r=$this->row($owner,(int)$duplicate['id']);if($r['license_type']===$type && $r['expires_on']===$expiry){return $this->publicRow($r);}}
            throw new HttpFailure(409,'同じライセンスの登録があります。事務局へ確認してください');
        }
        if($old && $plain===$number && $old['license_type']===$type && $old['expires_on']===$expiry && (int)$old['key_version']===1 && hash_equals($old['lookup_hmac'],$hmac)) {
            // 既に暗号化済みでも重複確保が未移行なら、claim作成まで省略しない。
            if($this->run('SELECT identifier_id FROM portal_license_claims WHERE identifier_id=? AND kind=? AND lookup_hmac=? AND key_version=1 FOR UPDATE',[$id,$kind,$hmac])->fetchColumn()){return $this->publicRow($old);}
        }
        $basisChanged=$old && ($plain!==$number || $old['license_type']!==$type || (int)$old['key_version']!==1);
        if($old){
            $snapshot=json_encode(['kind'=>$old['kind'],'value_cipher'=>base64_encode($old['value_cipher']),'lookup_hmac'=>bin2hex($old['lookup_hmac']),'key_version'=>$old['key_version'],'license_type'=>$old['license_type'],'expires_on'=>$old['expires_on']],JSON_THROW_ON_ERROR);
            $this->run('INSERT INTO portal_identifier_revisions(identifier_id,previous_version,actor_user_id,snapshot_cipher) VALUES(?,?,?,?)',[$id,$old['row_version'],$actor,$box->seal($snapshot,'jmrc:identifier-history:v1:'.$id.':'.$old['row_version'])]);
            $cipher=$plain===$number && (int)$old['key_version']===1?$old['value_cipher']:$box->seal($number,'jmrc:identifier:v1:'.$id);
            $this->run('UPDATE portal_identifiers SET value_cipher=?,lookup_hmac=?,key_version=1,license_type=?,expires_on=?,verified_at=IF(?=1,NULL,verified_at),row_version=row_version+1 WHERE id=?',[$cipher,$hmac,$type,$expiry,(int)$basisChanged,$id]);
        }else{
            $this->run("INSERT INTO portal_identifiers(user_id,kind,value_cipher,lookup_hmac,key_version,license_type,expires_on) VALUES(?,?,'pending',?,1,?,?)",[$owner,$kind,$hmac,$type,$expiry]);$id=(int)$this->db->lastInsertId();
            $this->run('UPDATE portal_identifiers SET value_cipher=? WHERE id=?',[$box->seal($number,'jmrc:identifier:v1:'.$id),$id]);
        }
        $claim=$this->run('SELECT identifier_id FROM portal_license_claims WHERE identifier_id=? FOR UPDATE',[$id])->fetchColumn();
        if($claim){$this->run('UPDATE portal_license_claims SET kind=?,lookup_hmac=?,key_version=1 WHERE identifier_id=?',[$kind,$hmac,$id]);}
        else{$this->run('INSERT INTO portal_license_claims(kind,lookup_hmac,key_version,identifier_id,user_id) VALUES(?,?,1,?,?)',[$kind,$hmac,$id,$owner]);}
        $members=$this->run('SELECT id FROM portal_memberships WHERE general_license_identifier_id=? ORDER BY id FOR UPDATE',[$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach($members as $member){
            if($basisChanged){$this->run('UPDATE portal_card_tokens SET revoked_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE membership_id=? AND revoked_at IS NULL',[$member]);}
            (new \Jmrc\Billing\Eligibility($this->db))->refresh((int)$member);
        }
        $request=bin2hex(random_bytes(16));$action=$migration?'LICENSE_MIGRATED':($old?'LICENSE_UPDATED':'LICENSE_REGISTERED');
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES (?,?,'identifier',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,$request]);
        $this->run("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$request,$action,$id]);
        return $this->publicRow($this->row($owner,$id));
    }
    private function expiry(mixed $v): ?string
    {
        if($v===null || $v===''){return null;}
        if(!is_string($v)){throw new HttpFailure(422,'有効期限を確認してください');}
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if(!$d || $d->format('Y-m-d')!==$v){throw new HttpFailure(422,'有効期限を確認してください');}return $v;
    }
    private function positive(mixed $v,bool $zero=false): int
    {
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1]]);if($n===false || $n===null){throw new HttpFailure(422,'対象と更新版を確認してください');}return $n;
    }
    private function actor(int $id): void
    {
        if(!$this->run("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$id])->fetchColumn()){throw new HttpFailure(401,'ログイン状態を確認してください');}
    }
    private function row(int $owner,int $id,bool $lock=false): array
    {
        return $this->run("SELECT * FROM portal_identifiers WHERE id=? AND user_id=? AND kind IN ('jaf_competition','jaf_official')".($lock?' FOR UPDATE':''),[$id,$owner])->fetch(PDO::FETCH_ASSOC)?:throw new HttpFailure(404,'本人のライセンスがありません');
    }
    private function publicRow(array $r): array {return array_intersect_key($r,array_flip(['id','kind','license_type','expires_on','row_version']));}
    private function box(): SecretBox {return new SecretBox($this->config['data_key']??null);}
    private function run(string $sql,array $args): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function tx(callable $fn): array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent license transaction required');}
        for($i=0;$i<3;$i++){$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try{$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}
                if($e instanceof PDOException){$n=(int)($e->errorInfo[1]??0);if(in_array($n,[1205,1213],true) && $i<2){usleep(20000*($i+1));continue;}if($n===1062){throw new HttpFailure(409,'ライセンスの重複または同時更新があります');}}
                throw $e;}}
        throw new HttpFailure(503,'更新を再試行してください');
    }
}
