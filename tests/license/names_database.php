<?php
// Path: tests/license/names_database.php
// 機能: 架空DBでJAF登録名列の適用・原状復元・使用後の拒否・途中失敗を確認する。
declare(strict_types=1);
require '/srv/auth/scripts/LicenseNamesDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_details_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_license_details_lab CHARACTER SET utf8mb4;CREATE USER 'details_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_license_details_lab.* TO 'details_runtime'@'%';USE jmrckinki_license_details_lab;CREATE TABLE portal_identifiers(id BIGINT UNSIGNED PRIMARY KEY,value VARCHAR(32));INSERT INTO portal_identifiers VALUES(1,'fixture');");
$db->exec(file_get_contents('/srv/auth/design/phase2/015_license_details.sql'));
$r=new \Jmrc\Deployment\LicenseNamesDatabase($db,'jmrckinki_license_details_lab','details_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function($name,$ok)use(&$cases){if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$reject=static function($name,callable $f)use($check){$bad=false;try{$f();}catch(Throwable){$bad=true;}$check($name,$bad);};
$ddl=file_get_contents('/srv/auth/design/phase2/016_license_registered_name.sql');$record=$r->apply($before,$ddl);
$check('暗号欄だけを追加し既存権限を維持',$record['after']['exists']&&$record['after']['permissions']===$before['permissions']);
$reject('再適用は拒否',fn()=>$r->apply($r->snapshot(),$ddl));
$db->exec("INSERT INTO portal_license_details(identifier_id,types_json) VALUES(1,'[1]')");
$reject('後続の登録を保護',fn()=>$r->rollback($record));$db->exec('DELETE FROM portal_license_details');
$db->exec("UPDATE portal_identifiers SET value='later' WHERE id=1");$reject('他の業務変更を保護',fn()=>$r->rollback($record));$db->exec("UPDATE portal_identifiers SET value='fixture' WHERE id=1");
$r->rollback($record);$check('元定義と原本に復元',$r->snapshot()===$before);
$reject('途中失敗時は追加欄を復元',fn()=>$r->apply($before,$ddl.';INVALID_LAB_SQL;'));$check('途中失敗後も元状態',$r->snapshot()===$before);
$db->exec("INSERT INTO portal_license_details(identifier_id,types_json) VALUES(1,'[1]')");$reject('既存登録があれば適用を拒否',fn()=>$r->apply($r->snapshot(),$ddl));
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0]);
