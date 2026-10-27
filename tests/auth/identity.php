<?php
// Path: tests/auth/identity.php → 隔離コピーentry/identity.php / manage/identity.php
// 機能: HTTP検証だけでID/旧ロール/CSRFの整合を観測する。本番公開用ではない。
declare(strict_types=1);
if (str_starts_with($_SERVER['SCRIPT_NAME'], '/entry/')) {
    require __DIR__ . '/common/auth.php';
} else {
    require __DIR__ . '/_bootstrap.php';
}
$user = jmrc_auth()->requireIdentity();
header('Content-Type: application/json');
echo json_encode(['uid'=>$user['id'], 'rid'=>$user['role_id'], 'verified'=>$_SESSION['verified'],
    'csrf'=>jmrc_auth()->csrfToken(), 'sapi'=>PHP_SAPI, 'php'=>PHP_VERSION]);
