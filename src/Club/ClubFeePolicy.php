<?php
// Path: src/Club/ClubFeePolicy.php
// 機能: C06～C08・2026/11/3補足で確定した2027年度の料金と他地域クラブの会員区分を返す。請求やマスター更新はしない。
declare(strict_types=1);
namespace Jmrc\Club;

final class ClubFeePolicy
{
    public const CATEGORIES = ['official'=>'公認', 'affiliated'=>'加盟', 'associate'=>'準加盟', 'organization'=>'登録団体', 'business'=>'関連団体・企業'];

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
        $annual = match ($category) {
            'official', 'business' => 50000,
            'affiliated' => 30000,
            'associate' => 20000,
            'organization' => $membership === 'supporting' ? 10000 : 20000,
        };
        $joining = $registration === 'new' && $category !== 'business' ? 10000 : 0;
        return ['year'=>$year, 'annual_yen'=>$annual, 'joining_yen'=>$joining, 'total_yen'=>$annual+$joining];
    }
}
