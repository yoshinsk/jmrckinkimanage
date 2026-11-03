<?php
// Path: src/Club/ClubFeePolicy.php
// 機能: C06～C08・2026/11/3補足で確定した2027年度の料金を区分別に返す。請求やマスター更新はしない。
declare(strict_types=1);
namespace Jmrc\Club;

final class ClubFeePolicy
{
    public const CATEGORIES = ['official'=>'公認', 'affiliated'=>'加盟', 'associate'=>'準加盟', 'organization'=>'登録団体', 'business'=>'関連団体・企業'];

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
