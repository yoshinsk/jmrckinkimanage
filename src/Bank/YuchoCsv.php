<?php
// Path: src/Bank/YuchoCsv.php
// 機能: ゆうちょの受入金額・詳細２を厳密に解析し、曖昧な日付を推測しない。末尾数字はID扱いしない。
declare(strict_types=1);
namespace Jmrc\Bank;
use Jmrc\Auth\HttpFailure;
final class YuchoCsv
{
    public const VERSION='yucho-review-v1';
    public static function name(string $value): string
    {
        return preg_replace('/[\s　"]+/u','',mb_convert_kana($value,'aKVrn','UTF-8'));
    }
    public static function date(string $value): ?string
    {
        $value=trim(mb_convert_kana($value,'n','UTF-8'));if($value===''){return null;}
        if(preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})$/D',$value,$m)
            || preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/uD',$value,$m)
            || preg_match('/^(\d{4})(\d{2})(\d{2})$/D',$value,$m)){
            if((int)$m[1]>=1900 && (int)$m[1]<=2199 && checkdate((int)$m[2],(int)$m[3],(int)$m[1])){return sprintf('%04d-%02d-%02d',$m[1],$m[2],$m[3]);}
        }
        throw new HttpFailure(422,'取扱日の形式を確認してください（西暦年月日）');
    }
    /** ヘッダー前置きは20レコードまで。受入0/空欄は支出等として件数を報告し、正の入金の不正値は全体を拒否する。 */
    public function parse(string $bytes,string $encoding): array
    {
        if(!function_exists('mb_convert_kana')){throw new HttpFailure(503,'CSV文字変換拡張を確認してください');}
        if(strlen($bytes)<1 || strlen($bytes)>2097152 || !in_array($encoding,['UTF-8','CP932'],true)){throw new HttpFailure(422,'CSVは2MiB以内、UTF-8またはCP932で指定してください');}
        if(!mb_check_encoding($bytes,$encoding)){throw new HttpFailure(422,'指定文字コードでCSVを読み取れません');}
        $text=mb_convert_encoding($bytes,'UTF-8',$encoding);$text=preg_replace('/^\xEF\xBB\xBF/','',$text);$text=str_replace(["\r\n","\r"],"\n",$text);
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)){throw new HttpFailure(422,'CSVに制御文字があります');}
        $this->quotes($text);$fp=fopen('php://temp','r+');fwrite($fp,$text);rewind($fp);$header=null;$data=[];$ignored=0;$record=0;
        try{
            while(($row=fgetcsv($fp,0,',','"',''))!==false){
                $record++;if($record>2021 || count($row)>64){throw new HttpFailure(422,'CSVは2000明細・64列以内に分けてください');}
                if(count($row)===1 && ($row[0]===null || trim($row[0])==='')){continue;}
                if(!$header){
                    $keys=array_map(static fn($v)=>trim(mb_convert_kana((string)$v,'aKVrn','UTF-8')),$row);
                    if(in_array('受入金額',$keys,true) && in_array('詳細2',$keys,true)){
                        if(count(array_unique($keys))!==count($keys)){throw new HttpFailure(422,'CSVヘッダーが重複しています');}
                        $header=$keys;
                    }elseif($record>=20){throw new HttpFailure(422,'ゆうちょの受入金額・詳細２ヘッダーが必要です');}
                    continue;
                }
                if(count($row)!==count($header)){throw new HttpFailure(422,'CSVの列数が一致しません（レコード'.$record.'）');}
                $r=array_combine($header,$row);$raw=trim(mb_convert_kana((string)$r['受入金額'],'n','UTF-8'));
                if($raw==='' || $raw==='0'){$ignored++;continue;}
                if(!preg_match('/^(?:[1-9]\d*|[1-9]\d{0,2}(?:,\d{3})+)$/D',$raw) || strlen(str_replace(',','',$raw))>10){throw new HttpFailure(422,'受入金額を確認してください（レコード'.$record.'）');}
                $amount=(int)str_replace(',','',$raw);$name=trim((string)$r['詳細2']);
                if($amount>1000000000 || self::name($name)==='' || mb_strlen($name,'UTF-8')>190){throw new HttpFailure(422,'受入金額・名義を確認してください（レコード'.$record.'）');}
                $dates=[];foreach(['取扱日','取引日','お取扱日','取扱年月日','年月日','日付','入金日'] as $key){if(isset($r[$key]) && trim($r[$key])!==''){$dates[]=self::date($r[$key]);}}
                if(count(array_unique($dates))>1){throw new HttpFailure(422,'複数の日付欄が一致しません（レコード'.$record.'）');}
                if(count($data)>=2000){throw new HttpFailure(422,'CSVは2000受入明細以内に分けてください');}
                $data[]=['record_no'=>$record,'date'=>$dates[0]??null,'amount'=>$amount,'name'=>$name];
            }
        }finally{fclose($fp);}
        if(!$header || !$data){throw new HttpFailure(422,'正の受入明細がありません');}
        return ['rows'=>$data,'ignored'=>$ignored];
    }
    /** fgetcsv単独では閉じていない引用符も受容するため、先にRFC形式の引用境界を検証する。 */
    private function quotes(string $text): void
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
