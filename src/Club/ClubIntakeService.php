<?php
// Path: src/Club/ClubIntakeService.php
// 機能: 事前照合なしのクラブ申請・訂正、更新申請の事務局照合、年度登録との接続を暗号化・排他制御付きで提供する。
declare(strict_types=1);
namespace Jmrc\Club;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
require_once __DIR__.'/ClubRoster.php';
final class ClubIntakeService
{
    public const LIST_STATES=['all'=>'すべて','submitted'=>'新規・再加入：事務局の確認待ち','renewal_review'=>'更新：事務局の確認待ち','returned'=>'申請者の訂正待ち','linked'=>'照合済み（会費・年度承認は別途）'];
    public function __construct(private readonly PDO $db,private readonly array $config){}

    /** 既存クラブ代表権限やクラブ台帳は要求しない。共通認証の有効な本人だけが申請する。 */
    public function submit(int $actor,int $year,array $input,string $key):array
    {
        return $this->tx(function()use($actor,$year,$input,$key){
            $this->actor($actor);$this->key($key);$data=ClubRegistrationData::normalize($input);$box=$this->box();$digest=$this->digest($year,$data);
            $old=$this->one('SELECT i.* FROM portal_club_intakes i JOIN portal_applications a ON a.id=i.application_id WHERE a.request_key=? FOR UPDATE',[$key]);
            if($old){if((int)$old['applicant_user_id']!==$actor||!hash_equals($old['payload_hmac'],$digest)){throw new HttpFailure(409,'処理キーが別の申請で使用済みです');}return $this->read($actor,(int)$old['id'])+['replayed'=>true];}
            ClubIntakePolicy::requireOpen($this->db,$year,$this->config);
            $this->candidate($data);
            $q=ClubRegistrationData::quote($this->db,$year,$data['jaf_kind'],$data['membership_kind'],$data['registration_kind']);
            $this->run("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key,submitted_at) VALUES('club',?,?,'submitted',?,UTC_TIMESTAMP(6))",[$actor,$year,$key]);$app=(int)$this->db->lastInsertId();
            $this->run("INSERT INTO portal_club_intakes(application_id,applicant_user_id,year,registration_kind,jaf_kind,membership_kind,annual_yen,joining_yen,category_digit,payload_cipher,payload_hmac,review_state) VALUES(?,?,?,?,?,?,?,?,?,'pending',?,?)",[$app,$actor,$year,$data['registration_kind'],$data['jaf_kind'],$data['membership_kind'],$q['annual_yen'],$q['joining_yen'],$q['category_digit'],$digest,$data['registration_kind']==='renewal'?'renewal_review':'submitted']);$id=(int)$this->db->lastInsertId();
            $this->run('UPDATE portal_club_intakes SET payload_cipher=? WHERE id=?',[$box->seal($this->json($data),'jmrc:club-intake:v1:'.$id),$id]);
            $this->audit($actor,'CLUB_INTAKE_SUBMITTED',$id);return $this->read($actor,$id)+['replayed'=>false];
        });
    }

