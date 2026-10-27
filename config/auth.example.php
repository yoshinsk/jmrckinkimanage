<?php
// Path: config/auth.example.php
// 機能: Web非公開に配置する認証設定の例。秘密は環境から取得し、既存entry設定を複製しない。
declare(strict_types=1);

return [
    'db' => [
        'dsn' => getenv('JMRC_AUTH_DSN'),
        'user' => getenv('JMRC_AUTH_DB_USER'),
        'password' => getenv('JMRC_AUTH_DB_PASSWORD'),
    ],
    'session' => [
        'name' => 'PHPSESSID',
        'save_path' => getenv('JMRC_AUTH_SESSION_PATH'),
        'lifetime' => 2592000,
        'secure' => true,
    ],
];
