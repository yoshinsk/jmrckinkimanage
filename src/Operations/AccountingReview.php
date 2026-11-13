<?php
// Path: src/Operations/AccountingReview.php
// 機能: 会計・予算の確認用計算。年度予算、収支、残額、貸借、期間出力を純粋な配列で扱い、業務DBへ書き込まない。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
final class AccountingReview
{
    // 提供帳票から選んだ試作用科目。正式なマスター・予算・税務上の扱いは運営回答で確定する。
    public const ITEMS=[
        'club_join'=>['入会金','income'],'club_fee'=>['クラブ年会費','income'],'member_fee'=>['個人会員費','income'],
        'contribution'=>['部会分担金','income'],'interest'=>['受取利息','income'],'income_other'=>['その他収入','income'],
        'meeting'=>['会議費','expense'],'supplies'=>['事務用品費','expense'],'portal'=>['ポータル維持費','expense'],
        'payment_fee'=>['決済手数料','expense'],'expense_other'=>['その他支出','expense']
    ];
    public const BALANCE=['cash'=>['現金','asset'],'deposit'=>['普通預金','asset'],'term_deposit'=>['定期預金','asset'],'receivable'=>['未収入金','asset'],'unpaid'=>['未払金','liability'],'held'=>['預り金','liability'],'surplus'=>['剰余金','equity']];
    public static function year(mixed $value):int
    {
        if(!is_scalar($value)||!preg_match('/^20\d{2}$/D',(string)$value)){throw new HttpFailure(422,'年度を2000～2099で入力してください');}return (int)$value;
    }
    public static function money(mixed $value,bool $signed=false):int
    {
        if((!is_int($value)&&!is_string($value))||!preg_match($signed?'/^-?(?:0|[1-9]\d{0,9})$/D':'/^(?:0|[1-9]\d{0,9})$/D',(string)$value)||abs((int)$value)>1000000000){throw new HttpFailure(422,'金額は10億円以内の整数で入力してください');}return (int)$value;
    }
    public static function date(string $value):string
    {
        if(!preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])){throw new HttpFailure(422,'適用日を正しい年月日で入力してください');}return $value;
    }
    /** 科目ごとに年度予算を持つ。0円を未設定と混同せず、未設定はnullのまま残す。 */
    public static function budget(array $input):array
    {
        if(array_diff(array_keys($input),array_keys(self::ITEMS))){throw new HttpFailure(422,'未登録の科目があります');}
        $out=[];foreach(self::ITEMS as $key=>$_){$value=$input[$key]??'';$out[$key]=$value===''||$value===null?null:self::money($value);}return $out;
    }
    /** 手入力は収支明細のみ。登録日はサーバーから渡し、適用日とは区別する。 */
    public static function entry(array $in,string $registeredAt,string $key):array
    {
        $item=$in['item']??null;$date=$in['date']??null;$detail=$in['detail']??'';$memo=$in['memo']??'';
        if(!is_string($item)||!isset(self::ITEMS[$item])||!is_string($date)||!is_string($detail)||!is_string($memo)||mb_strlen($detail)>200||mb_strlen($memo)>1000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$detail.$memo)){throw new HttpFailure(422,'科目・費目明細・備考を確認してください');}
        return ['key'=>$key,'source'=>'manual','registered_at'=>$registeredAt,'date'=>self::date($date),'item'=>$item,'amount'=>self::money($in['amount']??null),'detail'=>trim($detail),'memo'=>trim($memo)];
    }
    /** 会費連携の試算。最新スナップショットで置換し、再実行・訂正・取消で金額を重ねない。実DBの計上時点は未接続。 */
    public static function synchronize(array $ledger,array $fees):array
    {
        if(count($ledger)>2000||count($fees)>2000){throw new HttpFailure(422,'確認用明細は2000件以内です');}
        $out=array_values(array_filter($ledger,static fn($r)=>$r['source']==='manual'));$seen=[];
        foreach($fees as $fee){
            $id=$fee['id']??null;$kind=$fee['item']??null;
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||!in_array($kind,['club_join','club_fee','member_fee'],true)||!in_array($fee['status']??null,['confirmed','cancelled','pending'],true)){throw new HttpFailure(422,'会費の連携元・状態を確認してください');}
            if(isset($seen[$id])){if($seen[$id]!==$fee){throw new HttpFailure(409,'同じ連携元に異なる内容があります');}continue;}$seen[$id]=$fee;
            if($fee['status']!=='confirmed'){continue;}
            $row=self::entry($fee,(string)($fee['registered_at']??''),'fee:'.$id);$row['source']='portal_fee_example';$out[]=$row;
        }
        if(count($out)>2000){throw new HttpFailure(422,'確認用明細は2000件以内です');}return $out;
    }
    /** 年度内の指定期間、年初から終了日までの累計、年間予算を比較する。端数は表示時だけ丸める。 */
    public static function report(array $ledger,array $budget,int $year,string $start,string $end):array
    {
        self::year($year);self::date($start);self::date($end);
        if($start>$end||substr($start,0,4)!==(string)$year||substr($end,0,4)!==(string)$year){throw new HttpFailure(422,'集計期間を同じ年度の開始日から終了日で指定してください');}
        $budget=self::budget($budget);$items=[];$audit=[];$totals=['income'=>0,'expense'=>0,'income_ytd'=>0,'expense_ytd'=>0];
        foreach(self::ITEMS as $key=>[$label,$kind]){$items[$key]=['label'=>$label,'kind'=>$kind,'period'=>0,'ytd'=>0,'budget'=>$budget[$key]];}
        foreach($ledger as $row){
            $date=self::date($row['date']);$key=$row['item'];if(!isset($items[$key])){throw new HttpFailure(422,'未登録の科目があります');}$amount=self::money($row['amount']);
            if(substr($date,0,4)!==(string)$year||$date>$end){continue;}$items[$key]['ytd']+=$amount;
            if($date>=$start){$items[$key]['period']+=$amount;$audit[]=$row;}
        }
        foreach($items as &$item){
            $item['remaining']=$item['budget']===null?null:$item['budget']-$item['ytd'];
            $item['rate']=$item['budget']===null||$item['budget']===0?null:round(100*$item['ytd']/$item['budget'],2);
            $totals[$item['kind']]+=$item['period'];$totals[$item['kind'].'_ytd']+=$item['ytd'];
        }unset($item);
        usort($audit,static fn($a,$b)=>[$a['date'],$a['key']]<=>[$b['date'],$b['key']]);
        return ['year'=>$year,'start'=>$start,'end'=>$end,'items'=>$items,'income'=>$totals['income'],'expense'=>$totals['expense'],'net'=>$totals['income']-$totals['expense'],'income_ytd'=>$totals['income_ytd'],'expense_ytd'=>$totals['expense_ytd'],'net_ytd'=>$totals['income_ytd']-$totals['expense_ytd'],'audit'=>$audit,'draft'=>true,'retained_through'=>($year+3).'-12-31'];
    }
    /** 資産の差額は比較対象の残高が入力された項目だけ計算。貸借不一致を自動調整しない。 */
    public static function balance(array $current,array $previous):array
    {
        if(array_diff(array_keys($current),array_keys(self::BALANCE))||array_diff(array_keys($previous),array_keys(self::BALANCE))){throw new HttpFailure(422,'貸借の科目を確認してください');}
        $rows=[];$totals=['asset'=>0,'liability'=>0,'equity'=>0];$complete=true;
        foreach(self::BALANCE as $key=>[$label,$kind]){
            $value=$current[$key]??null;$prior=$previous[$key]??null;
            $value=$value===null||$value===''?null:self::money($value,$kind==='equity');$prior=$prior===null||$prior===''?null:self::money($prior,$kind==='equity');
            $rows[$key]=['label'=>$label,'kind'=>$kind,'current'=>$value,'previous'=>$prior,'difference'=>$value===null||$prior===null?null:$value-$prior];
            $complete=$complete&&$value!==null;$totals[$kind]+=$value??0;
        }
        $difference=$totals['asset']-$totals['liability']-$totals['equity'];
        return ['items'=>$rows,'totals'=>$totals,'complete'=>$complete,'balanced'=>$complete&&$difference===0,'difference'=>$difference,'draft'=>true];
    }
    /** 当月・未来月は月末確定の対象にしない。タイムゾーンを日本時間に固定する。 */
    public static function mayClose(string $month,\DateTimeImmutable $now):bool
    {
        self::date($month.'-01');return $month<$now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m');
    }
    /** 表計算ソフトで利用者入力が数式にならないように出力する。 */
    public static function csv(array $rows):string
    {
        $f=fopen('php://temp','w+');fwrite($f,"\xEF\xBB\xBF");
        try{foreach($rows as $row){fputcsv($f,array_map(static fn($v)=>is_string($v)&&preg_match('/^\s*[=+\-@]/u',$v)?"'".$v:$v,$row),',','"','');}rewind($f);return stream_get_contents($f);}finally{fclose($f);}
    }
    /** 保存した年度予算CSVを再読込みする。集計結果や入力途中の金額を予算として取り込まない。 */
    public static function importBudget(string $bytes):array
    {
        $text=\Jmrc\Bank\StrictCsv::text($bytes,'UTF-8');$f=fopen('php://temp','w+');fwrite($f,$text);rewind($f);$year=null;$amounts=[];
        try{
            if(fgetcsv($f,0,',','"','')!==['年度','科目コード','予算額（円）']){throw new HttpFailure(422,'予算保存用CSVの3列を指定してください');}
            while(($r=fgetcsv($f,0,',','"',''))!==false){
                if(count($r)===1&&$r[0]===null){continue;}
                if(count($r)!==3||!isset(self::ITEMS[$r[1]])||array_key_exists($r[1],$amounts)){throw new HttpFailure(422,'予算CSVの列・科目・重複を確認してください');}
                $y=self::year($r[0]);if($year!==null&&$year!==$y){throw new HttpFailure(422,'予算CSVは1年度ずつ読み込んでください');}$year=$y;$amounts[$r[1]]=$r[2]===''?null:self::money($r[2]);
            }
        }finally{fclose($f);}
        if($year===null){throw new HttpFailure(422,'予算がありません');}return ['year'=>$year,'amounts'=>self::budget($amounts)];
    }
}
