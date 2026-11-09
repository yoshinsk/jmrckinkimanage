<?php
// Path: src/Content/EditorService.php
// 機能: 事務局限定の暗号化下書き・訂正版を保存する。一般公開、メール、得点反映、保険加入を実行しない。
declare(strict_types=1);
namespace Jmrc\Content;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Operations\AnswerRules;

final class EditorService
{
    public const KINDS=['calendar'=>'行事予定','mail_template'=>'メール文面','result_csv'=>'結果CSV','document_pdf'=>'資料PDF'];
    public function __construct(private readonly PDO $db,private readonly array $config){}
    /** 職務取消・本人停止を含む既存の事務局判定を毎回行い、一般アカウントの投稿を許さない。 */
    public function access(int $actor):void{(new \Jmrc\Operations\PreparationService($this->db,$this->config))->access($actor);}
    public function listing(int $actor):array
    {
        $this->access($actor);
        return $this->q('SELECT id,kind,item_key,row_version,edited_at,expires_at FROM portal_editor_drafts WHERE expires_at>UTC_TIMESTAMP(6) ORDER BY edited_at DESC LIMIT 200',[])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function read(int $actor,int $id):array
    {
        $this->access($actor);$r=$this->q('SELECT d.*,r.payload_cipher FROM portal_editor_drafts d JOIN portal_editor_revisions r ON r.draft_id=d.id AND r.version=d.row_version WHERE d.id=? AND d.expires_at>UTC_TIMESTAMP(6)',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$r){throw new HttpFailure(404,'下書きがないか保存期限を過ぎています');}
        $data=json_decode($this->box()->open($r['payload_cipher'],$this->context($id,(int)$r['row_version'])),true,512,JSON_THROW_ON_ERROR);unset($r['payload_cipher']);
        return $r+['data'=>$data,'published'=>false,'sent'=>false];
    }
    /** 更新版の一致を必須にし、訂正版の前の内容も保持。同じ内容の再送では最終編集日時を動かさない。 */
    public function save(int $actor,string $kind,string $key,int $version,array $input):array
    {
        $this->access($actor);
        if(!isset(self::KINDS[$kind])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$key)||$version<0){throw new HttpFailure(422,'種類・管理キー・更新版を確認してください');}
        $data=$this->validate($kind,$input);
        if($kind==='result_csv' && $key!==$data['result']['replacement_key']){throw new HttpFailure(422,'結果の管理キーは '.$data['result']['replacement_key'].' にしてください');}
        $plain=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=hash('sha256',$plain,true);$box=$this->box();
        if($this->db->inTransaction()){throw new \LogicException('Editor requires its own transaction');}
        $this->db->beginTransaction();
        try{
            $this->access($actor);
            $old=$this->q('SELECT d.*,r.payload_sha256 FROM portal_editor_drafts d JOIN portal_editor_revisions r ON r.draft_id=d.id AND r.version=d.row_version WHERE d.kind=? AND d.item_key=? FOR UPDATE',[$kind,$key])->fetch(PDO::FETCH_ASSOC);
            if(($old?(int)$old['row_version']:0)!==$version){throw new HttpFailure(409,'別の操作で更新されました。一覧から開き直してください');}
            if($old&&new \DateTimeImmutable($old['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))){throw new HttpFailure(409,'期限切れの下書きは更新できません。新しい管理キーを使ってください');}
            if($old&&hash_equals($old['payload_sha256'],$hash)){$this->db->commit();return ['id'=>(int)$old['id'],'row_version'=>$version,'replayed'=>true];}
            $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));$time=$now->format('Y-m-d H:i:s.u');$expires=AnswerRules::draftExpires($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');$next=$version+1;
            if($old){$id=(int)$old['id'];$this->q('UPDATE portal_editor_drafts SET row_version=?,edited_by=?,edited_at=?,expires_at=? WHERE id=?',[$next,$actor,$time,$expires,$id]);}
            else{$this->q('INSERT INTO portal_editor_drafts(kind,item_key,row_version,edited_by,edited_at,expires_at) VALUES(?,?,?,?,?,?)',[$kind,$key,$next,$actor,$time,$expires]);$id=(int)$this->db->lastInsertId();}
            $this->q('INSERT INTO portal_editor_revisions(draft_id,version,payload_cipher,payload_sha256,edited_by,edited_at) VALUES(?,?,?,?,?,?)',[$id,$next,$box->seal($plain,$this->context($id,$next)),$hash,$actor,$time]);
            $this->q("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'EDITOR_DRAFT_SAVED','editor_draft',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$id,bin2hex(random_bytes(16))]);
            $this->db->commit();return ['id'=>$id,'row_version'=>$next,'replayed'=>false];
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}if($e instanceof \PDOException&&$e->getCode()==='23000'){throw new HttpFailure(409,'同じ管理キーが登録されました。一覧から開き直してください');}throw $e;}
    }
    /** 構造化入力だけ保存し、自由なHTML、添付名のパス、未知の項目を引き継がない。 */
    private function validate(string $kind,array $in):array
    {
        $str=static function(string $key,int $max=200)use($in):string{$v=$in[$key]??'';if(!is_string($v)||!preg_match('//u',$v)||mb_strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v)){throw new HttpFailure(422,'入力を確認してください：'.$key);}return trim($v);};
        $data=['title'=>$str('title')];if($data['title']===''){throw new HttpFailure(422,'件名を入力してください');}
        try{
            if($kind==='calendar'){
                $category=$str('category');if(!isset(AnswerRules::CALENDAR[$category])){throw new HttpFailure(422,'予定の分類を選んでください');}
                $date=$str('date');AnswerRules::date($date);return $data+['category'=>$category,'date'=>$date,'description'=>$str('description',2000),'visibility'=>'internal_draft','approval'=>'pending'];
            }
            if($kind==='mail_template'){$purpose=$str('purpose');if(!isset(AnswerRules::MAIL[$purpose])){throw new HttpFailure(422,'通知の種類を選んでください');}return $data+['purpose'=>$purpose,'body'=>$str('body',10000),'sender'=>'office','send_mode'=>'manual','send_enabled'=>false];}
            if($kind==='result_csv'&&!is_string($in['bytes']??null)){throw new HttpFailure(422,'結果CSVを指定してください');}
            if($kind==='result_csv'){return $data+['result'=>AnswerRules::results($in['bytes']??'')];}
            $bytes=$in['bytes']??null;
            if(!is_string($bytes)||strlen($bytes)>2097152||!str_starts_with($bytes,'%PDF-')||!preg_match('/%%EOF\s*$/D',$bytes)) {throw new HttpFailure(422,'PDFは2MB以内で、正しい原本を指定してください');}
            return $data+['mime'=>'application/pdf','file_base64'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes),'visibility'=>'internal_draft'];
        }catch(\InvalidArgumentException $e){throw new HttpFailure(422,$e->getMessage());}
    }
    private function q(string $sql,array $args):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function box():\Jmrc\Card\SecretBox{return new \Jmrc\Card\SecretBox($this->config['data_key']??null);}
    private function context(int $id,int $v):string{return 'editor-draft:'.$id.':'.$v;}
}
