<?php
// Path: tests/membership/concurrent_submit.php
// 機能: 別接続から同人・同日の異イベントを同時申請し、実サービスの競合拒否を検証するCLIワーカー。
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
require '/srv/auth/src/Auth/HttpFailure.php';
require '/srv/auth/src/Membership/MembershipService.php';
$db=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','lab_fixture','lab_fixture_only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+00:00'");
$at=(float)$argv[1];
while (microtime(true)<$at) { usleep(1000); }
try {
    $service=new \Jmrc\Membership\MembershipService($db);
    $row=$service->submitTemporary(11,(int)$argv[2],str_repeat($argv[3],48));
    echo json_encode(['status'=>201,'id'=>$row['id']]);
} catch (\Jmrc\Auth\HttpFailure $failure) {
    echo json_encode(['status'=>$failure->status]);
}
