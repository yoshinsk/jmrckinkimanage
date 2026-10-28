<?php
// Path: tests/club/concurrent_club.php
// 機能: セッションロックに依存しない独立DB接続で、年度登録・請求・承認と訂正の競合を検証する。
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit(1); }
require '/srv/auth/src/bootstrap.php';
while(microtime(true)<(float)$argv[1]) { usleep(1000); }
try {
    $db=jmrc_db();$club=new \Jmrc\Club\ClubService($db,jmrc_auth_config()['data_key']);$billing=new \Jmrc\Billing\BillingService($db);
    $row=match($argv[2]) {
        'submit'=>$club->submit(2,(int)$argv[3],(int)$argv[4],'1',['name_full'=>'架空同時登録','name_short'=>'同時','rep_name'=>'架空','contact_name'=>'架空','postcode'=>'0000000','address'=>'架空','phone'=>'000','email'=>'race@example.invalid','bank_details'=>'架空同時口座'],bin2hex(random_bytes(24))),
        'issue'=>$billing->issueClub(2,(int)$argv[3]),
        'approve'=>$club->approve(7,(int)$argv[3]),
        'reverse'=>$billing->reverse(13,(int)$argv[3],bin2hex(random_bytes(24)),'同時実行の架空訂正'),
    };
    echo json_encode(['status'=>200,'id'=>$row['id']]);
}catch(\Jmrc\Auth\HttpFailure $e) {echo json_encode(['status'=>$e->status]);}
