<?php
// Path: tests/license/details_database.php
// 機能: 架空DBで追加表・最小権限・途中失敗・データ発生後の復元拒否を検証。本番へ接続しない。
declare(strict_types=1);
require '/srv/auth/scripts/LicenseDetailsDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_details_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_license_details_lab CHARACTER SET utf8mb4;CREATE USER 'details_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_license_details_lab.* TO 'details_runtime'@'%';USE jmrckinki_license_details_lab;CREATE TABLE portal_identifiers(id BIGINT UNSIGNED PRIMARY KEY,value VARCHAR(32));INSERT INTO portal_identifiers VALUES(1,'fixture');");
$r=new \Jmrc\Deployment\LicenseDetailsDatabase($db,'jmrckinki_license_details_lab','details_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function($name,$ok)use(&$cases){if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$reject=static function($name,callable $f)use($check){$bad=false;try{$f();}catch(Throwable){$bad=true;}$check($name,$bad);};
$ddl=file_get_contents('/srv/auth/design/phase2/015_license_details.sql');$record=$r->apply($before,$ddl);
$check('空の追加表とINSERT・UPDATEだけを追加',$record['after']['rows']===0&&$record['after']['permissions']===['INSERT','UPDATE']);
$reject('再適用拒否',fn()=>$r->apply($r->snapshot(),$ddl));
$reject('空の種別配列をDB拒否',fn()=>$db->exec("INSERT INTO portal_license_details VALUES(1,'[]',NULL)"));
$reject('JSONオブジェクトをDB拒否',fn()=>$db->exec("INSERT INTO portal_license_details VALUES(1,'{}',NULL)"));
$db->exec("INSERT INTO portal_license_details VALUES(1,'[1]',NULL)");
$reject('登録後の自動復元拒否',fn()=>$r->rollback($record));$db->exec('DELETE FROM portal_license_details');
$db->exec("UPDATE portal_identifiers SET value='later' WHERE id=1");$reject('後続業務変更の復元拒否',fn()=>$r->rollback($record));$db->exec("UPDATE portal_identifiers SET value='fixture' WHERE id=1");
$r->rollback($record);$check('原本・定義・権限の復元',$r->snapshot()===$before);
$reject('途中失敗の限定復元',fn()=>$r->apply($before,$ddl.';INVALID_SYNTHETIC_SQL;'));$check('途中失敗後も原本一致',$r->snapshot()===$before);
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0]);
