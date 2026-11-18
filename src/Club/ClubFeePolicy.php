<?php
// Path: src/Club/ClubFeePolicy.php
// 機能: C06～C08・2026/11/3補足で確定した2027年度の料金と他地域クラブの会員区分を返す。請求やマスター更新はしない。
declare(strict_types=1);
namespace Jmrc\Club;

final class ClubFeePolicy
{
    public const CATEGORIES = ['official'=>'公認クラブ', 'affiliated'=>'加盟クラブ', 'associate'=>'準加盟クラブ', 'official_org'=>'公認団体', 'organization'=>'加盟団体', 'special_org'=>'特別団体', 'associate_org'=>'準加盟団体', 'business'=>'関連団体・企業'];
    public const PREFIXES = ['official'=>'1','affiliated'=>'2','associate'=>'3','official_org'=>'5','organization'=>'6','special_org'=>'7','associate_org'=>'8'];

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
        // 追加された5・7・8の区分番号と、年会費区分の対応は別の要件。既存団体の料金を流用しない。
        if(in_array($category,['official_org','special_org','associate_org'],true)){throw new \DomainException('このJAF区分の年会費は確認中です。事務局へ確認してください');}
        $annual = match ($category) {
            'official', 'business' => 50000,
            'affiliated' => 30000,
            'associate' => 20000,
            'organization' => $membership === 'supporting' ? 10000 : 20000,
        };
        $joining = $registration === 'new' && $category !== 'business' ? 10000 : 0;
        // P03: 賛助はすべて6。正会員の登録団体も6なので、数字から会費・正賛を逆算しない。
        $prefix=$membership==='supporting'?'6':self::PREFIXES[$category];
        return ['year'=>$year, 'annual_yen'=>$annual, 'joining_yen'=>$joining, 'total_yen'=>$annual+$joining,'code_prefix'=>$prefix];
    }
}
