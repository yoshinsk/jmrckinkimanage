<?php
// Path: tests/club/organization_fees_database.php
// 機能: 架空DBで団体料金2行と制約変更の適用・復元・途中失敗・後続データ保護を確認する。
declare(strict_types=1);
require '/srv/auth/scripts/OrganizationFeesDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_details_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_org_fees_lab CHARACTER SET utf8mb4;CREATE USER 'orgfees_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_org_fees_lab.* TO 'orgfees_runtime'@'%';USE jmrckinki_org_fees_lab;CREATE TABLE portal_years(year SMALLINT UNSIGNED PRIMARY KEY);INSERT INTO portal_years VALUES(2027);CREATE TABLE evidence(id INT PRIMARY KEY,note VARCHAR(32));INSERT INTO evidence VALUES(1,'fixture');");
$db->exec(explode('CREATE TABLE portal_club_intakes',file_get_contents('/srv/auth/design/phase2/013_club_intake.sql'))[0]);
$db->exec(file_get_contents('/srv/auth/design/phase2/013_club_fee_profiles_2027.sql'));
$r=new \Jmrc\Deployment\OrganizationFeesDatabase($db,'jmrckinki_org_fees_lab','orgfees_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function($name,$ok)use(&$cases){if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$reject=static function($name,callable $f)use($check){$bad=false;try{$f();}catch(Throwable){$bad=true;}$check($name,$bad);};
$ddl=file_get_contents('/srv/auth/design/phase2/017_regular_organization_fees_2027.sql');$record=$r->apply($before,$ddl);
$check('正会員の5・8だけ2行を追加',$record['after']['rows']===2&&count($record['after']['profiles'])===11);
$check('既存9行・他表と権限を保持',$before['permissions']===$record['after']['permissions']&&$before['protected_checksums']===$record['after']['protected_checksums']);
$reject('再適用を拒否',fn()=>$r->apply($r->snapshot(),$ddl));
$db->exec("UPDATE evidence SET note='later' WHERE id=1");$reject('後続業務を保護',fn()=>$r->rollback($record));$db->exec("UPDATE evidence SET note='fixture' WHERE id=1");
$db->exec("UPDATE portal_club_fee_profiles SET annual_yen=21000 WHERE jaf_kind='official_org'");$reject('後続の料金訂正を保護',fn()=>$r->rollback($record));$db->exec("UPDATE portal_club_fee_profiles SET annual_yen=20000 WHERE jaf_kind='official_org'");
$r->rollback($record);$check('既存料金とCHECK制約を原状復元',$r->snapshot()===$before);
$reject('料金追加後の失敗も限定復元',fn()=>$r->apply($before,$ddl.';INVALID_LAB_SQL;'));$check('失敗後の原本一致',$r->snapshot()===$before);
$reject('制約を外した途中の失敗も復元',fn()=>$r->apply($before,'ALTER TABLE portal_club_fee_profiles DROP CONSTRAINT CONSTRAINT_3;INVALID_LAB_SQL;'));$check('制約途中失敗後の原本一致',$r->snapshot()===$before);
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0]);
