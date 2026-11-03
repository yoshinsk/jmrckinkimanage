<?php
// Path: tests/auth/database_answers.php
// 機能: 実MariaDBの独立した架空DBで、追加DDL・初期権限・失敗時復元・後続変更保護を検証する。
declare(strict_types=1);
require '/srv/tests/ConfirmedAnswersDatabase.php';
$db=new PDO('mysql:host=db;dbname=jmrckinki_answers_lab;charset=utf8mb4','lab_fixture','lab_fixture_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00';INSERT INTO roles VALUES(3,'ENTRANT');INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(3,3,'initial@example.invalid',1,'fixture-unused');INSERT INTO portal_account_controls(user_id) VALUES(3);
 INSERT INTO portal_roles(code,name) VALUES('OFFICE','事務局'),('FINANCE','旧財務');
 INSERT INTO portal_role_permissions(role_id,permission_code) VALUES(1,'profiles.correct'),(1,'billing.confirm');
 INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by) VALUES(3,1,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,3,3)");
$release=new \Jmrc\Deployment\ConfirmedAnswersDatabase($db,'jmrckinki_answers_lab');
$migration=file_get_contents('/srv/tests/011_license_declarations.sql');$permissions=file_get_contents('/srv/tests/staff_permissions.sql');
$before=$release->snapshot();$checks=[];
$check=static function(string $name,bool $ok) use(&$checks): void { if(!$ok){throw new RuntimeException($name);}$checks[]=$name; };
$deny=static function(string $name,callable $f) use($check): void {try{$f();throw new LogicException('Expected rejection: '.$name);}catch(RuntimeException $e){$check($name,true);}};
$deny('対象DBを取り違えない',fn()=>new \Jmrc\Deployment\ConfirmedAnswersDatabase($db,'jmrckinki'));
$changed=$before;$changed['roles']=[];$deny('事前状態が変われば適用しない',fn()=>$release->apply($changed,3,$migration,$permissions));
$deny('指定アカウントなしでは適用しない',fn()=>$release->apply($before,999,$migration,$permissions));
$deny('権限登録に失敗したらDDLも戻す',fn()=>$release->apply($before,3,$migration,"SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'"));
$check('失敗後の原状復帰',$release->snapshot()===$before);
$record=$release->apply($before,3,$migration,$permissions);
$check('追加表・列・指定者の2権限だけを追加',$record['after']['declaration_table'] && $record['after']['bootstrap_column'] && count($record['after']['grants'])===3);
$check('初期設定を本人の承認と記録しない',(int)$db->query("SELECT COUNT(*) FROM portal_user_role_grants WHERE user_id=3 AND bootstrap_approval_ref IS NOT NULL AND approved_by IS NULL")->fetchColumn()===2);
$check('事務局と同じ業務権限を設定',(int)$db->query("SELECT COUNT(*) FROM portal_role_permissions p JOIN portal_roles r ON r.id=p.role_id WHERE r.code IN ('FINANCE','COMMITTEE','APPROVER')")->fetchColumn()===6);
$db->exec("INSERT INTO portal_role_permissions(role_id,permission_code) VALUES(1,'fixture.later')");
$deny('後続の権限変更があれば復元を停止',fn()=>$release->rollback($record));
$db->exec("DELETE FROM portal_role_permissions WHERE permission_code='fixture.later'");
$release->rollback($record);$check('DDLと権限データをまとめて復元',$release->snapshot()===$before);
$check('初期設定の外部操作ログは残す',(int)$db->query("SELECT COUNT(*) FROM portal_audit_events WHERE action='STAFF_BOOTSTRAP_CONFIGURED' AND actor_user_id IS NULL")->fetchColumn()===1);
// 本番と同じCLI入口も別プロセスで通す。資格情報は架空DBの環境変数だけに渡す。
$cli=static function(string $action,array $input=[]): array {
    $env=getenv();$env['JMRC_RELEASE_DATABASE']='jmrckinki_answers_lab';$env['JMRC_RELEASE_DB_USER']='lab_fixture';$env['MYSQL_PWD']='lab_fixture_only';
    $p=proc_open([PHP_BINARY,'/srv/tests/confirmed_answers_database.php',$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p)) {throw new RuntimeException('CLI launch failed');}
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0) {throw new RuntimeException($error);}
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
$check('CLIから同じ事前状態を取得',$cli('snapshot')===$before);
$cliRecord=$cli('apply',['before'=>$before,'email'=>'initial@example.invalid']);
$check('CLIがメールアドレスから指定者を解決',$cliRecord['subject']===3 && count($cliRecord['after']['grants'])===3);
$check('CLIによる復元',$cli('rollback',$cliRecord)['rolled_back'] && $release->snapshot()===$before);
echo json_encode(['status'=>'PASS','cases'=>$checks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
