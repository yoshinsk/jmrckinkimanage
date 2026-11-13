<?php
// Path: src/Bank/StrictCsv.php
// 機能: 金融CSVの文字コード・容量・制御文字・引用符を検査する。取引の照合や入金は行わない。
declare(strict_types=1);
namespace Jmrc\Bank;
use Jmrc\Auth\HttpFailure;
final class StrictCsv
{
    /** 元のゆうちょ解析と同じ順序で検証し、文字変換後のテキストだけを返す。 */
    public static function text(string $bytes,string $encoding):string
    {
        if(!function_exists('mb_convert_kana')){throw new HttpFailure(503,'CSV文字変換拡張を確認してください');}
        if(strlen($bytes)<1 || strlen($bytes)>2097152 || !in_array($encoding,['UTF-8','CP932'],true)){throw new HttpFailure(422,'CSVは2MiB以内、UTF-8またはCP932で指定してください');}
        if(!mb_check_encoding($bytes,$encoding)){throw new HttpFailure(422,'指定文字コードでCSVを読み取れません');}
        $text=mb_convert_encoding($bytes,'UTF-8',$encoding);$text=preg_replace('/^\xEF\xBB\xBF/','',$text);$text=str_replace(["\r\n","\r"],"\n",$text);
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)){throw new HttpFailure(422,'CSVに制御文字があります');}
        self::quotes($text);return $text;
    }
    /** fgetcsvが許容する不完全な引用符を先に拒否する。空行・引用内改行・二重引用符の扱いを維持する。 */
    private static function quotes(string $text):void
    {
        $state='start';$length=strlen($text);
        for($i=0;$i<$length;$i++){$c=$text[$i];
            if($state==='quoted'){if($c==='"'){if($i+1<$length && $text[$i+1]==='"'){$i++;}else{$state='closed';}}continue;}
            if($c===',' || $c==="\n"){$state='start';continue;}
            if($state==='closed'){throw new HttpFailure(422,'CSV引用符の後に不正な文字があります');}
            if($c==='"'){if($state!=='start'){throw new HttpFailure(422,'CSV引用符の位置が不正です');}$state='quoted';}else{$state='plain';}
        }
        if($state==='quoted'){throw new HttpFailure(422,'CSV引用符が閉じていません');}
    }
}
