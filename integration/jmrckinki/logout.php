<?php
// Path: integration/jmrckinki/logout.php → entry/logout.php
// 機能: GETは確認フォームのみ、POST+CSRFで共通ログアウトする。旧ナビリンクを維持する。
declare(strict_types=1);
require_once __DIR__ . '/common/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_logout();
    header('Location: /entry/login.php', true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new \Jmrc\Auth\HttpFailure(405, 'POST required');
}
require_once __DIR__ . '/common/header.php';
?>
<h1>サインアウト</h1>
<form method="post"><?=csrf_field()?><button type="submit">サインアウトする</button></form>
<?php require_once __DIR__ . '/common/footer.php'; ?>
