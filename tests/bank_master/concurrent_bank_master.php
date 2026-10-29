<?php
// Path: tests/bank_master/concurrent_bank_master.php
// 機能: 別DB接続で版競合・有効期間競合の実サービスを同時実行する隔離専用ワーカー。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}require '/srv/auth/src/bootstrap.php';
while(microtime(true)<(float)$argv[1]){usleep(1000);}
try{$in=json_decode(base64_decode($argv[2],true),true,512,JSON_THROW_ON_ERROR);$r=(new \Jmrc\Bank\BankMasterService(jmrc_db(),jmrc_auth_config()))->saveAccount(76,$in);echo json_encode(['status'=>200,'id'=>$r['id']]);}
catch(\Jmrc\Auth\HttpFailure $e){echo json_encode(['status'=>$e->status]);}
