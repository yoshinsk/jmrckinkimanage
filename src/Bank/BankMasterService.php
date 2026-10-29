<?php
// Path: src/Bank/BankMasterService.php
// 機能: 事務局専用の用途別口座・決済URL・実口座対応を版管理し、暗号化した変更履歴を一括保存する。
declare(strict_types=1);
namespace Jmrc\Bank;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class BankMasterService
{
    public const PURPOSES=['membership_fee'=>'個人会費','club_fee'=>'正会員クラブ会費','sports_insurance'=>'スポーツ安全保険','jmrc_condolence'=>'協議会見舞金','gymkhana_fund'=>'ジムカーナ部会救済基金','mutual_aid'=>'共済（制度別に確認）'];
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    public function access(int $actor): void
    {
        if(($this->config['bank_master_enabled']??false)!==true){throw new HttpFailure(503,'入金先設定は準備中です');}
        if(!$this->one("SELECT g.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id JOIN portal_user_role_grants g ON g.user_id=u.id
            JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=r.id
            WHERE u.id=? AND u.email_verified=1 AND c.status='active' AND r.code='OFFICE' AND p.permission_code='billing.accounts.manage'
            AND g.scope_type='global' AND g.status='active' AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL
            AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1",[$actor])){throw new HttpFailure(403,'事務局の入金先設定権限が必要です');}
    }
    /** 一覧は口座番号やURLを含めない。編集対象を選んだときだけ保護された詳細を返す。 */
    public function listing(int $actor): array
    {
        $this->access($actor);return ['accounts'=>$this->all('SELECT a.id,a.purpose_code,a.year,a.display_name,a.enabled,a.valid_from,a.valid_until,a.row_version,l.source_id FROM portal_bank_accounts a LEFT JOIN portal_bank_source_accounts l ON l.account_id=a.id ORDER BY a.id',[]),
            'sources'=>$this->all('SELECT id,code,display_name,enabled,row_version FROM portal_bank_sources ORDER BY id',[]),
            'years'=>$this->all('SELECT year FROM portal_years ORDER BY year DESC',[])];
    }
    public function read(int $actor,int $id): array {$this->access($actor);return $this->accountPublic($this->account($id));}
    public function sourceRead(int $actor,int $id): array
    {
        $this->access($actor);$s=$this->source($id);return array_intersect_key($s,array_flip(['id','code','display_name','enabled','row_version','updated_at','updated_by']));
    }
    /** 全設定更新を1つのガードで直列化し、用途/年度/期間の重複判定の取りこぼしを防ぐ。 */
    public function saveAccount(int $actor,array $in): array
    {
        $this->access($actor);
        return $this->tx(function()use($actor,$in):array{
            $this->access($actor);$id=$this->id($in['id']??0,true);$reason=$this->text($in['reason']??null,500);$old=$id?$this->account($id):false;
            if($old && (int)$old['row_version']!==$this->id($in['row_version']??null)){throw new HttpFailure(409,'別の更新があります。再読込してください');}
            $purpose=$in['purpose_code']??null;if(!is_string($purpose) || (!isset(self::PURPOSES[$purpose]) && (!$old || $old['purpose_code']!==$purpose))){throw new HttpFailure(422,'用途を選択してください');}
            $year=($in['year']??'')===''?null:$this->id($in['year']);if($year!==null && !$this->one('SELECT year FROM portal_years WHERE year=?',[$year])){throw new HttpFailure(422,'登録済み年度を選択してください');}
            $name=$this->text($in['display_name']??null,128);$enabled=$this->flag($in['enabled']??'0');$from=$this->time($in['valid_from']??'');$until=$this->time($in['valid_until']??'');
            if($until!==null && ($from===null || $from>=$until)){throw new HttpFailure(422,'終了日時には開始日時より後の日時を指定してください');}
            $url=$this->url($in['payment_url']??'');$source=$this->id($in['source_id']??0,true);$previous=(int)($old['source_id']??0);
            // BankServiceもsource→口座の順に触れる。取込との並行実行で口座を先に占有しない。
            $locks=array_unique(array_filter([$source,$previous]));sort($locks,SORT_NUMERIC);foreach($locks as $sid){$this->source($sid,true);}
            if($old){$old=$this->account($id,true);}
            $details=$in['details']??'';if(!is_string($details)){throw new HttpFailure(422,'口座案内を確認してください');}$details=trim($details);
            $used=$old?$this->used($id):false;
            if($old && ($old['purpose_code']!==$purpose || ($old['year']===null?null:(int)$old['year'])!==$year)){throw new HttpFailure(409,'用途・年度の変更は新しい口座設定として登録してください');}
            if($old && $source!==$previous){
                if($previous && ($used || $this->one('SELECT id FROM portal_bank_imports WHERE bank_source_id=? LIMIT 1',[$previous]))){throw new HttpFailure(409,'使用済みの実口座対応は付け替えできません。新規設定を作成してください');}
            }
            if($source!==$previous && $source && ($in['mapping_verified']??'')!=='1'){throw new HttpFailure(422,'同じ実口座であることの確認が必要です');}
            $cipher=$old['details_cipher']??null;$box=$this->box();
            if($details!==''){
                $details=$this->text($details,4000,true);$same=false;
                if($old && str_starts_with($cipher,'gcm1:')){$same=hash_equals($box->open($cipher,'jmrc:bank-account:v1:'.$id),$details);}
                if($used && !$same){throw new HttpFailure(409,'使用済み口座の案内は変更できません。新しい口座設定を作成してください');}
            }elseif(!$old){throw new HttpFailure(422,'新しい口座案内を入力してください');}
            if($enabled){$this->overlap($id,$purpose,$year,$from,$until);}
            if(!$old){
                $this->run("INSERT INTO portal_bank_accounts(purpose_code,year,display_name,details_cipher,payment_url,valid_from,valid_until,enabled,updated_by) VALUES(?,?,?,'',?,?,?,?,?)",[$purpose,$year,$name,$url,$from,$until,$enabled,$actor]);$id=(int)$this->db->lastInsertId();
            }
            if($details!==''){
                if(!$old || !$same){$cipher=$box->seal($details,'jmrc:bank-account:v1:'.$id);}
            }
            $before=$old?$this->snapshotAccount($old):null;
            $changed=!$old || $old['display_name']!==$name || $old['payment_url']!==$url || $old['valid_from']!==$from || $old['valid_until']!==$until || (int)$old['enabled']!==$enabled || $old['details_cipher']!==$cipher || $previous!==$source;
            if(!$changed){return $this->accountPublic($old)+['unchanged'=>true];}
            $this->run('UPDATE portal_bank_accounts SET display_name=?,details_cipher=?,payment_url=?,valid_from=?,valid_until=?,enabled=?,updated_by=?,row_version=row_version+? WHERE id=?',[$name,$cipher,$url,$from,$until,$enabled,$actor,$old?1:0,$id]);
            if($source!==$previous){if($previous){$this->run('DELETE FROM portal_bank_source_accounts WHERE account_id=?',[$id]);}if($source){$this->run('INSERT INTO portal_bank_source_accounts(account_id,source_id) VALUES(?,?)',[$id,$source]);}}
            $now=$this->account($id);$this->history($actor,'account',$id,(int)$now['row_version'],$before,$this->snapshotAccount($now),$reason);
            return $this->accountPublic($now)+['unchanged'=>false];
        });
    }
    /** 実口座コードと重複識別の鍵チェックは更新しない。停止しても履歴・対応行は保持する。 */
    public function saveSource(int $actor,array $in): array
    {
        $this->access($actor);
        return $this->tx(function()use($actor,$in):array{
            $this->access($actor);$id=$this->id($in['id']??0,true);$old=$id?$this->source($id,true):false;$reason=$this->text($in['reason']??null,500);
            $code=$in['code']??null;if(!is_string($code) || !preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D',$code)){throw new HttpFailure(422,'実口座コードを確認してください');}
            $name=$this->text($in['display_name']??null,128);$enabled=$this->flag($in['enabled']??'0');
            if($old){
                if((int)$old['row_version']!==$this->id($in['row_version']??null)){throw new HttpFailure(409,'取込元に別の更新があります');}
                if($old['code']!==$code){throw new HttpFailure(409,'実口座コードは変更できません');}
                if($old['display_name']===$name && (int)$old['enabled']===$enabled){return $this->sourceRead($actor,$id)+['unchanged'=>true];}
                $this->run('UPDATE portal_bank_sources SET display_name=?,enabled=?,row_version=row_version+1,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$name,$enabled,$actor,$id]);
            }else{
                if(($in['physical_verified']??'')!=='1'){throw new HttpFailure(422,'既存取込元と重複しない実口座であることを確認してください');}
                $this->run('INSERT INTO portal_bank_sources(code,display_name,enabled,updated_by) VALUES(?,?,?,?)',[$code,$name,$enabled,$actor]);$id=(int)$this->db->lastInsertId();
            }
            $now=$this->source($id);$this->history($actor,'source',$id,(int)$now['row_version'],$old?$this->snapshotSource($old):null,$this->snapshotSource($now),$reason);
            return $this->sourceRead($actor,$id)+['unchanged'=>false];
        });
    }
    /** 履歴一覧は対象と変更項目だけ。詳細を選択した場合だけ理由・前後値を復号する。 */
    public function changes(int $actor,string $type,int $id,int $before=0): array
    {
        $this->access($actor);$this->kind($type);
        return $this->all('SELECT id,entity_type,entity_id,row_version,actor_user_id,changed_at,changed_fields FROM portal_bank_master_changes WHERE entity_type=? AND entity_id=? AND (?=0 OR id<?) ORDER BY id DESC LIMIT 50',[$type,$id,$before,$before]);
    }
    public function change(int $actor,int $id): array
    {
        $this->access($actor);$h=$this->one('SELECT * FROM portal_bank_master_changes WHERE id=?',[$id])?:throw new HttpFailure(404,'変更履歴がありません');$box=$this->box();
        $context=$h['entity_type'].':'.$h['entity_id'].':'.$h['row_version'];
        $data=json_decode($box->open($h['snapshot_cipher'],'jmrc:bank-change:v1:'.$context),true,512,JSON_THROW_ON_ERROR);
        foreach(['before','after'] as $side){if(isset($data[$side]['details_cipher_base64'])){$cipher=base64_decode($data[$side]['details_cipher_base64'],true);unset($data[$side]['details_cipher_base64']);$data[$side]['details']=is_string($cipher)&&str_starts_with($cipher,'gcm1:')?$box->open($cipher,'jmrc:bank-account:v1:'.$h['entity_id']):'旧形式（移行確認待ち）';}}
        return ['id'=>(int)$h['id'],'entity_type'=>$h['entity_type'],'entity_id'=>(int)$h['entity_id'],'row_version'=>(int)$h['row_version'],'actor_user_id'=>(int)$h['actor_user_id'],'changed_at'=>$h['changed_at'],
            'reason'=>$box->open($h['reason_cipher'],'jmrc:bank-change-reason:v1:'.$context),'before'=>$data['before'],'after'=>$data['after']];
    }
    private function accountPublic(array $a): array
    {
        $out=array_intersect_key($a,array_flip(['id','purpose_code','year','display_name','payment_url','valid_from','valid_until','enabled','updated_by','row_version','source_id']));
        $out['details']=null;$out['details_notice']=null;
        if(str_starts_with($a['details_cipher'],'gcm1:')){$out['details']=$this->box()->open($a['details_cipher'],'jmrc:bank-account:v1:'.$a['id']);}
        else{$out['details_notice']='旧保管形式のため値を表示しません。移行確認が必要です。';}
        $out['used']=$this->used((int)$a['id']);return $out;
    }
    /** 履歴本文はさらに暗号化する。履歴閲覧で口座案内を復号しなくても前後の設定値を比較できる。 */
    private function snapshotAccount(array $a): array
    {
        $p=$this->accountPublic($a);unset($p['details'],$p['details_notice'],$p['used']);$p['details_cipher_base64']=base64_encode($a['details_cipher']);return $p;
    }
    private function snapshotSource(array $s): array {return array_intersect_key($s,array_flip(['id','code','display_name','enabled','row_version','updated_by','updated_at']));}
    private function history(int $actor,string $type,int $id,int $version,?array $before,array $after,string $reason): void
    {
        $fields=[];foreach($after as $key=>$value){if(!$before || ($before[$key]??null)!==$value){$fields[]=$key;}}
        $box=$this->box();$context=$type.':'.$id.':'.$version;
        $this->run('INSERT INTO portal_bank_master_changes(entity_type,entity_id,row_version,actor_user_id,changed_fields,reason_cipher,snapshot_cipher) VALUES(?,?,?,?,?,?,?)',[$type,$id,$version,$actor,implode(',',$fields),$box->seal($reason,'jmrc:bank-change-reason:v1:'.$context),$box->seal(json_encode(['before'=>$before,'after'=>$after],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'jmrc:bank-change:v1:'.$context)]);
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'BANK_MASTER_UPDATED',?,?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$type==='account'?'bank_account':'bank_source',$id,bin2hex(random_bytes(16))]);
    }
    private function overlap(int $id,string $purpose,?int $year,?string $from,?string $until): void
    {
        $rows=$this->all('SELECT id,year,valid_from,valid_until FROM portal_bank_accounts WHERE id<>? AND purpose_code=? AND enabled=1 AND (? IS NULL OR year IS NULL OR year=?)',[$id,$purpose,$year,$year]);
        foreach($rows as $a){if(($until===null || $a['valid_from']===null || $a['valid_from']<$until) && ($a['valid_until']===null || $from===null || $from<$a['valid_until'])){throw new HttpFailure(409,'用途・年度・有効期間が重なる口座が有効です。先に期間または有効状態を調整してください');}}
    }
    private function used(int $id): bool {foreach(['portal_invoices','portal_payments','portal_bank_imports'] as $table){if($this->one('SELECT id FROM '.$table.' WHERE account_id=? LIMIT 1',[$id])){return true;}}return false;}
    private function account(int $id,bool $lock=false): array {return $this->one('SELECT a.*,l.source_id FROM portal_bank_accounts a LEFT JOIN portal_bank_source_accounts l ON l.account_id=a.id WHERE a.id=?'.($lock?' FOR UPDATE':''),[$id])?:throw new HttpFailure(404,'口座設定がありません');}
    private function source(int $id,bool $lock=false): array {return $this->one('SELECT * FROM portal_bank_sources WHERE id=?'.($lock?' FOR UPDATE':''),[$id])?:throw new HttpFailure(422,'取込元がありません');}
    private function kind(string $type): void {if(!in_array($type,['account','source'],true)){throw new HttpFailure(422,'履歴の対象を確認してください');}}
    private function box(): SecretBox {return new SecretBox($this->config['data_key']??null);}
    private function id(mixed $v,bool $zero=false): int {$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1]]);if($id===false){throw new HttpFailure(422,'ID・版を確認してください');}return $id;}
    private function flag(mixed $v): int {if(!in_array($v,['0','1',0,1],true)){throw new HttpFailure(422,'有効状態を確認してください');}return (int)$v;}
    private function text(mixed $v,int $max,bool $lines=false): string
    {
        if(!is_string($v) || preg_match('//u',$v)!==1 || mb_strlen(trim($v),'UTF-8')<1 || mb_strlen($v,'UTF-8')>$max || preg_match($lines?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$v)){throw new HttpFailure(422,'入力項目の文字・長さを確認してください');}return trim($v);
    }
    private function time(mixed $v): ?string
    {
        if($v===''){return null;}if(!is_string($v)){throw new HttpFailure(422,'日時を確認してください');}
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?$/D',$v)){throw new HttpFailure(422,'日本時間の正しい日時を入力してください');}
        $v=strlen($v)===16?$v.':00.000000':substr($v,0,19).'.'.str_pad(substr($v,20),6,'0');
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u',$v,new \DateTimeZone('Asia/Tokyo'));
        if(!$d || $d->format('Y-m-d\TH:i:s.u')!==$v){throw new HttpFailure(422,'日本時間の正しい日時を入力してください');}return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    private function url(mixed $v): ?string
    {
        if($v===''){return null;}if(!is_string($v) || strlen($v)>2048 || preg_match('/[\s\x00-\x1F\x7F\\\\]/',$v) || filter_var($v,FILTER_VALIDATE_URL)===false){throw new HttpFailure(422,'決済URLを確認してください');}
        $p=parse_url($v);if(!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])){throw new HttpFailure(422,'決済URLは認証情報を含まないHTTPSで指定してください');}return $v;
    }
    private function run(string $sql,array $args): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function one(string $sql,array $args): array|false {return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
    private function all(string $sql,array $args): array {return $this->run($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private function tx(callable $fn): array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent bank master transaction required');}
        for($i=0;$i<3;$i++){$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try{$this->one('SELECT id FROM portal_bank_master_lock WHERE id=1 FOR UPDATE',[] )?:throw new HttpFailure(503,'設定変更の排他制御を確認してください');$r=$fn();$this->db->commit();return $r;}
            catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}if($e instanceof PDOException){$n=(int)($e->errorInfo[1]??0);if(in_array($n,[1205,1213],true)&&$i<2){usleep(20000*($i+1));continue;}if($n===1062){throw new HttpFailure(409,'実口座コードまたは版が重複しています');}}throw $e;}
        }throw new HttpFailure(503,'設定変更を再試行してください');
    }
}
