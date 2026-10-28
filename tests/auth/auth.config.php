<?php
// Path: tests/auth/auth.config.php
// 機能: network=internalの隔離コンテナ専用設定。実在アカウントの資格情報ではない。
return [
    'data_key'=>base64_encode(str_repeat('L',32)), // 隔離環境だけの架空鍵。本番へ転用しない。
    'card_public_url'=>'https://web/manage/card-check.php',
    'db'=>['dsn'=>'mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4',
        'user'=>'auth_runtime','password'=>'lab_runtime_only'],
    'session'=>['save_path'=>'/srv/sessions','name'=>'PHPSESSID','lifetime'=>2592000,'secure'=>true],
];
