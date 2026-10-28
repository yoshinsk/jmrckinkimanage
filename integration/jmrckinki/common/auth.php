<?php
// Path: integration/jmrckinki/common/auth.php → entry/common/auth.php
// 機能: 既存auth_* APIを共通認証へ橋渡しする。隔離コピーへの適用用、本番未適用。
declare(strict_types=1);

$authBootstrap = getenv('JMRC_AUTH_BOOTSTRAP');
if (!$authBootstrap || !is_file($authBootstrap)) {
    http_response_code(503);
    exit('Authentication service unavailable');
}
require_once $authBootstrap;
jmrc_auth();
// 既存画面が使うpdo/functionsは従来どおり維持。認証設定はこのconfigに依存しない。
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/portal_billing.php';
portal_guard_legacy_membership_fee();

function auth_login(string $email, string $password): bool { return jmrc_auth()->login($email, $password); }
function auth_user(): ?array { return jmrc_auth()->identity(); }
function auth_user_id(): ?int { return auth_user()['id'] ?? null; }
function auth_role_id(): ?int { return auth_user()['role_id'] ?? null; }
function is_role(string $name): bool
{
    return auth_role_id() === (['JMRC_ADMIN'=>1, 'CLUB'=>2, 'ENTRANT'=>3][$name] ?? 0);
}
function auth_is_jmrc_admin(): bool { return is_role('JMRC_ADMIN'); }
function _auth_login_url(): string { return '/entry/login.php'; }
function auth_require_login(): void
{
    if (!auth_user()) {
        header('Location: /entry/login.php');
        exit;
    }
}
function auth_require_jmrc_admin(): void
{
    auth_require_login();
    if (!auth_is_jmrc_admin()) {
        throw new \Jmrc\Auth\HttpFailure(403, 'Forbidden');
    }
}
function auth_logout(): void
{
    jmrc_auth()->logout($_SERVER['REQUEST_METHOD'], $_POST['_csrf'] ?? null);
}
