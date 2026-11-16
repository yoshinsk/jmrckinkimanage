<?php
// Path: src/Input/TextInput.php
// 機能: ポータル共通の英数字・空白整形。ローマ数字を保持し、整形後も項目別検証・SQL束縛・HTMLエスケープを必須とする。
declare(strict_types=1);
namespace Jmrc\Input;
use Jmrc\Auth\HttpFailure;
final class TextInput
{
    // 認証照合値と取込原本は文字の同一性を保つ。POST/GETのキー自体は変換しない。
    public const EXACT=['password','password_confirm','password_confirmation','current_password','new_password','confirm_password','token','_csrf','signature','request_key','instance','csv_text'];
    /** NFKCはⅠ・Ⅲも変えるため使用しない。対象を全角A-Z/a-z/0-9と全角空白へ限定する。 */
    public static function normalize(string $value):string
    {
        if(!mb_check_encoding($value,'UTF-8')){throw new HttpFailure(422,'入力文字の形式を確認してください');}
        $out=preg_replace_callback('/[Ａ-Ｚａ-ｚ０-９]/u',static fn($m)=>mb_chr(mb_ord($m[0],'UTF-8')-0xFEE0,'UTF-8'),$value);
        return str_replace('　',' ',$out);
    }
    /** HTTPの配列値を検証前に整形する。配列の注入を文字列へ強制変換しない。 */
    public static function http(array $input,int $depth=0):array
    {
        if($depth>8){throw new HttpFailure(422,'入力項目の構造を確認してください');}
        foreach($input as $key=>$value){
            if(in_array((string)$key,self::EXACT,true)){continue;}
            if(is_array($value)){$input[$key]=self::http($value,$depth+1);}
            elseif(is_string($value)){$input[$key]=self::normalize($value);}
        }
        return $input;
    }
}
