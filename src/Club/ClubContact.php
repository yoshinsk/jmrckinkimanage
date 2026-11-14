<?php
// Path: src/Club/ClubContact.php
// 機能: N09の所属支部と代表者兼連絡先を検証し、二つの確認画面で同じ入力規則を使う。
declare(strict_types=1);
namespace Jmrc\Club;
use Jmrc\Auth\HttpFailure;
final class ClubContact
{
    public const REGIONS=['25'=>'25：滋賀県','26'=>'26：京都府','27'=>'27：大阪府','28'=>'28：兵庫県','29'=>'29：奈良県','30'=>'30：和歌山県','other'=>'その他（記入）'];
    /** 住所から支部・会員区分を推定しない。兼任が明示された場合だけ連絡先へ転記する。 */
    public static function normalize(array $in):array
    {
        $out=[];
        foreach(['region','region_other','rep_name','rep_kana','rep_postcode','rep_address','rep_phone','rep_mobile','rep_email','contact_name','contact_kana','contact_postcode','contact_address','contact_phone','contact_mobile','contact_email'] as $key){
            $v=$in[$key]??'';
            if(!is_string($v)||mb_strlen($v)>255||preg_match('//u',$v)!==1||preg_match('/[\x00-\x1f\x7f]/',$v)){throw new HttpFailure(422,'支部・代表者・連絡先の文字を確認してください');}
            $out[$key]=trim($v);
        }
        if($out['region']!==''&&!array_key_exists($out['region'],self::REGIONS)){throw new HttpFailure(422,'所属支部を選択してください');}
        if($out['region']==='other'&&$out['region_other']===''){throw new HttpFailure(422,'その他の所属支部を記入してください');}
        if($out['region']!=='other'){$out['region_other']='';}
        $same=$in['same_contact']??'0';if(!in_array($same,['0','1'],true)){throw new HttpFailure(422,'連絡先の兼任を選択してください');}$out['same_contact']=$same;
        if($same==='1'){
            foreach(['name','kana','postcode','address','phone','mobile','email'] as $part){$out['contact_'.$part]=$out['rep_'.$part];}
        }
        return $out;
    }
}
