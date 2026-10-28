<?php
// Path: src/License/LicenseMigration.php
// 機能: 旧entrantsのJAF情報を暗号化計画へ保存し、種別/期限/対象を人が確認した分だけ原子的に移行。
declare(strict_types=1);
namespace Jmrc\License;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class LicenseMigration
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    /** SELECTだけで計画を作る。運転免許のlicense_no/license_expiryをJAF情報へ流用しない。 */
    public function plan(int $actor,array $users=[]): array
    {
        $this->service()->requireMigration($actor);$box=$this->box();$args=[];$where='';
        foreach($users as $id){if(!is_int($id) || $id<1){throw new HttpFailure(422,'移行対象IDを確認してください');}}
        if(count($users)>500){throw new HttpFailure(422,'移行対象は500件以下に分割してください');}
        if($users){$where=' AND user_id IN ('.implode(',',array_fill(0,count($users),'?')).')';$args=$users;}
        $rows=$this->run("SELECT user_id,jaf_license_no,jaf_license_type FROM entrants WHERE jaf_license_no IS NOT NULL AND TRIM(jaf_license_no)<>''".$where.' ORDER BY user_id LIMIT 501',$args)->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>500){throw new HttpFailure(422,'移行対象は500件以下に分割してください');}
        $batch=bin2hex(random_bytes(16));$payload=[];$review=[];
        foreach($rows as $r){$payload[]=['user_id'=>(int)$r['user_id'],'number'=>$r['jaf_license_no'],'license_type'=>$r['jaf_license_type'],'source_hash'=>bin2hex($this->sourceHash($r))];
            $review[]=['user_id'=>(int)$r['user_id'],'decision'=>'pending','kind'=>null,'expiry_reviewed'=>false,'expires_on'=>null,'target_identifier_id'=>null,'target_version'=>null];}
        return ['manifest'=>['format'=>'jmrc-license-migration-v1','batch_key'=>$batch,'payload_cipher'=>$box->seal(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'jmrc:license-migration:v1:'.$batch)],
            'review'=>['batch_key'=>$batch,'items'=>$review],'candidates'=>count($payload)];
    }
    /** checkは書込ゼロ。applyは元データ・版・所有者を再確認し、全件成功時だけcommitする。 */
    public function execute(int $actor,array $manifest,array $review,string $expectedDatabase,bool $apply=false): array
    {
        $this->service()->requireMigration($actor);
        if($expectedDatabase==='' || $this->db->query('SELECT DATABASE()')->fetchColumn()!==$expectedDatabase){throw new HttpFailure(409,'対象DBが一致しません');}
        $batch=$manifest['batch_key']??'';
        if(($manifest['format']??'')!=='jmrc-license-migration-v1' || !is_string($batch) || !preg_match('/^[a-f0-9]{32}$/D',$batch) || ($review['batch_key']??null)!==$batch || !is_string($manifest['payload_cipher']??null)){throw new HttpFailure(422,'移行計画と確認ファイルを照合してください');}
        $rows=json_decode($this->box()->open($manifest['payload_cipher'],'jmrc:license-migration:v1:'.$batch),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($rows) || count($rows)>500 || !is_array($review['items']??null)){throw new HttpFailure(422,'移行件数と確認ファイルを確認してください');}
        $choices=[];foreach($review['items'] as $r){$uid=$r['user_id']??null;if(!is_int($uid) || isset($choices[$uid])){throw new HttpFailure(422,'確認対象IDの重複または形式不正です');}$choices[$uid]=$r;}
        if(count($choices)!==count($rows)){throw new HttpFailure(422,'全候補の確認が必要です');}
        if($this->db->inTransaction()){throw new \LogicException('Independent migration transaction required');}
        if($apply){$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();}
        try{
            $result=['mode'=>$apply?'apply':'check','applied'=>0,'already_applied'=>0,'approved'=>0,'skipped'=>0];$seen=[];
            foreach($rows as $r){
                $uid=$r['user_id'];$choice=$choices[$uid]??null;
                if(!$choice || !in_array($choice['decision']??null,['approve','skip'],true)){throw new HttpFailure(422,'未確認の候補があります');}
                if($choice['decision']==='skip'){$result['skipped']++;continue;}
                if(!in_array($choice['kind']??null,['jaf_competition','jaf_official'],true) || ($choice['expiry_reviewed']??false)!==true || !array_key_exists('expires_on',$choice)){throw new HttpFailure(422,'JAF区分と期限の明示確認が必要です');}
                $live=$this->run('SELECT user_id,jaf_license_no,jaf_license_type FROM entrants WHERE user_id=?'.($apply?' LOCK IN SHARE MODE':''),[$uid])->fetch(PDO::FETCH_ASSOC);
                if(!$live || !hash_equals($r['source_hash'],bin2hex($this->sourceHash($live)))){throw new HttpFailure(409,'計画後に元データが変更されています');}
                $reviewHash=$this->box()->digest(json_encode([$choice['kind'],$choice['expires_on'],$choice['target_identifier_id']??null,$choice['target_version']??null],JSON_THROW_ON_ERROR),'migration-review');
                $receipt=$this->run('SELECT source_hash,review_hash FROM portal_license_migrations WHERE batch_key=? AND source_user_id=?',[$batch,$uid])->fetch(PDO::FETCH_ASSOC);
                if($receipt){if(!hash_equals($receipt['source_hash'],hex2bin($r['source_hash'])) || !hash_equals($receipt['review_hash'],$reviewHash)){throw new HttpFailure(409,'適用済みの移行計画・確認内容が一致しません');}$result['already_applied']++;continue;}
                $target=$choice['target_identifier_id']??null;
                if($target!==null){
                    if(!is_int($target) || $target<1 || !is_int($choice['target_version']??null)){throw new HttpFailure(422,'移行先と更新版を明示してください');}
                    $t=$this->run('SELECT user_id,kind,row_version FROM portal_identifiers WHERE id=?',[$target])->fetch(PDO::FETCH_ASSOC);
                    if(!$t || (int)$t['user_id']!==$uid || $t['kind']!==$choice['kind'] || (int)$t['row_version']!==$choice['target_version']){throw new HttpFailure(409,'移行先の所有者・区分・版を確認してください');}
                }elseif($this->run('SELECT id FROM portal_identifiers WHERE user_id=? AND kind=? LIMIT 1',[$uid,$choice['kind']])->fetchColumn()){throw new HttpFailure(409,'既存識別情報があるため移行先IDを明示してください');}
                // checkでも文字・期限・重複候補を検査し、DB更新はapplyに限定する。
                if(!preg_match('/^[A-Za-z0-9-]{1,64}$/D',trim($r['number']))){throw new HttpFailure(422,'元のJAF番号を確認してください');}
                $expiry=$choice['expires_on'];if($expiry!==null && (!is_string($expiry) || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d',$expiry)) || $date->format('Y-m-d')!==$expiry)){throw new HttpFailure(422,'確認済み期限の形式を確認してください');}
                $hmac=$this->box()->digest(trim($r['number']),'identifier:'.$choice['kind']);
                $claimKey=$choice['kind'].':'.bin2hex($hmac);if(isset($seen[$claimKey])){throw new HttpFailure(409,'計画内の重複候補を照合してください');}$seen[$claimKey]=$uid;
                $dupe=$this->run('SELECT identifier_id FROM portal_license_claims WHERE kind=? AND lookup_hmac=? AND key_version=1 AND identifier_id<>?',[$choice['kind'],$hmac,$target??0])->fetchColumn();
                if($dupe){throw new HttpFailure(409,'重複候補の照合が必要です');}
                $result['approved']++;
                if($apply){
                    $saved=$this->service()->importReviewed($actor,$uid,['id'=>$target??0,'row_version'=>$choice['target_version']??null,'kind'=>$choice['kind'],'number'=>$r['number'],'license_type'=>$r['license_type']??'','expires_on'=>$expiry]);
                    $this->run('INSERT INTO portal_license_migrations(batch_key,source_user_id,source_hash,review_hash,identifier_id,applied_by) VALUES(?,?,?,?,?,?)',[$batch,$uid,hex2bin($r['source_hash']),$reviewHash,$saved['id'],$actor]);$result['applied']++;
                }
            }
            if($apply){$this->db->commit();}return $result;
        }catch(\Throwable $e){if($apply && $this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    private function sourceHash(array $r): string{return $this->box()->digest(json_encode([(int)$r['user_id'],$r['jaf_license_no'],$r['jaf_license_type']],JSON_THROW_ON_ERROR),'legacy-entrant-jaf');}
    private function service(): LicenseService{return new LicenseService($this->db,$this->config);}
    private function box(): SecretBox{return new SecretBox($this->config['data_key']??null);}
    private function run(string $sql,array $args): \PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
}
