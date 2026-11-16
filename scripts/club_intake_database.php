<?php
// Path: scripts/club_intake_database.php
// 機能: Plesk本番のクラブ受付DDLを非公開バックアップ付きで適用・検査・復元する。既存業務行と受付設定は変更しない。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require __DIR__.'/ClubIntakeDatabase.php';
try{
    $mode=$argv[1]??'';$path=$argv[2]??'';$base='/var/www/vhosts/gymkinki.com/private/jmrc-portal-backups';
    if(!in_array($mode,['--snapshot','--apply','--rollback'],true)){throw new RuntimeException('Use --snapshot, --apply BACKUP, or --rollback BACKUP');}
    $cfg=require '/var/www/vhosts/gymkinki.com/private/jmrc-portal/auth.config.php';
    if(($cfg['db']['user']??'')!=='jmrc_portal'||($cfg['club_intake_enabled']??false)!==false){throw new RuntimeException('Runtime or held intake changed');}
    $password=trim(file_get_contents('/etc/psa/.psa.shadow'));
    $db=new PDO('mysql:host=localhost;dbname=jmrckinki;charset=utf8mb4','admin',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec("SET time_zone='+00:00'");$db->exec('SET SESSION lock_wait_timeout=5');unset($password);
    $r=new \Jmrc\Deployment\ClubIntakeDatabase($db,'jmrckinki','jmrc_portal','127.0.0.1');
    if($db->query('SELECT GROUP_CONCAT(CONCAT(year,CHAR(9),source_mode) ORDER BY year) FROM portal_years')->fetchColumn()!=="2027\tshadow"){throw new RuntimeException('Year source changed');}
    if($mode==='--snapshot'){echo json_encode($r->snapshot(),JSON_THROW_ON_ERROR);exit;}
    if(realpath($base)!==$base||!preg_match('#^'.preg_quote($base,'#').'/club-intake-db-20261116-[0-9TZ]+$#D',$path)||is_link($path)){throw new RuntimeException('Unexpected private backup path');}
    if($mode==='--apply'){
        if(file_exists($path)){throw new RuntimeException('Backup already exists');}mkdir($path,0700);
        $dump=$path.'/before.sql';$out=fopen($dump,'x');chmod($dump,0600);
        // 環境変数でだけ接続値を渡す。コマンド文字列や標準出力に資格情報を出さない。
        $env=['PATH'=>getenv('PATH'),'MYSQL_PWD'=>trim(file_get_contents('/etc/psa/.psa.shadow'))];
        $process=proc_open(['mariadb-dump','-uadmin','--single-transaction','--routines','--triggers','--skip-lock-tables','jmrckinki'],[0=>['pipe','r'],1=>$out,2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($process)){throw new RuntimeException('Backup process failed');}fclose($pipes[0]);stream_get_contents($pipes[2]);fclose($pipes[2]);$status=proc_close($process);fclose($out);unset($env);
        if($status!==0||filesize($dump)<1000){throw new RuntimeException('Backup failed');}
        $record=$r->apply($r->snapshot(),file_get_contents(__DIR__.'/../design/phase2/013_club_intake.sql'),file_get_contents(__DIR__.'/../design/phase2/013_club_fee_profiles_2027.sql'));
        $record['dump_sha256']=hash_file('sha256',$dump);$record['phase']='applied';
        file_put_contents($path.'/record.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);chmod($path.'/record.json',0600);
        echo json_encode(['status'=>'PASS','backup'=>$path,'tables'=>3,'fee_rows'=>9,'business_rows'=>0,'record_sha256'=>hash_file('sha256',$path.'/record.json')],JSON_THROW_ON_ERROR);
    }else{
        if(realpath($path)!==$path||is_link($path.'/record.json')){throw new RuntimeException('Backup path changed');}
        $record=json_decode(file_get_contents($path.'/record.json'),true,512,JSON_THROW_ON_ERROR);
        if(($record['phase']??'')!=='applied'||hash_file('sha256',$path.'/before.sql')!==$record['dump_sha256']){throw new RuntimeException('Backup record changed');}
        $r->rollback($record);$record['phase']='rolled_back';file_put_contents($path.'/record.json',json_encode($record,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);
        echo json_encode(['status'=>'PASS','restored'=>true],JSON_THROW_ON_ERROR);
    }
}catch(Throwable $e){fwrite(STDERR,'Club intake database operation failed: '.get_class($e)."\n");exit(1);}
