<?php
// Path: tests/card/concurrent_card.php
// 機能: 別PHPプロセス・別DB接続で本人会員証の同時発行を検証する。隔離CLI専用。
declare(strict_types=1);
if(PHP_SAPI!=='cli') {exit(1);}
require '/srv/auth/src/bootstrap.php';
while(microtime(true)<(float)$argv[1]) {usleep(1000);}
try {
    $card=(new \Jmrc\Card\CardService(jmrc_db(),jmrc_auth_config()))->issue(3,(int)$argv[2]);
    echo json_encode(['status'=>200,'qr_url'=>$card['qr_url']]);
}catch(\Jmrc\Auth\HttpFailure $e){echo json_encode(['status'=>$e->status]);}
