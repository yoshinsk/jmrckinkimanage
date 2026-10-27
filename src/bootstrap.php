<?php
// Path: src/bootstrap.php
// 機能: Web非公開の設定から共通DB/認証を一度だけ初期化し、障害時に閉じて停止する。
declare(strict_types=1);

require_once __DIR__ . '/Auth/HttpFailure.php';
require_once __DIR__ . '/Auth/SharedAuth.php';

set_exception_handler(static function (Throwable $error): void {
    $status = $error instanceof \Jmrc\Auth\HttpFailure ? $error->status : 503;
    http_response_code($status);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    if ($status === 405) {
        header('Allow: POST');
    }
    // 例外の詳細には設定・DB接続情報が含まれ得るため、公開レスポンスへ出さない。
    echo $error instanceof \Jmrc\Auth\HttpFailure ? $error->getMessage() : 'Authentication service unavailable';
});

function jmrc_auth(): \Jmrc\Auth\SharedAuth
{
    static $auth = null;
    if ($auth === null) {
        $configPath = getenv('JMRC_AUTH_CONFIG');
        if (!$configPath || !is_file($configPath)) {
            throw new RuntimeException('External auth config required');
        }
        $config = require $configPath;
        $db = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // 既存DBのSYSTEM時刻と分離し、この接続だけをUTCにする。
        $db->exec("SET time_zone='+00:00'");
        $auth = new \Jmrc\Auth\SharedAuth($db, $config['session']);
    }
    return $auth;
}
