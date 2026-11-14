<?php
// Path: src/Bank/PaymentCoreCsv.php
// 機能: 提供された8列のペイメントコアCSVを確認用に読む。年・取引ID・決済状態を推測せず自動入金しない。
declare(strict_types=1);
namespace Jmrc\Bank;
use Jmrc\Auth\HttpFailure;
require_once __DIR__.'/StrictCsv.php';
final class PaymentCoreCsv
{
    public const HEADERS=['決済者名','電話番号','メールアドレス','支払方法','支払金額','決済手数料','決済日','■指定名義人名（カタカナ氏名＋エントリーID）を入力してください'];
    /** 年を指定しても「担当者による補足」として扱う。電話・メールを解析結果や照合キーに複写しない。 */
    public function parse(string $bytes,string $encoding,?int $year=null,?string $nameHeader=null):array
    {
        if($year!==null&&($year<1900||$year>2199)){throw new HttpFailure(422,'決済年を西暦4桁で指定してください');}
        $text=StrictCsv::text($bytes,$encoding);$fp=fopen('php://temp','w+');fwrite($fp,$text);rewind($fp);
        $rows=[];$seen=[];$record=1;
        try{
            $headers=self::HEADERS;
            if($nameHeader!==null){
                if(trim($nameHeader)===''||mb_strlen($nameHeader)>255||preg_match('/[\x00-\x1f\x7f]/',$nameHeader)||in_array($nameHeader,array_slice($headers,0,7),true)){throw new HttpFailure(422,'設定済みの指定名義欄の見出しを確認してください');}
                $headers[7]=$nameHeader;
            }
            if(fgetcsv($fp,0,',','"','')!==$headers){throw new HttpFailure(422,'ペイメントコアの8列の見出しを確認してください');}
            while(($r=fgetcsv($fp,0,',','"',''))!==false){
                $record++;if(count($r)===1&&($r[0]===null||$r[0]==='')){continue;}
                if(count($r)!==8||count($rows)>=2000||$record>2021){throw new HttpFailure(422,'CSVは8列・2000明細以内にしてください');}
                [$payer,,,$method,$gross,$fee,$date,$specified]=$r;
                foreach([$payer,$specified] as $name){if(trim($name)===''||mb_strlen($name)>190){throw new HttpFailure(422,'決済者名・指定名義を確認してください');}}
                if(mb_strlen($method)>50){throw new HttpFailure(422,'支払方法を確認してください');}
                $gross=self::money($gross,false);$fee=self::money($fee,true);
                if($fee>$gross){throw new HttpFailure(422,'手数料が支払金額を超えています');}
                $date=trim($date);
                if(!preg_match('/^(\d{1,2})\/(\d{1,2})$/D',$date,$m)||!checkdate((int)$m[1],(int)$m[2],$year??2000)){throw new HttpFailure(422,'決済日の月日を確認してください');}
                $full=$year===null?null:sprintf('%04d-%02d-%02d',$year,(int)$m[1],(int)$m[2]);
                // 一致した候補の注意表示に限る。同額同日の別実取引を削除したり、再取込と確定したりしない。
                $key=hash('sha256',json_encode([$payer,$specified,$method,$gross,$fee,$date,$year],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                // F11/F16: 支払金額が会費本体か総額か未確定。手数料を再控除して収入を過少計上しない。
                $rows[]=['record_no'=>$record,'payer_name'=>$payer,'specified_name'=>$specified,'method'=>$method,'gross_yen'=>$gross,'fee_yen'=>$fee,'net_yen'=>null,'principal_yen'=>null,'date_text'=>$date,'date'=>$full,'year_supplied'=>$year!==null,'duplicate_candidate'=>isset($seen[$key]),'match_state'=>'manual_review'];$seen[$key]=true;
            }
        }finally{fclose($fp);}
        if(!$rows){throw new HttpFailure(422,'決済明細がありません');}
        return ['rows'=>$rows,'auto_confirm_allowed'=>false,'fee_expense_allowed'=>false,'purpose'=>'membership_fee','principal_status'=>'awaiting_F16','transaction_id_available'=>false,'settlement_status_available'=>false,'year_source'=>$year===null?'missing':'operator_supplied'];
    }
    /** 円の整数のみ受け付け、小数、負数、指数表記、不正な桁区切りを拒否する。 */
    private static function money(string $value,bool $zero):int
    {
        if($zero&&$value==='0'){return 0;}
        if(!preg_match('/^(?:[1-9]\d*|[1-9]\d{0,2}(?:,\d{3})+)$/D',$value)){throw new HttpFailure(422,'支払金額・手数料を整数で確認してください');}
        $n=str_replace(',','',$value);if(strlen($n)>10||(int)$n>1000000000){throw new HttpFailure(422,'支払金額・手数料が上限を超えています');}return (int)$n;
    }
}
