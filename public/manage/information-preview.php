<?php
// Path: public/manage/information-preview.php
// 機能: 事務局専用の旧HP情報ページ試作。閲覧だけを許可し、公開・受付・業務DBの変更は行わない。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
$auth = jmrc_auth();
$auth->requireIdentity();
$auth->requirePermission('profiles.correct');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
// 共通ハンドラーのAllow: POSTはこの閲覧専用画面に合わないため、405もここで明示する。
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    exit('この試作は閲覧専用です。公開・保存・送信は行いません。');
}
header('Content-Type: text/html; charset=utf-8');
echo \Jmrc\Content\InformationPreview::render($_GET, $auth->csrfToken());
