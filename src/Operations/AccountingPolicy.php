<?php
// Path: src/Operations/AccountingPolicy.php
// 機能: F01・F03・F04・F05の確定した会計条件を提供する。権限付与・自動締め・削除は実行しない。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
final class AccountingPolicy
{
    /** 入金ごとの識別子を使い、対象会員年度と会計計上年を区別する。未確認の入金は計上できない。 */
    public static function receipt(array $in):array
    {
        $paid=$in['paid_on']??null;$confirmed=$in['confirmed_at']??null;
        if(!is_string($paid)||!is_string($confirmed)){throw new HttpFailure(422,'実入金日と入金確認日時が必要です');}
        AccountingReview::date($paid);
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$confirmed,new \DateTimeZone('Asia/Tokyo'));
        if(!$d||$d->format('Y-m-d H:i:s')!==$confirmed||substr($confirmed,0,10)<$paid){throw new HttpFailure(422,'入金確認日時を日本時間で確認してください');}
        return ['id'=>$in['receipt_id']??null,'item'=>$in['item']??null,'amount'=>$in['amount']??null,'status'=>'confirmed','date'=>$paid,'registered_at'=>$confirmed.' JST','detail'=>'','memo'=>''];
    }
    /** 翌月の土日・休日を除く2日目23:59。休日一覧の対象年を呼出側が確認できない場合は計算しない。 */
    public static function closingDeadline(string $month,array $holidays,int $calendarYear):\DateTimeImmutable
    {
        AccountingReview::date($month.'-01');$date=new \DateTimeImmutable($month.'-01',new \DateTimeZone('Asia/Tokyo'));$date=$date->modify('first day of next month');
        if((int)$date->format('Y')!==$calendarYear){throw new HttpFailure(422,'翌月の休日一覧が未確認です');}
        foreach($holidays as $day){if(!is_string($day)||substr(AccountingReview::date($day),0,4)!==(string)$calendarYear){throw new HttpFailure(422,'休日一覧の対象年を確認してください');}}
        $found=0;for($i=0;$i<31;$i++,$date=$date->modify('+1 day')){
            if((int)$date->format('N')<6&&!in_array($date->format('Y-m-d'),$holidays,true)&&++$found===2){return $date->setTime(23,59);}
        }throw new HttpFailure(422,'翌月の営業日を確認できません');
    }
}
