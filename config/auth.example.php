<?php
// Path: config/auth.example.php
// 機能: Web非公開に配置する認証設定の例。秘密は環境から取得し、既存entry設定を複製しない。
declare(strict_types=1);

return [
    'data_key' => getenv('JMRC_PORTAL_DATA_KEY') ?: null, // Base64形式の32バイト鍵。口座情報のAES-GCM保管用。
    'card_public_url' => getenv('JMRC_CARD_PUBLIC_URL') ?: null, // https://gymkinki.com/manage/card-check.php
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
