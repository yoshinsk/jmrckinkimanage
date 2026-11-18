<?php
// Path: tests/club/concurrent_closure.php
// 機能: 別接続の同時終了・最終照合接続を検証する隔離専用ワーカー。実アカウント・本番へ接続しない。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require '/srv/auth/src/bootstrap.php';
$db=jmrc_db();$config=jmrc_auth_config();
while(microtime(true)<(float)$argv[1]){usleep(1000);}
try{
    if($argv[2]==='link'){$out=(new \Jmrc\Club\ClubIntakeService($db,$config))->link(7,(int)$argv[3],3,0,'99879',true);}
    else{$out=(new \Jmrc\Club\ClubReviewClosure($db,$config))->close(7,2027,'架空の並行照合完了');}
    echo json_encode(['action'=>$argv[2],'status'=>200]);
}catch(\Jmrc\Auth\HttpFailure $e){echo json_encode(['action'=>$argv[2],'status'=>$e->status]);}
