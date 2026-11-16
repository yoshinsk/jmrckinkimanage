<?php
// Path: src/Profile/EntryProfile.php
// 機能: 共通アカウントに紐づく旧entryの本人・クラブ情報だけを読み取り、申請入力の再利用候補を返す。元データは更新しない。
declare(strict_types=1);
namespace Jmrc\Profile;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Input\TextInput;
final class EntryProfile
{
    public function __construct(private readonly PDO $db){}
    /** portal側で訂正された氏名・生年月日を優先し、住所等は本人の旧entry登録から再利用する。 */
    public function personal(int $actor):?array
    {
        $this->actor($actor);
        $r=$this->one('SELECT last_name,first_name,last_kana,first_kana,entry_name,postcode,address,phone,contact_mobile,birthday,club_abbrev,club_code FROM entrants WHERE user_id=?',[$actor]);
        if(!$r){return null;}
        $p=$this->one('SELECT last_name,first_name,birthday FROM portal_profiles WHERE user_id=?',[$actor]);
        if($p){$r=array_replace($r,$p);}
        return TextInput::http($r)+['name_source'=>$p?'portal_profile':'entry','user_id'=>$actor];
    }
    /** JAF番号だけを本人の明示操作で再利用する。運転免許証番号・運転免許証の期限は取得しない。 */
    public function license(int $actor):?array
    {
        $this->actor($actor);$r=$this->one("SELECT jaf_license_no AS number,jaf_license_type AS license_type FROM entrants WHERE user_id=? AND jaf_license_no IS NOT NULL AND TRIM(jaf_license_no)<>''",[$actor]);
        return $r?TextInput::http($r)+['expires_on'=>'']:null;
    }
    /** 既存クラブの所有者IDで限定する。連絡先の用途やJAF区分・正賛は文字列から推定しない。 */
    public function club(int $actor):?array
    {
        $this->actor($actor);$r=$this->one('SELECT name_full,name_short,code,rep_last,rep_first,rep_contact,postcode,address,phone,contact_email,bank_name,bank_branch,bank_type,bank_account,bank_account_holder FROM clubs WHERE user_id=? AND is_deleted=0',[$actor]);
        if(!$r){return null;}$r=TextInput::http($r);$code=trim($r['code']);
        $v=['club_name'=>$r['name_full'],'club_short'=>$r['name_short'],'club_postcode'=>$r['postcode'],'club_address'=>$r['address'],'club_phone'=>$r['phone'],
            'rep_name'=>trim($r['rep_last'].' '.$r['rep_first']),'contact_email'=>$r['contact_email'],'same_contact'=>'0',
            'bank_details'=>implode("\n",array_filter([$r['bank_name'],$r['bank_branch'],$r['bank_type'],$r['bank_account'],$r['bank_account_holder']],static fn($x)=>$x!==null&&$x!==''))];
        if(preg_match('/^(?:[1236])?([0-9]{5})$/D',$code,$m)){$v['jaf_number']=$m[1];}
        $c=$this->db->prepare('SELECT id FROM jmrc_affiliated_clubs WHERE club_code=? AND is_deleted=0');$c->execute([$code]);$ids=$c->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)===1){$v['candidate_ref']='legacy:'.$ids[0];$v['registration_kind']='renewal';}
        return ['values'=>$v,'legacy_code'=>$code,'rep_contact_reference'=>$r['rep_contact'],'source'=>'entry_club'];
    }
    /** 旧ロールだけでは本人確認を代替しない。停止・未認証・共通管理行欠落は既存認証と同じく拒否する。 */
    private function actor(int $id):void
    {
        if(!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$id])){throw new HttpFailure(401,'サインイン状態を確認してください');}
    }
    private function one(string $sql,array $args):array|false{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC);}
}
