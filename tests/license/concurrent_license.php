<?php
// Path: tests/license/concurrent_license.php
// 機能: 異なるユーザー/DB接続による重複登録、同じ版への競合更新を検証する隔離CLI。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}require '/srv/auth/src/bootstrap.php';
while(microtime(true)<(float)$argv[1]){usleep(1000);}
try{
    $in=$argv[3]==='create'?['kind'=>'jaf_competition','number'=>'RACE-JAF','license_type'=>'国内B','expires_on'=>null]:['id'=>(int)$argv[3],'row_version'=>(int)$argv[4],'kind'=>'jaf_official','number'=>'','license_type'=>'審判B','expires_on'=>$argv[5]];
    $r=(new \Jmrc\License\LicenseService(jmrc_db(),jmrc_auth_config()))->save((int)$argv[2],$in);echo json_encode(['status'=>200,'id'=>$r['id']]);
}catch(\Jmrc\Auth\HttpFailure $e){echo json_encode(['status'=>$e->status]);}
