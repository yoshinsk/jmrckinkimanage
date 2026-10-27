<?php
// Path: public/manage/_bootstrap.php
// 機能: manageの公開入口からWeb非公開の共通認証を読み込む。
declare(strict_types=1);
$authBootstrap = getenv('JMRC_AUTH_BOOTSTRAP');
if (!$authBootstrap || !is_file($authBootstrap)) {
    http_response_code(503);
    exit('Authentication service unavailable');
}
require_once $authBootstrap;
jmrc_auth();
