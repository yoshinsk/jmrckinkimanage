<?php
// Path: src/bootstrap.php
// 機能: Web非公開の設定から共通DB/認証を一度だけ初期化し、障害時に閉じて停止する。
declare(strict_types=1);

require_once __DIR__ . '/Auth/HttpFailure.php';
require_once __DIR__ . '/Auth/SharedAuth.php';
require_once __DIR__ . '/Membership/MembershipService.php';
require_once __DIR__ . '/Billing/Eligibility.php';
require_once __DIR__ . '/Billing/BillingService.php';
require_once __DIR__ . '/Club/ClubEligibility.php';
require_once __DIR__ . '/Club/ClubService.php';
require_once __DIR__ . '/Card/SecretBox.php';
require_once __DIR__ . '/Card/CardService.php';
require_once __DIR__ . '/License/LicenseService.php';
require_once __DIR__ . '/License/LicenseMigration.php';
require_once __DIR__ . '/Insurance/InsuranceWorkbook.php';
require_once __DIR__ . '/Insurance/InsuranceService.php';
require_once __DIR__ . '/Bank/YuchoCsv.php';
require_once __DIR__ . '/Bank/BankService.php';
require_once __DIR__ . '/Bank/BankMasterService.php';

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

/** 設定を検証して一度だけ読む。パス不備や異なる読込順で警告/設定重複を発生させない。 */
function jmrc_auth_config(): array
{
    static $config = null;
    if ($config === null) {
        $configPath = getenv('JMRC_AUTH_CONFIG');
        if (!$configPath || !is_file($configPath)) {
            throw new RuntimeException('External auth config required');
        }
        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('Auth config must return an array');
        }
    }
    return $config;
}

/** 共通DBを返す。認証と業務サービスのトランザクション接続を分断しない。 */
function jmrc_db(): PDO
{
    static $db = null;
    if ($db === null) {
        $config = jmrc_auth_config();
        $db = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // 既存DBのSYSTEM時刻と分離し、この接続だけをUTCにする。
        $db->exec("SET time_zone='+00:00'");
    }
    return $db;
}

/** セッションの初期化は一度だけ行い、従来の互換APIへも同じインスタンスを返す。 */
function jmrc_auth(): \Jmrc\Auth\SharedAuth
{
    static $auth = null;
    if ($auth === null) {
        $config = jmrc_auth_config();
        $auth = new \Jmrc\Auth\SharedAuth(jmrc_db(), $config['session']);
    }
    return $auth;
}
