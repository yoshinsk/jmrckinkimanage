<?php
// Path: scripts/confirmed_answers_database.php
// 機能: 非公開の管理CLIで11月3日DB変更を読取・適用・復元する。JSON入力と結果だけを使い、資格情報を出力しない。
declare(strict_types=1);
require_once __DIR__.'/ConfirmedAnswersDatabase.php';
ini_set('zend.exception_ignore_args','1');
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
try {
    $action=$argv[1]??'';
    if(!in_array($action,['snapshot','apply','rollback'],true)) {throw new RuntimeException('Expected snapshot, apply or rollback');}
    $database=getenv('JMRC_RELEASE_DATABASE');$user=getenv('JMRC_RELEASE_DB_USER');$password=getenv('MYSQL_PWD');
    if(!in_array($database,['jmrckinki','jmrckinki_answers_lab'],true) || !$user || !$password) {throw new RuntimeException('Explicit deployment database credentials required');}
    $host=$database==='jmrckinki'?'localhost':'db';
    $db=new PDO('mysql:host='.$host.';dbname='.$database.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("SET time_zone='+00:00'");
    // 既存処理の長いトランザクションに遭遇した場合は、DDLの待機を5秒で打ち切る。
    $db->exec('SET SESSION lock_wait_timeout=5');
    $release=new \Jmrc\Deployment\ConfirmedAnswersDatabase($db,$database);
    if($action==='snapshot') {$result=$release->snapshot();}
    else {
        $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
        if($action==='rollback') {$release->rollback($input);$result=['status'=>'PASS','rolled_back'=>true];}
        else {
            // 指定されたメールアドレスを適用直前に解決。別人のIDを呼出元の数値だけで信用しない。
            if(!is_string($input['email']??null)) {throw new RuntimeException('Designated initial account required');}
            $s=$db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$input['email']]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)!==1) {throw new RuntimeException('Initial account is not unique');}
            $result=$release->apply($input['before'],(int)$ids[0],file_get_contents(__DIR__.'/011_license_declarations.sql'),file_get_contents(__DIR__.'/staff_permissions.sql'));
        }
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    // PDOのエラー文に接続情報を混ぜず、管理端末には操作の成否だけを返す。
    fwrite(STDERR,$error instanceof PDOException?'Database operation failed':$error->getMessage());exit(1);
}
