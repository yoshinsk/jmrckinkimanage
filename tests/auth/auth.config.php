<?php
// Path: tests/auth/auth.config.php
// 機能: network=internalの隔離コンテナ専用設定。実在アカウントの資格情報ではない。
return [
    'db'=>['dsn'=>'mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4',
        'user'=>'auth_runtime','password'=>'lab_runtime_only'],
    'session'=>['save_path'=>'/srv/sessions','name'=>'PHPSESSID','lifetime'=>2592000,'secure'=>true],
];
