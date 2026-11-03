<?php
// Path: src/Membership/LicenseDeclaration.php
// 機能: C02の競技・審判員種別の申告を検証する。番号は既存の暗号化ライセンスを共通の正本にする。
declare(strict_types=1);
namespace Jmrc\Membership;
use Jmrc\Auth\HttpFailure;

final class LicenseDeclaration
{
    /** 正式な選択肢を推測せず券面の種別を申告。非保有は「なし」と明記し、選択ライセンスと矛盾させない。 */
    public static function validate(array $input, string $selectedKind): array
    {
        $out = [];
        foreach (['competition_type'=>'競技ライセンス種別', 'official_type'=>'審判員ライセンス種別'] as $key=>$label) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || preg_match_all('/./us', $value) === false
                || mb_strlen($value, 'UTF-8') > 80 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new HttpFailure(422, $label.'を80文字以内で入力してください。保有していない場合は「なし」と入力してください');
            }
            $out[$key] = trim($value);
        }
        $selected = match ($selectedKind) {
            'jaf_competition', 'competition' => 'competition_type',
            'jaf_official', 'official' => 'official_type',
            default => throw new HttpFailure(422, 'JAFライセンスを選択してください'),
        };
        if ($out[$selected] === 'なし') { throw new HttpFailure(422, '選択したライセンスの種別を入力してください'); }
        return $out;
    }
}
