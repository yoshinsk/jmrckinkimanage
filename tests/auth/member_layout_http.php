<?php
// Path: tests/auth/member_layout_http.php
// 機能: 隔離DBで受付保留時の表示・直POST拒否と、既存の申請・請求・入金が不変であることを確認する。
declare(strict_types=1);
$layoutMember=new Browser();$layoutMember->login(3);$layoutOffice=new Browser();$layoutOffice->login(7);
$layoutYears=$db->query('SELECT year,source_mode,member_self_open_at FROM portal_years')->fetchAll(PDO::FETCH_ASSOC);
$layoutState=static function() use($db): array {
    $out=[];foreach(['portal_applications','portal_memberships','portal_affiliations','portal_invoices','portal_payments','portal_allocations'] as $table){$out[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}return $out;
};
$layoutBefore=$layoutState();
try {
    $db->exec("UPDATE portal_years SET source_mode='shadow'");
    $html=$layoutMember->request('/manage/memberships.php?submitted=1')['body'];
    check('保留中の個人申請はフォームと受付済み表示を出さない',!str_contains($html,'name="request_key"') && !str_contains($html,'申請を受け付けました。') && str_contains($html,'受付を行っていません'));
    $html=$layoutOffice->request('/manage/billing.php')['body'];
    check('保留中は事務局にも入金操作を表示しない',!str_contains($html,'name="action"') && str_contains($html,'会費・入金の受付は準備中'));
    foreach(['issue','confirm','reverse','reallocate','approve'] as $action){check('会費保留中の直POST拒否 '.$action,billPost($layoutOffice,$action,1,['amount'=>2000,'payment'=>1,'reference'=>'LAYOUT-HOLD','reason'=>'架空保留検証'])['status']===409);}
    check('保留中もCSRF欠落は拒否',$layoutOffice->request('/manage/billing.php?format=json',['action'=>'confirm','id'=>1])['status']===400);
    check('保留中の新規本人申請は既存サービスで拒否',submitMember($layoutMember,['member_type'=>'general','year'=>(int)gmdate('Y')+1,'club_year_id'=>101,'identifier_id'=>101])['status']===422);
    check('受付保留の確認で既存の申請・入金・承認を更新しない',$layoutState()===$layoutBefore);
    // 資格の参照先が切替済みでも、本人受付の開始前にはフォームを表示しない。
    $db->exec("UPDATE portal_years SET source_mode='portal',member_self_open_at=UTC_TIMESTAMP()+INTERVAL 5 YEAR");
    $html=$layoutMember->request('/manage/memberships.php')['body'];
    check('本人受付の開始前は申請フォームなし',!str_contains($html,'name="request_key"') && str_contains($html,'受付を行っていません'));
} finally {
    $restore=$db->prepare('UPDATE portal_years SET source_mode=?,member_self_open_at=? WHERE year=?');
    foreach($layoutYears as $year){$restore->execute([$year['source_mode'],$year['member_self_open_at'],$year['year']]);}
}
