<?php
// Path: tests/auth/accounting_answers.php
// 機能: 11/15回答の会計・任期・クラブ入力を架空の境界値で検証する。実データや外部送信は使わない。
declare(strict_types=1);
use Jmrc\Operations\AccountingWorkflow as Flow;
use Jmrc\Operations\AccountingReview as Review;
use Jmrc\Operations\AccountingPolicy as Policy;
use Jmrc\Operations\MaterialsReview as Materials;
use Jmrc\Bank\PaymentCoreCsv as Payment;
(static function()use($reject):void{
    $p=new Payment();$csv=Review::csv([Payment::HEADERS,['架空','','','カード',2000,100,'12/31','カクウ'],['架空','','','カード',2000,100,'1/2','カクウ']]);
    $r=$p->parse($csv,'UTF-8',null,null,new DateTimeImmutable('2027-01-05T00:00:00+09:00'));
    check('1月確認は12月分だけ前年に戻す',$r['rows'][0]['date']==='2026-12-31'&&$r['rows'][1]['date']==='2027-01-02'&&$r['year_source']==='confirmation_date_rule');
    check('会費本体と人数未確認を区別',$r['rows'][0]['principal_yen']===2000&&$r['rows'][0]['persons']===null&&!$r['auto_confirm_allowed']);
    $jan=Review::csv([Payment::HEADERS,['架空','','','カード',500,600,'12/31','カクウ']]);
    check('手数料と会費の大小関係を推定しない',$p->parse($jan,'UTF-8',null,null,new DateTimeImmutable('2026-12-31T15:00:00Z'))['rows'][0]['date']==='2026-12-31');
    $reject('未来の月日を前年と推測しない',fn()=>$p->parse($jan,'UTF-8',null,null,new DateTimeImmutable('2026-02-10T00:00:00+09:00')));
    $unknown=['receipt_id'=>'a','item'=>'member_fee','amount'=>2000,'paid_on'=>'2027-01-02','confirmed_at'=>'2027-01-05 10:00:00'];
    $reject('人数が不明なら自動連携しない',fn()=>Policy::receipt($unknown),409);
    $reject('複数名分は手動確認へ',fn()=>Policy::receipt($unknown+['persons'=>2]),409);
    $reject('クラブ費を自動連携しない',fn()=>Review::synchronize([],[['id'=>'club','item'=>'club_fee','status'=>'confirmed']]));
    $reject('個人会費の手入力重複を拒否',fn()=>Review::entry(['item'=>'member_fee','date'=>'2027-01-01','amount'=>2000],'now','manual:a'));
    $c=Materials::club(Materials::sampleClub()+['directory_phone'=>'不要な掲載欄','jaf_registration'=>'不要な添付メモ']);
    check('任意会社欄は空欄可で掲載項目と添付メモは保存しない',$c['company']===''&&!isset($c['directory_phone'],$c['jaf_registration']));
    $reject('必要な住所が空なら拒否',fn()=>Materials::club(array_replace(Materials::sampleClub(),['rep_address'=>''])));
    $term=Flow::term(['term_year'=>2027,'term_role'=>'chief','term_name'=>'架空担当','term_start'=>'2027-04-01','term_end'=>'2027-09-30']);
    check('途中任期と翌日期限切れ',$term['nomination_deadline']==='2026-11-30'&&Flow::activeOn($term,'2027-09-30')&&!Flow::activeOn($term,'2027-10-01')&&!$term['grants_permission']);
    $reject('任期の年度越えを拒否',fn()=>Flow::term(['term_year'=>2027,'term_role'=>'chief','term_name'=>'架空','term_start'=>'2027-01-01','term_end'=>'2028-01-01']));
    $reject('部会予算の合計上限',fn()=>Flow::departmentTotal(['甲'=>1000000000,'乙'=>1]));
    $reject('部会CSVと合計不一致を拒否',fn()=>Review::importBudget(Review::csv([['年度','科目コード','予算額（円）'],[2027,'contribution',100],[2027,'department:甲',200]])));
    $original=Review::entry(['item'=>'club_fee','date'=>'2027-01-01','amount'=>2000],'now','manual:original');
    $refund=Flow::refund([$original],[],['refund_source'=>$original['key'],'refund_date'=>'2027-01-02','refund_amount'=>2000],'refund:1');
    check('元明細を残し返金全額を負数で保持',$refund['original']===$original&&$refund['amount']===-2000&&!$refund['included_in_totals']);
    $reject('返金の二重入力と超過を拒否',fn()=>Flow::refund([$original],[$refund],['refund_source'=>$original['key'],'refund_date'=>'2027-01-03','refund_amount'=>1],'refund:2'));
    $now=Materials::initial(7);$old=$now;$old['schema']=1;$old['budgets'][2028]=Review::budget(['meeting'=>1234]);
    unset($old['departments'],$old['budget_status'],$old['month_approved'],$old['refunds'],$old['terms']);$migrated=Materials::upgrade($old);
    check('一時データ更新でも予算と手入力を保持',$migrated['budgets'][2028]['meeting']===1234&&$migrated['ledger']===$now['ledger']&&$migrated['schema']===2);
    $deadline=Policy::closingDeadline('2025-12',['2026-01-01'],2026,true);
    check('銀行休業日候補は年末年始を除くが独自繰下げはしない',$deadline->format('Y-m-d H:i')==='2026-01-06 23:59');
})();
