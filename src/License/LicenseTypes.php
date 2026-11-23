<?php
// Path: src/License/LicenseTypes.php
// 機能: 運営指定の競技・審判員種別と発行日の共通検証。旧自由記載からの推定は行わない。
declare(strict_types=1);
namespace Jmrc\License;
use Jmrc\Auth\HttpFailure;
final class LicenseTypes
{
    /** 種別の並びを固定し、チェック順だけの変更を更新と扱わない。 */
    public static function options(string $kind):array
    {
        return match($kind){
            'jaf_competition'=>['国際スーパー','国際A','国際B','国際C-C','国際C-R','国際ドラッグ','国際ソーラーカー','国内A','国内B'],
            'jaf_official'=>array_merge(...array_map(static fn($area)=>array_map(static fn($grade)=>$area.$grade,['A1','A2','B1','B2','B3']),['コース','計時','技術'])),
            default=>throw new HttpFailure(422,'競技・審判員の区分を確認してください'),
        };
    }
    /** 未指定・別区分・自由文字列・重複・入れ子を拒否し、正規の選択肢だけを返す。 */
    public static function validate(string $kind,mixed $values):array
    {
        $options=self::options($kind);
        if(!is_array($values)||!array_is_list($values)||!$values||count($values)>count($options)){throw new HttpFailure(422,'ライセンスの種類を選択してください');}
        $seen=[];
        foreach($values as $value){
            if(!is_string($value)){throw new HttpFailure(422,'ライセンスの種類を確認してください');}
            $value=\Jmrc\Input\TextInput::normalize($value);
            if(!in_array($value,$options,true)||isset($seen[$value])){throw new HttpFailure(422,'指定されたライセンスの種類を重複なく選択してください');}
            $seen[$value]=true;
        }
        return array_values(array_filter($options,static fn($value)=>isset($seen[$value])));
    }
    /** 日付は日付のまま保存する。旧期限・当日・免許証から発行日を補完しない。 */
    public static function issuedOn(mixed $value):?string
    {
        if($value===null||$value===''){return null;}
        if(!is_string($value)){throw new HttpFailure(422,'発行日を確認してください');}
        $value=\Jmrc\Input\TextInput::normalize($value);
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||$value<'1000-01-01'||!$d||$d->format('Y-m-d')!==$value){throw new HttpFailure(422,'発行日を正しい日付で入力してください');}
        return $value;
    }
    /** L16: 新しい保存・申請・未適用の移行だけで必須にする。旧レコードの読取には使わない。 */
    public static function requiredIssuedOn(mixed $value):string
    {
        return self::issuedOn($value)??throw new HttpFailure(422,'券面の発行日を入力してください。不明な場合は保存せず事務局へ確認してください');
    }
    /** N11・L15: 券面の登録名だけを受け付ける。一般の氏名や旧カナから自動補完しない。 */
    public static function registeredName(mixed $value):?string
    {
        if($value===null||$value===''){return null;}
        if(!is_string($value)){throw new HttpFailure(422,'JAFライセンス登録名を確認してください');}
        $value=trim(\Jmrc\Input\TextInput::normalize($value));
        if($value===''||mb_strlen($value)>128||!preg_match('/[\\p{Katakana}\\p{Latin}\\p{Nl}]/u',$value)||!preg_match("/^[\\p{Katakana}\\p{Latin}\\p{Nl}\\x{3099}\\x{309A} ・.ʼ'’-]+$/uD",$value)){
            throw new HttpFailure(422,'JAFライセンス登録名はカタカナまたはローマ字で128文字以内に入力してください');
        }
        return $value;
    }
    /** 移行前の種別はそのまま表示し、新形式では指定された全種別を表示する。 */
    public static function display(?string $json,?string $legacy):string
    {
        return $json!==null?implode('・',json_decode($json,true,512,JSON_THROW_ON_ERROR)):($legacy??'');
    }
}
