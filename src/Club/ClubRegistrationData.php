<?php
// Path: src/Club/ClubRegistrationData.php
// 機能: F08/N09で確定した正式入力を検証。不要な掲載情報を保存せず、兼任は明示選択時だけ転記する。
declare(strict_types=1);
namespace Jmrc\Club;
use Jmrc\Auth\HttpFailure;
final class ClubRegistrationData
{
    public const REGISTRATIONS=['new'=>'新規','renewal'=>'更新','rejoin'=>'再加入'];
    public const MEMBERSHIPS=['regular'=>'正会員','supporting'=>'賛助会員'];
    public const LABELS=\Jmrc\Operations\MaterialsReview::CLUB_FIELDS;
    public static function normalize(array $in):array
    {
        $in=\Jmrc\Input\TextInput::http($in);
        $out=\Jmrc\Operations\MaterialsReview::club($in);
        $jaf=$in['jaf_number']??null;
        if(!is_string($jaf)||!preg_match('/^[0-9]{5}$/D',trim($jaf))){throw new HttpFailure(422,'JAF登録番号を5桁で入力してください');}
        $out['jaf_number']=trim($jaf);
        foreach(['rep_email','contact_email'] as $key){if(!filter_var($out[$key],FILTER_VALIDATE_EMAIL)){throw new HttpFailure(422,self::LABELS[$key].'を確認してください');}}
        foreach(['club_postcode','rep_postcode','contact_postcode'] as $key){if(!preg_match('/^[0-9]{3}-?[0-9]{4}$/D',$out[$key])){throw new HttpFailure(422,self::LABELS[$key].'を7桁で入力してください');}}
        foreach(['club_short','rep_name','contact_name'] as $key){if(mb_strlen($out[$key])>128){throw new HttpFailure(422,self::LABELS[$key].'は128文字以内です');}}
        foreach(['club_phone','rep_phone','rep_mobile','contact_phone','contact_mobile'] as $key){if(mb_strlen($out[$key])>32){throw new HttpFailure(422,self::LABELS[$key].'は32文字以内です');}}
        $kind=$in['registration_kind']??null;
        if(!is_string($kind)||!isset(self::REGISTRATIONS[$kind])||!isset(ClubFeePolicy::CATEGORIES[$out['jaf_kind']])||!isset(self::MEMBERSHIPS[$out['membership_kind']])||($out['jaf_kind']==='business'&&$out['membership_kind']!=='supporting')){throw new HttpFailure(422,'登録方法・JAF区分・正会員／賛助を選択してください');}
        $candidate=$in['candidate_ref']??'';
        if(!is_string($candidate)||($candidate!==''&&!preg_match('/^(portal|legacy):[1-9][0-9]{0,18}$/D',$candidate))){throw new HttpFailure(422,'更新候補を確認してください');}
        if($candidate!==''&&$kind==='new'){throw new HttpFailure(422,'既存クラブを選んだ場合は更新または再加入を選択してください');}
        $out['candidate_ref']=$candidate;$out['registration_kind']=$kind;$bank=$in['bank_details']??'';
        if(!is_string($bank)||mb_strlen($bank)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$bank)){throw new HttpFailure(422,'クラブの口座情報を確認してください');}
        $out['bank_details']=trim($bank);return $out;
    }
    /** 正式略称や所在地から正賛・JAF区分を逆算しない。数字コードの6は料金区分ではない。 */
    public static function quote(\PDO $db,int $year,string $jaf,string $membership,string $registration):array
    {
        try{$known=ClubFeePolicy::quote($year,$jaf,$membership,$registration);}
        catch(\DomainException $e){throw new HttpFailure(409,$e->getMessage());}
        catch(\InvalidArgumentException){throw new HttpFailure(422,'確定済み年度・登録区分を選択してください');}
        $s=$db->prepare('SELECT annual_yen,joining_yen FROM portal_club_fee_profiles WHERE year=? AND jaf_kind=? AND membership_kind=? AND enabled=1');$s->execute([$year,$jaf,$membership]);$r=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$r){throw new HttpFailure(409,'この年度・区分の料金表は未設定です');}
        $annual=(int)$r['annual_yen'];$join=$registration==='new'?(int)$r['joining_yen']:0;
        if($annual!==$known['annual_yen']||$join!==$known['joining_yen']){throw new HttpFailure(409,'確定料金と年度マスターの照合が必要です');}
        return ['annual_yen'=>$annual,'joining_yen'=>$join,'total_yen'=>$annual+$join,'category_digit'=>$known['code_prefix']];
    }
}
