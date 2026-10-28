<?php
// Path: tests/billing/concurrent_billing.php
// 機能: 別プロセス・別DB接続で同時請求/入金の実サービスを実行する隔離専用ワーカー。
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
require '/srv/auth/src/bootstrap.php';
while (microtime(true)<(float)$argv[1]) { usleep(1000); }
try {
    $service=new \Jmrc\Billing\BillingService(jmrc_db());
    $row=$argv[2]==='issue'?$service->issue(7,(int)$argv[3]):$service->confirm(7,(int)$argv[3],2000,$argv[4],$argv[5]);
    echo json_encode(['status'=>200,'id'=>$row['id']]);
} catch (\Jmrc\Auth\HttpFailure $error) { echo json_encode(['status'=>$error->status]); }
