<?php
// Path: integration/jmrckinki/common/csrf.php → entry/common/csrf.php
// 機能: 既存CSRF APIを共通セッションへ委譲し、空/欠落トークンを拒否する。
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function csrf_token(): string { return jmrc_auth()->csrfToken(); }
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_verify(): void { jmrc_auth()->verifyCsrf($_POST['_csrf'] ?? null); }
