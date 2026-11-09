<?php
// Path: tests/auth/dirt_integration.php
// 機能: 最新ダートラ実コードを架空DB・内部HTTPSで検証する。受付の設定変更は隔離DB内だけ。
declare(strict_types=1);
require_once '/srv/web/entry/common/entry_readiness.php';
require_once '/srv/web/entry/common/dirt_trial_fees.php';
$pdo=$db; // 既存entryの受付判定が参照する接続も架空DBに固定する。
$dirtYear=(int)gmdate('Y');
$dirtMember=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=3 AND year=$dirtYear AND member_type='general'")->fetchColumn();
$dirtOfficial=['event_type'=>'ダートラ','event_date'=>"$dirtYear-12-01",'organizer_code'=>'100001'];
$dirtReject=static function(string $name,callable $f):void{try{$f();check($name,false);}catch(InvalidArgumentException|RuntimeException $e){if($e->getMessage()===$name)throw $e;check($name,true);}};
check('ダートラ資格の基準は有効なGeneral',dirt_trial_resolve_membership($db,$dirtOfficial,3,'none')==='kinki');
$db->beginTransaction();
try {
    foreach (["UPDATE portal_memberships SET status='suspended' WHERE id=$dirtMember",
        "UPDATE portal_applications a JOIN portal_memberships m ON m.application_id=a.id SET a.status='submitted' WHERE m.id=$dirtMember",
        "UPDATE portal_invoices i JOIN portal_membership_charges c ON c.invoice_id=i.id SET i.status='cancelled' WHERE c.membership_id=$dirtMember",
        "UPDATE portal_identifiers i JOIN portal_memberships m ON m.general_license_identifier_id=i.id SET i.expires_on=CURRENT_DATE()-INTERVAL 1 DAY WHERE m.id=$dirtMember",
        "UPDATE portal_years SET valid_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE year=$dirtYear",
        "UPDATE portal_club_years SET status='returned' WHERE id=900"] as $i=>$sql) {
        $db->exec('SAVEPOINT dirt_state');$db->exec($sql);
        $dirtReject('ダートラ無効資格の自己申告拒否 '.$i,fn()=>dirt_trial_resolve_membership($db,$dirtOfficial,3,'kinki'));
        check('ダートラ無効資格を会員扱いしない '.$i,dirt_trial_resolve_membership($db,$dirtOfficial,3,'none')==='none');
        $db->exec('ROLLBACK TO SAVEPOINT dirt_state');
    }
    $db->exec("UPDATE portal_years SET source_mode='shadow' WHERE year=$dirtYear");
    $dirtReject('shadowでポータル資格を断定しない',fn()=>dirt_trial_resolve_membership($db,$dirtOfficial,3,'kinki'));
} finally { $db->rollBack(); }
check('他地区の自己申告は近畿資格にしない',dirt_trial_resolve_membership($db,$dirtOfficial,14,'other')==='other');
$dirtReject('近畿未加入の偽装拒否',fn()=>dirt_trial_resolve_membership($db,$dirtOfficial,14,'kinki'));
$dirtReject('未知の区分を拒否',fn()=>dirt_trial_resolve_membership($db,$dirtOfficial,3,'bad'));
// 2026へ開催年を移したテスト大会も2027料金を使う。正式大会の保留を解除しない。
$dirtTest=['event_type'=>'ダートラ','event_date'=>'2026-12-01','organizer_code'=>'DTTEST001'];
check('開催年変更後もテスト大会を維持',dirt_trial_is_test_event($dirtTest)&&dirt_trial_uses_2027_rules($dirtTest)&&!entry_is_preparing($dirtTest));
check('テスト大会のみ自己申告で確認可能',dirt_trial_resolve_membership($db,$dirtTest,14,'kinki')==='kinki');
$db->beginTransaction();
try {
 $db->exec("UPDATE portal_years SET source_mode='shadow' WHERE year=2027");
 check('正式2027大会はshadow中受付保留',entry_is_preparing(['event_type'=>'ダートラ','event_date'=>'2027-03-01','organizer_code'=>'100001']));
 check('shadowでもテスト大会の受付設定は独立',!entry_is_preparing($dirtTest));
} finally {$db->rollBack();}
foreach(['champion','middle','open','closed'] as $category)foreach(['kinki','other','none'] as $district)foreach([false,true] as $student){
 $q=dirt_trial_2027_fee($category,$district,$student,true);$base=dirt_trial_2027_fee($category,$district,$student,false);
 check('ダートラWeb割引は500円 '.$category.$district.(int)$student,$base['total']-$q['total']===500);
}
// 実HTTP入口でも会費加算が拒否され、単なる画面表示で請求・資格を作らない。
$db->exec("INSERT INTO jmrc_events(id,event_type,event_format,event_date,venue,entry_start_date,entry_end_date,max_entrants,organizer_code,organizer_short)
 VALUES(99090,'ダートラ','チャンピオンシリーズ','2027-03-01','隔離',CURRENT_DATE()-INTERVAL 1 DAY,CURRENT_DATE()+INTERVAL 1 DAY,100,'100001','LAB'),
 (99091,'ダートラ','チャンピオンシリーズ','2026-12-01','隔離',CURRENT_DATE()-INTERVAL 1 DAY,CURRENT_DATE()+INTERVAL 1 DAY,100,'DTTEST001','LAB')");
$dirtBrowser=new Browser();$dirtBrowser->login(3);
$dirtBefore=[];foreach(['jmrc_entries','jmrc_entry_fees','portal_invoices','portal_memberships'] as $table)$dirtBefore[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$page=$dirtBrowser->request('/entry/entrant/entries.php?action=create&event_id=99091');
check('最新ダートラ実画面を共通ログインで表示',$page['status']===200&&str_contains($page['body'],'テスト大会の会員区分は自己申告'));
check('ダートラからmanageへ同一ID',identity($dirtBrowser,'entry')['uid']===identity($dirtBrowser)['uid']);
check('ダートラでも事務局権限を昇格しない',$dirtBrowser->request('/manage/preparation.php')['status']===403);
check('ダートラ正式年度の会費加算POST拒否',$dirtBrowser->request('/entry/entrant/entries.php?action=create&event_id=99090',['jmrc_join_member'=>'1','_csrf'=>identity($dirtBrowser)['csrf']])['status']===409);
$dirtBrowser->request('/manage/logout.php',['_csrf'=>identity($dirtBrowser)['csrf']]);
check('manageログアウトでダートラも未認証',$dirtBrowser->request('/entry/entrant/entries.php?action=create&event_id=99091')['status']===302);
foreach($dirtBefore as $table=>$count)check('ダートラ確認で業務行を増やさない '.$table,(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count);
$db->exec('DELETE FROM jmrc_events WHERE id IN (99090,99091)');
