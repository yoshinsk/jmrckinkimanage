<?php
// Path: tests/club/intake_database.php
// 機能: 専用Docker DBだけでDDL・権限の適用と復元、部分失敗、後続業務データ保護を検証する。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require '/srv/auth/scripts/ClubIntakeDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_club_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_club_release_lab CHARACTER SET utf8mb4;CREATE USER 'intake_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_club_release_lab.* TO 'intake_runtime'@'%';USE jmrckinki_club_release_lab;
 CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,value VARCHAR(20));INSERT INTO users VALUES(1,'fixture');
 CREATE TABLE portal_years(year SMALLINT UNSIGNED PRIMARY KEY);INSERT INTO portal_years VALUES(2027);
 CREATE TABLE portal_applications(id BIGINT UNSIGNED PRIMARY KEY,year SMALLINT UNSIGNED NOT NULL,UNIQUE(id,year));
 CREATE TABLE portal_clubs(id BIGINT UNSIGNED PRIMARY KEY);
 CREATE TABLE portal_club_years(id BIGINT UNSIGNED PRIMARY KEY,year SMALLINT UNSIGNED NOT NULL,UNIQUE(id,year));");
$r=new \Jmrc\Deployment\ClubIntakeDatabase($db,'jmrckinki_club_release_lab','intake_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function(string $name,bool $ok)use(&$cases):void{if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$reject=static function(string $name,callable $op)use($check):void{$failed=false;try{$op();}catch(Throwable){$failed=true;}$check($name,$failed);};
$ddl=file_get_contents('/srv/auth/design/phase2/013_club_intake.sql');$fees=file_get_contents('/srv/auth/design/phase2/013_club_fee_profiles_2027.sql');
$record=$r->apply($before,$ddl,$fees);$check('追加3表と9料金・既存表不変',count($record['after']['own'])===3&&count($record['after']['profiles'])===9&&$before['protected_checksums']===$record['after']['protected_checksums']);
$reject('同じDDLを再適用しない',fn()=>$r->apply($r->snapshot(),$ddl,$fees));
$db->exec("UPDATE users SET value='changed' WHERE id=1");$reject('後続の既存業務変更があれば復元拒否',fn()=>$r->rollback($record));$db->exec("UPDATE users SET value='fixture' WHERE id=1");
$db->exec("INSERT INTO portal_applications VALUES(1,2027);INSERT INTO portal_club_intakes(application_id,applicant_user_id,year,registration_kind,jaf_kind,membership_kind,annual_yen,joining_yen,category_digit,payload_cipher,payload_hmac) VALUES(1,1,2027,'new','official','regular',50000,10000,'1','fixture',UNHEX(SHA2('fixture',256)))");
$reject('追加表に申請が入れば復元拒否',fn()=>$r->rollback($record));$db->exec('DELETE FROM portal_club_intakes;DELETE FROM portal_applications');
$r->rollback($record);$check('元の表・権限へ完全復元',$r->snapshot()===$before);
$reject('DDL途中失敗を復元',fn()=>$r->apply($before,$ddl.';INVALID_SYNTHETIC_SQL;',$fees));$check('部分失敗後も元の状態',$r->snapshot()===$before);
$record=$r->apply($before,$ddl,$fees);$r->rollback($record);$check('失敗後の再適用と復元',$r->snapshot()===$before);
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
