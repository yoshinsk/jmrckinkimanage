<?php
// Path: tests/bank/concurrent_bank.php
// 機能: 隔離DBの別接続から同時取込・同時照合を実行するCLI専用ワーカー。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}require '/srv/auth/src/bootstrap.php';
while(microtime(true)<(float)$argv[1]){usleep(1000);}
try{
    $s=new \Jmrc\Bank\BankService(jmrc_db(),jmrc_auth_config());
    $r=$argv[2]==='import'?$s->import(7,1,file_get_contents('/tmp/yucho-race.csv'),'UTF-8'):
        $s->resolve(7,(int)$argv[3],1,'new',(int)$argv[4],0,$argv[5],'同時照合',true,false);
    echo json_encode(['status'=>200,'id'=>$r['id'],'replayed'=>$r['replayed']]);
}catch(\Jmrc\Auth\HttpFailure $e){echo json_encode(['status'=>$e->status]);}
