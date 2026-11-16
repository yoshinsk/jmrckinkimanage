<?php
// Path: public/manage/_bootstrap.php
// 機能: manageの公開入口からWeb非公開の共通認証を読み込む。
declare(strict_types=1);
$authBootstrap = getenv('JMRC_AUTH_BOOTSTRAP') ?: '/var/www/vhosts/gymkinki.com/private/jmrc-portal/current/src/bootstrap.php';
if (!$authBootstrap || !is_file($authBootstrap)) {
    http_response_code(503);
    exit('Authentication service unavailable');
}
require_once $authBootstrap;
// 本人照合・CSRF・業務検証は各処理で継続。CSVアップロード原本と認証用照合値は変換しない。
$_POST=\Jmrc\Input\TextInput::http($_POST);
$_GET=\Jmrc\Input\TextInput::http($_GET);
jmrc_auth();
