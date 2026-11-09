<?php
// Path: scripts/editor_drafts_database.php
// 機能: 非公開CLIから下書き2表の状態確認・限定追加・空表復元を実行。資格情報は環境内だけで扱う。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');require_once __DIR__.'/EditorDraftDatabase.php';
try{
    $action=$argv[1]??'';if(!in_array($action,['snapshot','apply','rollback-empty'],true)){throw new RuntimeException('Unknown action');}
    $name=getenv('JMRC_RELEASE_DATABASE');$user=getenv('JMRC_RELEASE_DB_USER');$password=getenv('MYSQL_PWD');
    if(!in_array($name,['jmrckinki','jmrckinki_editor_lab'],true)||!$user||!$password){throw new RuntimeException('Explicit database credentials required');}
    $db=new PDO('mysql:host='.($name==='jmrckinki'?'localhost':'db').';dbname='.$name.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("SET time_zone='+00:00';SET SESSION lock_wait_timeout=5");
    $r=new \Jmrc\Deployment\EditorDraftDatabase($db,$name);$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $out=match($action){
        'snapshot'=>$r->snapshot($in['account']),
        'apply'=>$r->apply($in['before'],file_get_contents(__DIR__.'/012_editor_drafts.sql'),$in['account']),
        'rollback-empty'=>(function()use($r,$in){$r->rollbackEmpty($in);return ['status'=>'PASS','restored'=>true];})(),
    };
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?'Database operation failed':$e->getMessage());exit(1);}
