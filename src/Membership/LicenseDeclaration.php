<?php
// Path: src/Membership/LicenseDeclaration.php
// 機能: C02の競技・審判員種別の申告を検証する。番号は既存の暗号化ライセンスを共通の正本にする。
declare(strict_types=1);
namespace Jmrc\Membership;
use Jmrc\Auth\HttpFailure;

final class LicenseDeclaration
{
    /** 指定種別を登録画面と共用する。有効な旧文字列APIも受け付け、保存形式は従来の申告欄を保つ。 */
    public static function validate(array $input, string $selectedKind): array
    {
        $out = [];
        foreach (['competition_type'=>'競技ライセンス種別', 'official_type'=>'審判員ライセンス種別'] as $key=>$label) {
            $value=$input[$key]??null;
            if(is_string($value)){$value=explode('・',trim(\Jmrc\Input\TextInput::normalize($value)));}
            $kind=$key==='competition_type'?'jaf_competition':'jaf_official';
            $out[$key]=$value===['なし']?'なし':implode('・',\Jmrc\License\LicenseTypes::validate($kind,$value));
            if(mb_strlen($out[$key],'UTF-8')>80){throw new HttpFailure(422,$label.'の選択内容を確認してください');}
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
