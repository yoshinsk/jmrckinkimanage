<?php
// Path: tests/auth/permission.php → 隔離コピーmanage/permission.php
// 機能: 対象クラブ付き認可とPOST CSRFをHTTPから検証する。本番公開用ではない。
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$id = filter_var($_GET['club_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (isset($_GET['club_id']) && $id === false) {
    throw new \Jmrc\Auth\HttpFailure(400, 'Invalid club');
}
$permission = $_GET['permission'] ?? 'club.members.read';
if (!is_string($permission)) {
    throw new \Jmrc\Auth\HttpFailure(400, 'Invalid permission');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf'] ?? null);
}
jmrc_auth()->requirePermission($permission, isset($_GET['club_id']) ? (int)$id : null);
echo 'allowed';
