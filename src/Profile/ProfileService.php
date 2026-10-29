<?php
// Path: src/Profile/ProfileService.php
// 機能: 事務局の氏名・生年月日訂正、暗号化履歴、未更新者の削除候補を扱う。削除は実行しない。
declare(strict_types=1);
namespace Jmrc\Profile;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class ProfileService
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}

    /** 旧管理者・財務専用・クラブ代表では訂正できない。毎回現在の承認済みgrantを確認する。 */
    public function access(int $actor): void
    {
        if(($this->config['profile_corrections_enabled']??false)!==true){throw new HttpFailure(503,'会員情報の訂正は準備中です');}
        if(!$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id
            JOIN portal_role_permissions p ON p.role_id=r.id JOIN users u ON u.id=g.user_id
            JOIN portal_account_controls c ON c.user_id=u.id WHERE g.user_id=? AND r.code='OFFICE'
            AND p.permission_code='profiles.correct' AND g.scope_type='global' AND g.status='active'
            AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
            AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) AND u.email_verified=1 AND c.status='active'",[$actor])){
            throw new HttpFailure(403,'事務局の会員情報訂正権限が必要です');
        }
    }
    public function read(int $actor,int $user): array
    {
        $this->access($actor);
        return $this->one('SELECT user_id,last_name,first_name,birthday,row_version FROM portal_profiles WHERE user_id=?',[$user])?:throw new HttpFailure(404,'登録済みプロフィールがありません');
    }
    /** 訂正理由と変更前後を暗号化し、プロフィール・監査と同時に保存。元entryは更新しない。 */
    public function correct(int $actor,int $user,array $input): array
    {
        $this->access($actor);
        foreach(array_keys($input) as $key){if(!in_array($key,['last_name','first_name','birthday','row_version','reason'],true)){throw new HttpFailure(422,'未対応の入力項目です');}}
        foreach(['last_name','first_name','birthday','reason'] as $key){if(!is_string($input[$key]??null)){throw new HttpFailure(422,'入力形式を確認してください');}}
        $next=[];foreach(['last_name','first_name'] as $key){$v=trim($input[$key]);if($v==='' || mb_strlen($v)>64 || preg_match('/[\x00-\x1f\x7f]/u',$v)){throw new HttpFailure(422,'氏名は1～64文字で入力してください');}$next[$key]=$v;}
        $next['birthday']=null;
        if($input['birthday']!==''){
            if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$input['birthday'])){throw new HttpFailure(422,'生年月日を確認してください');}
            $birth=\DateTimeImmutable::createFromFormat('!Y-m-d',$input['birthday'],new \DateTimeZone('Asia/Tokyo'));
            if(!$birth || $birth->format('Y-m-d')!==$input['birthday'] || $input['birthday']<'1000-01-01' || $birth>new \DateTimeImmutable('today',new \DateTimeZone('Asia/Tokyo'))){throw new HttpFailure(422,'生年月日を確認してください');}
            $next['birthday']=$input['birthday'];
        }
        $reason=trim($input['reason']);
        if($reason==='' || mb_strlen($reason)>500){throw new HttpFailure(422,'訂正理由は1～500文字で入力してください');}
        $version=filter_var($input['row_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($version===false){throw new HttpFailure(422,'更新版を確認してください');}
        if($this->db->inTransaction()){throw new \LogicException('Independent profile transaction required');}
        $this->db->beginTransaction();try{
            $old=$this->one('SELECT user_id,last_name,first_name,birthday,row_version FROM portal_profiles WHERE user_id=? FOR UPDATE',[$user]);
            if(!$old){throw new HttpFailure(404,'登録済みプロフィールがありません');}
            $this->access($actor);
            if((int)$old['row_version']!==$version){throw new HttpFailure(409,'他の更新があります。再読込してください');}
            if($next['birthday']===null && $old['birthday']!==null){throw new HttpFailure(422,'登録済みの生年月日を空欄にはできません');}
            $before=array_intersect_key($old,$next);
            if($before!==$next){
                $box=new SecretBox($this->config['data_key']??null);
                $cipher=$box->seal(json_encode(['before'=>$before,'after'=>$next,'reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'jmrc:profile-change:v1:'.$user.':'.$version);
                $this->run('INSERT INTO portal_profile_changes(user_id,previous_version,actor_user_id,snapshot_cipher) VALUES(?,?,?,?)',[$user,$version,$actor,$cipher]);
                $this->run('UPDATE portal_profiles SET last_name=?,first_name=?,birthday=?,row_version=row_version+1 WHERE user_id=?',[$next['last_name'],$next['first_name'],$next['birthday'],$user]);
                $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'PROFILE_CORRECTED','profile',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$user,bin2hex(random_bytes(16))]);
            }
            $result=$this->read($actor,$user);$this->db->commit();return $result;
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    /** 履歴も同じ事務局権限で保護。個人情報を監査表や一覧へ複製しない。 */
    public function changes(int $actor,int $user): array
    {
        $this->access($actor);$rows=$this->run('SELECT * FROM portal_profile_changes WHERE user_id=? ORDER BY id DESC LIMIT 50',[$user])->fetchAll(PDO::FETCH_ASSOC);
        $box=new SecretBox($this->config['data_key']??null);$out=[];
        foreach($rows as $r){$out[]=['id'=>(int)$r['id'],'actor_user_id'=>(int)$r['actor_user_id'],'created_at'=>$r['created_at']]+json_decode($box->open($r['snapshot_cipher'],'jmrc:profile-change:v1:'.$user.':'.$r['previous_version']),true,512,JSON_THROW_ON_ERROR);}
        return $out;
    }
    /** 4月30日以降の候補だけを抽出。再申請中も保護し、共通ID・会計・entryには変更しない。 */
    public function retentionCandidates(int $actor,int $lastYear): array
    {
        $this->access($actor);if($lastYear<2000 || $lastYear>9998){throw new HttpFailure(422,'対象年度を確認してください');}
        $cutoff=sprintf('%04d-04-30 00:00:00',$lastYear+1);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
        if($today<$cutoff){return ['due'=>false,'candidates'=>[],'execution_enabled'=>false];}
        $rows=$this->run("SELECT DISTINCT m.user_id FROM portal_memberships m JOIN portal_profiles p ON p.user_id=m.user_id
            JOIN portal_applications a ON a.id=m.application_id WHERE m.year=? AND a.status IN ('submitted','approved')
            AND NOT EXISTS(SELECT 1 FROM portal_memberships n JOIN portal_applications na ON na.id=n.application_id
                WHERE n.user_id=m.user_id AND n.year>m.year AND na.status IN ('draft','submitted','approved','returned'))
            ORDER BY m.user_id LIMIT 200",[$lastYear])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['identifier_count']=(int)$this->run('SELECT COUNT(*) FROM portal_identifiers WHERE user_id=?',[$r['user_id']])->fetchColumn();$r['profile_history_count']=(int)$this->run('SELECT COUNT(*) FROM portal_profile_changes WHERE user_id=?',[$r['user_id']])->fetchColumn();}
        return ['due'=>true,'candidates'=>$rows,'limit'=>200,'execution_enabled'=>false];
    }
    private function one(string $sql,array $args): array|false{return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
    private function run(string $sql,array $args): \PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
}
