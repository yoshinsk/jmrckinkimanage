<?php
// Path: src/Club/ClubFeePolicy.php
// 機能: C06～C08・2026/11/3補足で確定した2027年度の料金と他地域クラブの会員区分を返す。請求やマスター更新はしない。
declare(strict_types=1);
namespace Jmrc\Club;

final class ClubFeePolicy
{
    public const CATEGORIES = ['official'=>'公認クラブ', 'affiliated'=>'加盟クラブ', 'associate'=>'準加盟クラブ', 'official_org'=>'公認団体', 'organization'=>'加盟団体', 'special_org'=>'特別団体', 'associate_org'=>'準加盟団体', 'business'=>'関連団体・企業'];
    public const PREFIXES = ['official'=>'1','affiliated'=>'2','associate'=>'3','official_org'=>'5','organization'=>'6','special_org'=>'7','associate_org'=>'8'];
    public const ORGANIZATIONS=['official_org','organization','associate_org'];

    /** N21: 候補の公開コードから入力補助だけを返す。区分6は賛助とも重なるため格式を推定しない。 */
    public static function candidateFields(string $code):array
    {
        $code=trim(\Jmrc\Input\TextInput::normalize($code));
        $out=['candidate_jaf_number'=>'','candidate_jaf_kind'=>'','candidate_hint'=>'番号と格式は登録資料を確認して入力してください。'];
        if(preg_match('/^[0-9]{5}$/D',$code)){$out['candidate_jaf_number']=$code;return $out;}
        if(!preg_match('/^([1235678])([0-9]{5})$/D',$code,$m)){return $out;}
        $out['candidate_jaf_number']=$m[2];
        if(in_array($m[1],['6','7'],true)){return $out;}
        $out['candidate_jaf_kind']=array_flip(self::PREFIXES)[$m[1]];
        $out['candidate_hint']='候補の番号と格式を入力しました。内容を確認してください。提出後に事務局が照合します。';
        return $out;
    }

    /** L12訂正: 区分7は参照用マスターに残すが、2027年度の新規登録・新しい承認には使わない。 */
    public static function requireRegistrationCategory(int $year,string $digit):void
    {
        if($year===2027&&$digit==='7'){throw new \DomainException('特別団体（区分7）は2027年度の加盟対象外です');}
    }

    /**
     * 2027年度の他地域クラブだけを対象にする。所在地・JAF登録・主催実績をDBから推定しない。
     * 第5条の例外はJAF登録と「JMRC近畿の事業として主催」の両方が必要。
     * 片方が明確に非該当なら賛助、例外の成否が不明ならnullで確認待ちを返す。
     */
    public static function outsideKinkiMembership(int $year, ?bool $jafRegisteredClub, ?bool $hostsKinkiBusinessEvent): ?string
    {
        if ($year !== 2027) { throw new \InvalidArgumentException('この区分確認は2027年度が対象です'); }
        if ($jafRegisteredClub === false || $hostsKinkiBusinessEvent === false) { return 'supporting'; }
        return $jafRegisteredClub === true && $hostsKinkiBusinessEvent === true ? 'regular' : null;
    }

    /** 数字のクラブコードと会費区分を混同しない。未決定年度・区分には金額を補完しない。 */
    public static function quote(int $year, string $category, string $membership, string $registration): array
    {
        if ($year !== 2027 || !isset(self::CATEGORIES[$category]) || !in_array($membership, ['regular','supporting'], true)
            || !in_array($registration, ['new','renewal','rejoin'], true) || ($category === 'business' && $membership !== 'supporting')) {
            throw new \InvalidArgumentException('確定済み年度・会員区分・登録方法を選択してください');
        }
        self::requireRegistrationCategory($year,self::PREFIXES[$category]??'6');
        // L12の11/22追記: 正会員・賛助とも登録団体は20,000円。区分7の加盟対象外は維持する。
        $annual = match ($category) {
            'official', 'business' => 50000,
            'affiliated' => 30000,
            'associate' => 20000,
            'official_org','organization','associate_org' => 20000,
        };
        $joining = $registration !== 'renewal' && $category !== 'business' ? 10000 : 0;
        // P03: 賛助はすべて6。正会員の登録団体も6なので、数字から会費・正賛を逆算しない。
        $prefix=$membership==='supporting'?'6':self::PREFIXES[$category];
        return ['year'=>$year, 'annual_yen'=>$annual, 'joining_yen'=>$joining, 'total_yen'=>$annual+$joining,'code_prefix'=>$prefix];
    }
}