    /** 本人・事務局が対象申請を読む。入力したクラブ名やIDだけで他クラブへの権限を付与しない。 */
    public function read(int $actor,int $id):array
    {
        $this->actor($actor);$r=$this->one('SELECT * FROM portal_club_intakes WHERE id=?',[$id])?:throw new HttpFailure(404,'クラブ申請がありません');
        if((int)$r['applicant_user_id']!==$actor){$this->office($actor);}
        $data=json_decode($this->box()->open($r['payload_cipher'],'jmrc:club-intake:v1:'.$id),true,512,JSON_THROW_ON_ERROR);
        unset($r['payload_cipher'],$r['payload_hmac']);$r['data']=$data;$r['total_yen']=(int)$r['annual_yen']+(int)$r['joining_yen'];return $r;
    }
    /** 年度・状況を先に絞り、ID境界で古い申請も取得する。閲覧だけで照合・権限・請求を変更しない。 */
    public function listing(int $actor,bool $office=false,int $year=0,string $state='all',int $before=0):array
    {
        $this->actor($actor);if($office){$this->office($actor);}
        if($year<0||$year>9999||$before<0||!isset(self::LIST_STATES[$state])){throw new HttpFailure(422,'一覧の年度・確認状況を選び直してください');}
        $where=[];$args=[];
        if(!$office){$where[]='applicant_user_id=?';$args[]=$actor;}
        if($year>0){$where[]='year=?';$args[]=$year;}
        if($state!=='all'){$where[]='review_state=?';$args[]=$state;}
        if($before>0){$where[]='id<?';$args[]=$before;}
        $s=$this->run('SELECT id FROM portal_club_intakes'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 100',$args);
        return array_map(fn($id)=>$this->read($actor,(int)$id),$s->fetchAll(PDO::FETCH_COLUMN));
    }
    public function isOffice(int $actor):bool
    {
        try{$this->office($actor);return true;}catch(HttpFailure $e){if($e->status!==403){throw $e;}return false;}
    }
    /** 候補は名称・正式略称・コードだけ。原本の名前も整形した値で比較し、メールや口座は返さない。 */
    public function candidates(int $actor,string $query=''):array
    {
        $this->actor($actor);$query=mb_strtolower(trim(\Jmrc\Input\TextInput::normalize($query)),'UTF-8');
        if(mb_strlen($query)>128){throw new HttpFailure(422,'クラブの検索文字は128文字以内です');}
        $rows=$this->run("SELECT CONCAT('portal:',p.id) AS ref,p.name_full AS name,p.name_short AS short_name,p.fixed_code AS code,COALESCE(l.club_code,'') AS reference_code FROM portal_clubs p LEFT JOIN jmrc_affiliated_clubs l ON l.id=p.legacy_affiliated_id ORDER BY p.fixed_code LIMIT 2000",[])->fetchAll(PDO::FETCH_ASSOC);
        $legacy=$this->run("SELECT CONCAT('legacy:',l.id) AS ref,l.name,l.short_name,l.club_code AS code FROM jmrc_affiliated_clubs l LEFT JOIN portal_clubs p ON p.legacy_affiliated_id=l.id WHERE l.is_deleted=0 AND p.id IS NULL ORDER BY l.club_code LIMIT 2000",[])->fetchAll(PDO::FETCH_ASSOC);
        $found=[];foreach(ClubRoster::candidates([...$rows,...$legacy]) as $r){
            $text=mb_strtolower(\Jmrc\Input\TextInput::normalize($r['name'].' '.$r['short_name'].' '.$r['code'].' '.($r['_search_aliases']??'')),'UTF-8');
            if($query===''||str_contains($text,$query)){
                $fields=ClubFeePolicy::candidateFields($r['roster_code']??$r['code']);
                if(isset($r['source_year'])){$fields['candidate_hint'].=' 2026年度名簿の参考情報です。2027年度の会員区分・登録方法は申告し、事務局が照合します。';}
                unset($r['_search_aliases']);$found[]=$r+$fields;
            }if(count($found)>=50){break;}
        }
        return $found;
    }

    /** 年度登録への接続前だけ訂正する。旧版は別の暗号文として保存し、更新の事務局照合をやり直す。 */
    public function update(int $actor,int $id,int $version,array $input):array
    {
        return $this->tx(function()use($actor,$id,$version,$input){
            $this->lockReview($actor,$id);
            $r=$this->one('SELECT * FROM portal_club_intakes WHERE id=? FOR UPDATE',[$id])?:throw new HttpFailure(404,'クラブ申請がありません');$this->read($actor,$id);
            if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ申請の変更は準備中です');}
            if((int)$r['row_version']!==$version||$r['review_state']==='linked'){throw new HttpFailure(409,'別の更新または年度登録があります。事務局へ確認してください');}
            $data=ClubRegistrationData::normalize($input);
            if(isset($input['year'])&&(string)\Jmrc\Input\TextInput::normalize((string)$input['year'])!==(string)$r['year']){throw new HttpFailure(409,'提出済み申請の年度は変更できません');}
            $this->candidate($data);$q=ClubRegistrationData::quote($this->db,(int)$r['year'],$data['jaf_kind'],$data['membership_kind'],$data['registration_kind']);
            $this->revision($r,$actor);
            $this->run('UPDATE portal_club_intakes SET registration_kind=?,jaf_kind=?,membership_kind=?,annual_yen=?,joining_yen=?,category_digit=?,payload_cipher=?,payload_hmac=?,review_state=?,row_version=row_version+1 WHERE id=?',[$data['registration_kind'],$data['jaf_kind'],$data['membership_kind'],$q['annual_yen'],$q['joining_yen'],$q['category_digit'],$this->box()->seal($this->json($data),'jmrc:club-intake:v1:'.$id),$this->digest((int)$r['year'],$data),$data['registration_kind']==='renewal'?'renewal_review':'submitted',$id]);
            $this->audit($actor,'CLUB_INTAKE_UPDATED',$id);return $this->read($actor,$id);
        });
    }

    /** 区分やJAF番号の誤りは理由付きで差し戻す。旧版と理由は暗号化し、再提出まで接続を禁止する。 */
    public function returnForCorrection(int $actor,int $id,int $version,string $reason):array
    {
        return $this->tx(function()use($actor,$id,$version,$reason){
            $this->lockReview($actor,$id);
            $this->office($actor);if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ申請の照合は準備中です');}
            $r=$this->one('SELECT * FROM portal_club_intakes WHERE id=? FOR UPDATE',[$id])?:throw new HttpFailure(404,'クラブ申請がありません');
            if((int)$r['row_version']!==$version||!in_array($r['review_state'],['submitted','renewal_review'],true)){throw new HttpFailure(409,'差し戻せる申請と版を確認してください');}
            $reason=trim(\Jmrc\Input\TextInput::normalize($reason));
            if($reason===''||mb_strlen($reason)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason)){throw new HttpFailure(422,'訂正してほしい項目と理由を入力してください');}
            $data=$this->read($actor,$id)['data'];$data['return_reason']=$reason;$this->revision($r,$actor);
            $this->run("UPDATE portal_club_intakes SET review_state='returned',payload_cipher=?,row_version=row_version+1 WHERE id=?",[$this->box()->seal($this->json($data),'jmrc:club-intake:v1:'.$id),$id]);
            $this->audit($actor,'CLUB_INTAKE_RETURNED',$id);return $this->read($actor,$id);
        });
    }

