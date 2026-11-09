<?php
// Path: tests/auth/preparation_http.php
// 機能: 隔離DBで運用準備の実参照・認可・時刻境界・非更新性を検証。実サーバーや実個人情報は使わない。
declare(strict_types=1);
require_once '/srv/auth/src/Operations/PreparationService.php';
$runtime=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','auth_runtime','lab_runtime_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$runtime->exec("SET time_zone='+00:00'");
$op=new \Jmrc\Operations\PreparationService($runtime,[]);
$office=new Browser();$office->login(7);
$entrant=new Browser();$entrant->login(1);
$anonymous=new Browser();
check('運用準備未認証は401',$anonymous->request('/manage/preparation.php')['status']===401);
check('旧entry管理者に運用準備を開放しない',$entrant->request('/manage/preparation.php')['status']===403);
$tables=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('audit_logs','portal_audit_events') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$fingerprint=static fn()=> $db->query('CHECKSUM TABLE '.implode(',',array_map(fn($t)=>'`'.$t.'`',$tables)))->fetchAll(PDO::FETCH_ASSOC);
$initial=$fingerprint();
foreach (['overview','club','event','privacy','membership'] as $section) {
    check('運用準備の確認画面 '.$section,$office->request('/manage/preparation.php?section='.$section)['status']===200);
}
check('確認画面のPOSTを拒否',$office->request('/manage/preparation.php',['action'=>'open'])['status']===405);
check('範囲外の項目・配列入力を拒否',$office->request('/manage/preparation.php?section[]=overview')['status']===422);
check('負数の確認対象を拒否',$office->request('/manage/preparation.php?section=privacy&id=-1')['status']===422);
check('存在しない対象を拒否',$office->request('/manage/preparation.php?section=privacy&id=999999999')['status']===404);
$overview=$op->overview(7);
check('秘密設定を返さない',!str_contains(json_encode($overview),'lab_runtime_only') && !array_key_exists('data_key',$overview));
check('新機能の未設定は受付停止',$overview['flags']['staff_role_requests_enabled']===false && !$overview['opening_authorized']);
$plan=$op->clubPlan(7,1,2027,'organization','supporting','new');
check('賛助登録団体の年会費・入会金を別明細で確認',array_column($plan['lines'],'amount_yen')===[10000,10000] && $plan['quote']['total_yen']===20000 && !$plan['can_issue']);
check('再加入を新規に読み替えない',count($op->clubPlan(7,1,2027,'organization','supporting','rejoin')['lines'])===1);
check('履歴から新規や数字コードを推定しない',$plan['history_review_required'] && !$plan['code_mapping_review_required'] && $plan['membership_mapping_review_required'] && $plan['quote']['code_prefix']==='6');
$data=$op->personalDataInventory(7,3);
check('個人情報の内容・暗号文を返さず件数だけ棚卸し',count($data['items'])===9 && !$data['execution_enabled'] && !str_contains(json_encode($data),'value_cipher'));
check('共通ログインは保持に分類',count(array_filter($data['items'],fn($i)=>$i['label']==='共通ログイン用アカウント' && $i['scope']==='keep' && $i['count']===1))===1);
check('運用準備の読取で全業務表が不変',$initial===$fingerprint());

// 既存イベントと明示的な対応を作るfixtureは最後に元へ戻す。期間は任意の過去/未来の日付で固定する。
$eventId=99001;$eventPortal=99001;$cy=(int)$db->query('SELECT id FROM portal_club_years WHERE club_id=1 ORDER BY year DESC LIMIT 1')->fetchColumn();
$cyYear=(int)$db->query('SELECT year FROM portal_club_years WHERE id='.$cy)->fetchColumn();
$oldLink=$db->query('SELECT * FROM portal_organizer_links WHERE legacy_club_user_id=2 AND portal_club_id=1')->fetch(PDO::FETCH_ASSOC);
$yearModes=$db->query('SELECT year,source_mode,updated_at FROM portal_years')->fetchAll(PDO::FETCH_ASSOC);
try {
    $db->exec("INSERT INTO jmrc_events(id,event_type,event_format,event_date,venue,entry_start_date,entry_end_date,max_entrants,organizer_code,organizer_short)
        VALUES($eventId,'LAB','LAB','2027-06-20','LAB','2027-06-01','2027-06-13',10,'100001','LAB')");
    check('未対応のイベントは期間内でも保留',$op->eventPlan(7,$eventId,new DateTimeImmutable('2027-06-20T12:00:00+09:00'))['window']==='unconfirmed');
    $db->exec("INSERT INTO portal_events(id,legacy_event_id,organizer_club_year_id,year,title,starts_at,ends_at,status)
        VALUES($eventPortal,$eventId,$cy,$cyYear,'架空接続試験','2027-06-19 15:00:00','2027-06-20 15:00:00','published')");
    if (!$oldLink) { $db->exec('INSERT INTO portal_organizer_links(legacy_club_user_id,portal_club_id,verified_by,verified_at) VALUES(2,1,7,UTC_TIMESTAMP(6))'); }
    foreach (['2027-06-13T23:59:59+09:00'=>'not_open','2027-06-14T00:00:00+09:00'=>'open','2027-06-27T23:59:59+09:00'=>'open','2027-06-28T00:00:00+09:00'=>'expired'] as $time=>$expected) {
        $r=$op->eventPlan(7,$eventId,new DateTimeImmutable($time));check('既存日付からの照会期間 '.$time,$r['window']===$expected && !$r['organizer_access_enabled']);
    }
    $db->exec("UPDATE portal_events SET ends_at='2027-06-21 15:00:00' WHERE id=$eventPortal");
    check('複数日や終了日不一致を自動接続しない',$op->eventPlan(7,$eventId)['window']==='unconfirmed');
    $db->exec("UPDATE portal_years SET source_mode='shadow'");
    $latest=$op->latestMembership(7,3);
    check('年度未切替は未加入ではなく確認不能',$latest['member_active']===null && $latest['regular_club_active']===null && !$latest['points_recalculated']);
} finally {
    $db->exec("DELETE FROM portal_events WHERE id=$eventPortal;DELETE FROM jmrc_events WHERE id=$eventId");
    if (!$oldLink) { $db->exec('DELETE FROM portal_organizer_links WHERE legacy_club_user_id=2 AND portal_club_id=1'); }
    foreach ($yearModes as $row) { $db->prepare('UPDATE portal_years SET source_mode=?,updated_at=? WHERE year=?')->execute([$row['source_mode'],$row['updated_at'],$row['year']]); }
}
check('確認用fixtureの後に全業務表を復元',$initial===$fingerprint());
