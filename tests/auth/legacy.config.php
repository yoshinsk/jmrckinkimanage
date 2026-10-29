<?php
// Path: tests/auth/legacy.config.php → 隔離コピーentry/common/config.php
// 機能: 既存画面を架空DBだけへ接続する。実設定の読み込み/コピーはしない。
return [
    'db'=>['host'=>'db','port'=>3306,'name'=>'jmrckinki_auth_lab','charset'=>'utf8mb4',
        'collation'=>'utf8mb4_general_ci','user'=>'auth_runtime','pass'=>'lab_runtime_only'],
    'features'=>['audit_log'=>true],
    'verification_ttl_minutes'=>60,
    'base_url'=>'/entry/','system_name'=>'JMRC隔離検証',
    'storage_path'=>'/srv/unused-storage','club_portal'=>['allow_event_create'=>false],
    'password_policy'=>[],
];
