<?php
// Path: public/manage/_card-public.php
// 機能: 匿名QR照会用の初期化。ログインセッションやCookieを作らず、キャッシュ・外部送信を制限する。
declare(strict_types=1);
$path=getenv('JMRC_AUTH_BOOTSTRAP') ?: '/var/www/vhosts/gymkinki.com/private/jmrc-portal/current/src/bootstrap.php';
if (!$path || !is_file($path)) {http_response_code(503);exit('Service unavailable');}
require_once $path;
header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