    /** 申請後の処理。本人のJAF番号を照合し、事務局の確認後だけ年度登録へ接続する。 */
    public function link(int $actor,int $id,int $version,int $clubId,string $fixedCode,bool $checked,int $legacyId=0,string $rosterCode=''):array
    {
        return $this->tx(function()use($actor,$id,$version,$clubId,$fixedCode,$checked,$legacyId,$rosterCode){
            $this->lockReview($actor,$id);
            $fixedCode=trim(\Jmrc\Input\TextInput::normalize($fixedCode));
            $this->office($actor);if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ申請の照合は準備中です');}
            $r=$this->one('SELECT * FROM portal_club_intakes WHERE id=? FOR UPDATE',[$id])?:throw new HttpFailure(404,'クラブ申請がありません');
            if((int)$r['row_version']!==$version||!in_array($r['review_state'],['submitted','renewal_review'],true)||!$checked){throw new HttpFailure(409,'版と申請内容・区分・クラブの照合確認を行ってください');}
            $data=$this->read($actor,$id)['data'];$q=ClubRegistrationData::quote($this->db,(int)$r['year'],$r['jaf_kind'],$r['membership_kind'],$r['registration_kind']);
            if($fixedCode===''){$fixedCode=$data['jaf_number'];}
            if($fixedCode!==$data['jaf_number']){throw new HttpFailure(409,'JAF登録番号が申請と異なります。差し戻して再申請を依頼してください');}
            if($rosterCode!==''){
                if($clubId!==0||$legacyId!==0||!ClubRoster::find($rosterCode)||substr($rosterCode,1)!==$fixedCode||$r['registration_kind']==='new'){throw new HttpFailure(422,'名簿の更新候補と申請番号を照合してください');}
                $matches=$this->run('SELECT id,is_deleted FROM jmrc_affiliated_clubs WHERE club_code=? FOR UPDATE',[$rosterCode])->fetchAll(PDO::FETCH_ASSOC);
                if(count($matches)>1||(count($matches)===1&&(int)$matches[0]['is_deleted']!==0)){throw new HttpFailure(409,'旧台帳の重複・削除履歴を事務局で確認してください');}
                if($matches){$legacyId=(int)$matches[0]['id'];}
                else{
                    $club=$this->one('SELECT id,fixed_code,status FROM portal_clubs WHERE fixed_code=? FOR UPDATE',[$fixedCode]);
                    if($club){throw new HttpFailure(409,'同じ固定5桁の台帳があります。既存クラブを選び直して事務局で照合してください');}
                    if(!$club){$this->run("INSERT INTO portal_clubs(fixed_code,name_full,name_short,status) VALUES(?,?,?,'active')",[$fixedCode,$data['club_name'],$data['club_short']]);$club=['id'=>(int)$this->db->lastInsertId()];}
                    $clubId=(int)$club['id'];
                }
            }
            if($q['annual_yen']!==(int)$r['annual_yen']||$q['joining_yen']!==(int)$r['joining_yen']){throw new HttpFailure(409,'申請後の料金変更を確認してください');}
            if($r['registration_kind']==='new'){
                if($clubId!==0||$legacyId!==0||!preg_match('/^[0-9]{5}$/D',$fixedCode)){throw new HttpFailure(422,'新規は既存クラブを選ばず、確認したJAF登録番号を指定してください');}
                $this->run("INSERT INTO portal_clubs(fixed_code,name_full,name_short,status) VALUES(?,?,?,'active')",[$fixedCode,$data['club_name'],$data['club_short']]);$clubId=(int)$this->db->lastInsertId();
            }else{
                if($clubId>0){$club=$this->one('SELECT id,fixed_code FROM portal_clubs WHERE id=? FOR UPDATE',[$clubId]);}
                elseif($legacyId>0){
                    $legacy=$this->one('SELECT id,club_code FROM jmrc_affiliated_clubs WHERE id=? AND is_deleted=0 FOR UPDATE',[$legacyId]);
                    if(!$legacy){throw new HttpFailure(422,'有効な既存クラブを選択してください');}
                    if(preg_match('/^[1-9][0-9]{5}$/D',$legacy['club_code'])&&substr($legacy['club_code'],1)!==$fixedCode){throw new HttpFailure(409,'旧クラブの6桁コードと申請番号が異なります。事務局で照合してください');}
                    $club=$this->one('SELECT id,fixed_code FROM portal_clubs WHERE legacy_affiliated_id=? FOR UPDATE',[$legacyId]);
                    if(!$club){
                        if(!preg_match('/^[0-9]{5}$/D',$fixedCode)){throw new HttpFailure(422,'旧クラブと照合した固定5桁コードを入力してください');}
                        $this->run("INSERT INTO portal_clubs(fixed_code,name_full,name_short,legacy_affiliated_id,status) VALUES(?,?,?,?,'active')",[$fixedCode,$data['club_name'],$data['club_short'],$legacyId]);$club=['id'=>(int)$this->db->lastInsertId(),'fixed_code'=>$fixedCode];
                    }
                }else{$club=false;}
                if(!$club){throw new HttpFailure(422,'照合した既存クラブを選択してください');}$clubId=(int)$club['id'];$fixedCode=$club['fixed_code'];
                if($fixedCode!==$data['jaf_number']){throw new HttpFailure(409,'既存クラブの番号が申請と異なります。差し戻して再申請を依頼してください');}
            }
            $bank=$data['bank_details']===''?null:$this->box()->seal($data['bank_details'],'jmrc:club-bank:v1');
            $this->run('INSERT INTO portal_club_years(club_id,year,category_digit,display_code,application_id,name_full,name_short,rep_name,contact_name,postcode,address,phone,email,bank_details_cipher) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$clubId,$r['year'],$r['category_digit'],$r['category_digit'].$fixedCode,$r['application_id'],$data['club_name'],$data['club_short'],$data['rep_name'],$data['contact_name'],$data['contact_postcode'],$data['contact_address'],$data['contact_phone'],$data['contact_email'],$bank]);$cy=(int)$this->db->lastInsertId();
            $this->revision($r,$actor);
            $this->run("UPDATE portal_club_intakes SET review_state='linked',club_year_id=?,matched_by=?,matched_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$cy,$actor,$id]);
            $this->audit($actor,'CLUB_INTAKE_LINKED',$id);return $this->read($actor,$id);
        });
    }
    private function actor(int $actor):void
    {
        if(!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$actor])){throw new HttpFailure(401,'共通アカウントの本人確認が必要です');}
    }
    /** 認証・対象参照を先に検証し、申請行より年度行を先にロックして終了操作と直列化する。 */
    private function lockReview(int $actor,int $id):void
    {
        $r=$this->read($actor,$id);
        (new ClubReviewClosure($this->db,$this->config))->requireMutable((int)$r['year']);
    }
    /** 候補IDは存在だけを検証。本人の所属・更新履歴が正しいとの承認にはしない。 */
    private function candidate(array $data):void
    {
        if($data['candidate_ref']===''){return;}[$source,$id]=explode(':',$data['candidate_ref'],2);
        if($source==='roster2026'){if(!ClubRoster::find($id)){throw new HttpFailure(422,'2026年度名簿の候補を確認してください');}return;}
        $sql=$source==='portal'?'SELECT id FROM portal_clubs WHERE id=?':'SELECT id FROM jmrc_affiliated_clubs WHERE id=? AND is_deleted=0';
        if(!$this->one($sql,[$id])){throw new HttpFailure(422,'更新候補を選び直してください。未選択でも名称を入力して申請できます');}
    }
    /** 既存の年度登録承認権限を使用する。一般会員・財務単独・クラブ代表は他人の更新照合を行えない。 */
    private function office(int $actor):void
    {
        $this->actor($actor);(new ClubService($this->db))->requireAccess($actor,0,true);
    }
    private function revision(array $r,int $actor):void
    {
        $snapshot=$r;unset($snapshot['payload_hmac']);
        $this->run('INSERT INTO portal_club_intake_revisions(intake_id,row_version,actor_user_id,snapshot_cipher) VALUES(?,?,?,?)',[$r['id'],$r['row_version'],$actor,$this->box()->seal($this->json($snapshot),'jmrc:club-intake-revision:v1:'.$r['id'].':'.$r['row_version'])]);
    }
    private function digest(int $year,array $data):string{return $this->box()->digest($this->json([$year,$data]),'club-intake-request');}
    private function box():SecretBox{return new SecretBox($this->config['data_key']??null);}
    private function json(array $data):string{return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private function key(string $key):void{if(!preg_match('/^[a-f0-9]{32,64}$/D',$key)){throw new HttpFailure(422,'申請キーを確認してください');}}
    private function audit(int $actor,string $action,int $id):void
    {
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,?,'club_intake',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,bin2hex(random_bytes(16))]);
    }
    private function run(string $sql,array $args):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function one(string $sql,array $args):array|false{return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
    /** 重複と競合は全体をロールバック。デッドロックは同じ論理操作を3回以内で再試行する。 */
    private function tx(callable $operation):array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent club intake transaction required');}
        for($i=0;$i<3;$i++){
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try{$out=$operation();$this->db->commit();return $out;}
            catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}
                if($e instanceof PDOException){$code=(int)($e->errorInfo[1]??0);if(in_array($code,[1205,1213],true)&&$i<2){usleep(20000*($i+1));continue;}if($code===1062){throw new HttpFailure(409,'同年度・クラブコード・処理キーの重複を確認してください');}}
                throw $e;
            }
        }throw new HttpFailure(503,'クラブ申請を再度お試しください');
    }
}
