<?php
// Path: tests/club/supporting_fees_database.php
// 機能: 架空DBで賛助料金の訂正・再実行拒否・途中失敗・後続変更保護・限定復元を検証する。
declare(strict_types=1);
require '/srv/auth/scripts/SupportingFeesDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_details_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_org_fees_lab CHARACTER SET utf8mb4;CREATE USER 'orgfees_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_org_fees_lab.* TO 'orgfees_runtime'@'%';USE jmrckinki_org_fees_lab;CREATE TABLE portal_years(year SMALLINT UNSIGNED PRIMARY KEY);INSERT INTO portal_years VALUES(2027);CREATE TABLE evidence(id INT PRIMARY KEY,note VARCHAR(32));INSERT INTO evidence VALUES(1,'fixture');");
$db->exec(explode('CREATE TABLE portal_club_intakes',file_get_contents('/srv/auth/design/phase2/013_club_intake.sql'))[0]);
foreach(['013_club_fee_profiles_2027.sql','017_regular_organization_fees_2027.sql'] as $n){$db->exec(file_get_contents('/srv/auth/design/phase2/'.$n));}
$r=new \Jmrc\Deployment\SupportingFeesDatabase($db,'jmrckinki_org_fees_lab','orgfees_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function($name,$ok)use(&$cases){if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$reject=static function($name,callable $f)use($check){$bad=false;try{$f();}catch(Throwable){$bad=true;}$check($name,$bad);};
$ddl=file_get_contents('/srv/auth/design/phase2/018_supporting_organization_fees_2027.sql');$record=$r->apply($before,$ddl);
$check('賛助の5・8を追加し6を訂正',count($record['after']['profiles'])===13);
$check('既存業務・権限・定義は保持',$before['permissions']===$record['after']['permissions']&&$before['protected_checksums']===$record['after']['protected_checksums']&&$before['schema']===$record['after']['schema']);
$reject('再適用を拒否',fn()=>$r->apply($r->snapshot(),$ddl));
$db->exec("UPDATE evidence SET note='later' WHERE id=1");$reject('後続業務を保護',fn()=>$r->rollback($record));$db->exec("UPDATE evidence SET note='fixture' WHERE id=1");
$db->exec("UPDATE portal_club_fee_profiles SET annual_yen=21000 WHERE jaf_kind='official_org' AND membership_kind='supporting'");$reject('後続の料金訂正を保護',fn()=>$r->rollback($record));$db->exec("UPDATE portal_club_fee_profiles SET annual_yen=20000 WHERE jaf_kind='official_org' AND membership_kind='supporting'");
$r->rollback($record);$check('変更した3料金だけ原状復元',$r->snapshot()===$before);
$reject('途中のSQL失敗を拒否',fn()=>$r->apply($before,$ddl.';INVALID_LAB_SQL;'));$check('SQL失敗後の原本一致',$r->snapshot()===$before);
$reject('不足したDDLを拒否',fn()=>$r->apply($before,"UPDATE portal_club_fee_profiles SET annual_yen=20000 WHERE year=2027 AND jaf_kind='organization' AND membership_kind='supporting'"));$check('検証失敗後の原本一致',$r->snapshot()===$before);
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0]);
