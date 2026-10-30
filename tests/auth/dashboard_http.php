<?php
// Path: tests/auth/dashboard_http.php
// 機能: 隔離HTTPでマイページの権限・保留導線・エスケープ・GETの非更新性を確認する。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
function dashboardLink(string $html, string $file): bool {
    return str_contains($html, 'href="/manage/'.$file.'"');
}
$dOffice = new Browser(); $dOffice->login(7);
$dClub = new Browser(); $dClub->login(2);
$dAdmin = new Browser(); $dAdmin->login(1);
$dInvalid = new Browser(); $dInvalid->login(8);
$counts = static fn(): array => $db->query('SELECT (SELECT COUNT(*) FROM portal_applications), (SELECT COUNT(*) FROM portal_invoices), (SELECT COUNT(*) FROM portal_payments)')->fetch(PDO::FETCH_NUM);
$beforeDashboard = $counts();
$html = $dOffice->request('/manage/')['body'];
check('マイページの事務局専用メニュー', dashboardLink($html,'bank-settings.php') && dashboardLink($html,'profile-correct.php'));
check('マイページの事務局クラブ登録と会計', dashboardLink($html,'clubs.php') && dashboardLink($html,'bank.php'));
check('事務局globalだけではTemporary主催承認を出さない', !dashboardLink($html,'temporary-approve.php'));
$html = $dClub->request('/manage/')['body'];
check('クラブscopeの年度登録とTemporaryメニュー', dashboardLink($html,'clubs.php') && dashboardLink($html,'temporary-approve.php'));
check('クラブ代表へ事務局設定を表示しない', !dashboardLink($html,'bank-settings.php') && !dashboardLink($html,'profile-correct.php') && !dashboardLink($html,'bank.php'));
$html = $dAdmin->request('/manage/')['body'];
check('旧管理者ロールだけではポータル管理メニューなし', !dashboardLink($html,'clubs.php') && !dashboardLink($html,'bank-settings.php') && !dashboardLink($html,'bank.php'));
check('会員メニューは切替済み年度で表示', dashboardLink($html,'memberships.php') && dashboardLink($html,'card.php') && dashboardLink($html,'billing.php'));
check('マイページ個人表示はno-store', (bool)array_filter($dAdmin->lastHeaders, static fn(string $h): bool => stripos($h,'Cache-Control:')===0 && str_contains($h,'no-store')));
$html = $dInvalid->request('/manage/')['body'];
check('誤ったglobalのクラブ代表に管理導線なし', !dashboardLink($html,'clubs.php') && !dashboardLink($html,'temporary-approve.php'));

// fixtureの変更は本番へ持ち込まず、他の試験に影響しないようfinallyで復元する。
$years = $db->query('SELECT year,source_mode FROM portal_years')->fetchAll(PDO::FETCH_ASSOC);
$email = $db->query('SELECT email FROM users WHERE id=1')->fetchColumn();
$financeStatus = $db->query('SELECT status FROM portal_user_role_grants WHERE user_id=7 AND role_id=3')->fetchColumn();
try {
    // 先行する会計試験で取り消したfixture権限を、この独立権限の確認中だけ有効にする。
    $db->exec("UPDATE portal_user_role_grants SET status='active' WHERE user_id=7 AND role_id=3");
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=7 AND role_id=1");
    $html = $dOffice->request('/manage/')['body'];
    check('マイページに事務局権限取消を即反映', !dashboardLink($html,'bank-settings.php') && !dashboardLink($html,'profile-correct.php') && !dashboardLink($html,'clubs.php'));
    check('事務局取消後も独立した財務権限を保持', dashboardLink($html,'bank.php'));
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=2 AND role_id=2");
    $html = $dClub->request('/manage/')['body'];
    check('クラブ権限取消で年度登録と承認メニューを除外', !dashboardLink($html,'clubs.php') && !dashboardLink($html,'temporary-approve.php'));
    $db->exec("UPDATE portal_years SET source_mode='shadow'");
    $html = $dAdmin->request('/manage/')['body'];
    check('shadowだけの時は登録・会計・会員証を準備中にする', !dashboardLink($html,'memberships.php') && !dashboardLink($html,'billing.php') && !dashboardLink($html,'card.php') && str_contains($html,'準備中の機能'));
    $untrusted = '<script>window.dashboardInjection=1</script>@example.invalid';
    $db->prepare('UPDATE users SET email=? WHERE id=1')->execute([$untrusted]);
    $html = $dAdmin->request('/manage/')['body'];
    check('アカウント表示をHTMLとして実行させない', !str_contains($html,$untrusted) && str_contains($html,htmlspecialchars($untrusted,ENT_QUOTES,'UTF-8')));
    check('マイページ閲覧で申請・請求・入金を作らない', $counts()===$beforeDashboard);
} finally {
    $db->exec('UPDATE portal_user_role_grants SET revoked_at=NULL WHERE (user_id=7 AND role_id=1) OR (user_id=2 AND role_id=2)');
    $db->prepare('UPDATE portal_user_role_grants SET status=? WHERE user_id=7 AND role_id=3')->execute([$financeStatus]);
    $restoreYear=$db->prepare('UPDATE portal_years SET source_mode=? WHERE year=?');
    foreach ($years as $year) { $restoreYear->execute([$year['source_mode'],$year['year']]); }
    $db->prepare('UPDATE users SET email=? WHERE id=1')->execute([$email]);
}
