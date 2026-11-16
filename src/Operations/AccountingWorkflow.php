<?php
// Path: src/Operations/AccountingWorkflow.php
// 機能: F13～F17の予算・部会別配分・任期・返金表示を確認用配列で扱う。実権限や正式会計には接続しない。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
final class AccountingWorkflow
{
    /** 部会名は提供された名称を入力する。競技種別から部会を推定しない。 */
    public static function department(mixed $name,mixed $amount):array
    {
        if(!is_string($name)||trim($name)===''||mb_strlen($name)>80||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name)){throw new HttpFailure(422,'部会名を80文字以内で入力してください');}
        return ['name'=>trim($name),'amount'=>AccountingReview::money($amount)];
    }
    /** 確認用の役を明示して操作例を試す。ログイン者の実権限を昇格する判定ではない。 */
    public static function requireRole(array $in,string $required):void
    {
        if(($in['review_role']??null)!==$required){throw new HttpFailure(403,$required==='chief'?'この操作例は事務局長の担当です':'この操作例は事務局の担当です');}
    }
    /** 年度途中の補正を作らず、承認後は編集と再取込を止める。 */
    public static function editable(array $state,int $year):void
    {
        if(($state['budget_status'][$year]??'draft')!=='draft'){throw new HttpFailure(409,'予算は承認待ちまたは承認済みです。年度途中の補正は行いません');}
    }
    /** 複数部会の合計と親科目を一致させ、同じ予算を二重に足さない。 */
    public static function departmentTotal(array $departments):int
    {
        if(count($departments)>40){throw new HttpFailure(422,'確認用の部会は40件以内です');}
        $total=0;foreach($departments as $name=>$amount){$total+=self::department($name,$amount)['amount'];}
        return AccountingReview::money($total);
    }
    /** 年度末までの任期を保存例にする。途中の就任・退任も日付で表し、実アカウントには付与しない。 */
    public static function term(array $in):array
    {
        $year=AccountingReview::year($in['term_year']??null);$role=$in['term_role']??null;$name=$in['term_name']??null;
        if(!in_array($role,['office','chief'],true)||!is_string($name)||trim($name)===''||mb_strlen($name)>80||preg_match('/[\x00-\x1f\x7f]/',$name)){throw new HttpFailure(422,'確認用の担当者名と役職を入力してください');}
        $start=$in['term_start']??$year.'-01-01';$end=$in['term_end']??$year.'-12-31';
        if(!is_string($start)||!is_string($end)){throw new HttpFailure(422,'任期の日付を確認してください');}
        AccountingReview::date($start);AccountingReview::date($end);
        if(substr($start,0,4)!==(string)$year||substr($end,0,4)!==(string)$year||$start>$end){throw new HttpFailure(422,'任期は同じ年度内の就任日から退任日で入力してください');}
        return ['year'=>$year,'role'=>$role,'name'=>trim($name),'start'=>$start,'end'=>$end,'nomination_deadline'=>($year-1).'-11-30','grants_permission'=>false];
    }
    /** 任期終了日の翌日は権限の対象外とする確認用判定。 */
    public static function activeOn(array $term,string $date):bool
    {
        AccountingReview::date($date);return $term['start']<=$date&&$date<=$term['end'];
    }
    /** F14は元明細を残し返金全額を負数で別項目へ保持。集計符号はF19待ちのため集計外とする。 */
    public static function refund(array $ledger,array $refunds,array $in,string $key):array
    {
        $source=$in['refund_source']??null;$date=$in['refund_date']??null;
        if(!is_string($source)||!is_string($date)){throw new HttpFailure(422,'元の収入と返金日を指定してください');}
        $matches=array_values(array_filter($ledger,static fn($r)=>$r['key']===$source));
        if(count($matches)!==1||AccountingReview::ITEMS[$matches[0]['item']][1]!=='income'){throw new HttpFailure(422,'元の収入明細を選んでください');}
        $original=$matches[0];$amount=AccountingReview::money($in['refund_amount']??null);AccountingReview::date($date);
        $already=0;foreach($refunds as $r){if($r['source_key']===$source){$already+=abs($r['amount']);}}
        if($amount<=0||$already+$amount>$original['amount']||$date<$original['date']||count($refunds)>=2000){throw new HttpFailure(422,'返金日・金額・既存の返金を確認してください');}
        return ['key'=>$key,'source_key'=>$source,'original'=>$original,'date'=>$date,'amount'=>-$amount,'kind'=>'expense','included_in_totals'=>false];
    }
}
