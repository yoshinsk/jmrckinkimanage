<?php
// Path: tests/club/followup_database.php
// 機能: 架空DBで区分制約・終了表・最小権限の追加、途中失敗、後続変更保護と復元を検証する。
declare(strict_types=1);
require '/srv/auth/scripts/ClubFollowupDatabase.php';
$db=new PDO('mysql:host=db;dbname=mysql','root','lab_club_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE jmrckinki_club_followup_lab CHARACTER SET utf8mb4;CREATE USER 'followup_runtime'@'%' IDENTIFIED BY 'lab_only';GRANT SELECT ON jmrckinki_club_followup_lab.* TO 'followup_runtime'@'%';USE jmrckinki_club_followup_lab;
 CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,value VARCHAR(20));INSERT INTO users VALUES(1,'fixture');
 CREATE TABLE portal_years(year SMALLINT UNSIGNED PRIMARY KEY);INSERT INTO portal_years VALUES(2027);
 CREATE TABLE portal_club_categories(category_digit CHAR(1) PRIMARY KEY,name VARCHAR(64),enabled BOOLEAN,CHECK(enabled IN(0,1)),CONSTRAINT chk_portal_category_confirmed CHECK(category_digit IN ('1','2','3','6')));
 INSERT INTO portal_club_categories VALUES('1','公認クラブ',1),('2','加盟クラブ',1),('3','準加盟クラブ',1),('6','加盟団体',1);");
$r=new \Jmrc\Deployment\ClubFollowupDatabase($db,'jmrckinki_club_followup_lab','followup_runtime','%');$before=$r->snapshot();$cases=[];
$check=static function(string $n,bool $ok)use(&$cases){if(!$ok){throw new RuntimeException($n);}$cases[]=$n;};
$reject=static function(string $n,callable $op)use($check){$failed=false;try{$op();}catch(Throwable){$failed=true;}$check($n,$failed);};
$ddl=file_get_contents('/srv/auth/design/phase2/014_club_review_closure.sql');$rec=$r->apply($before,$ddl);
$check('7区分と空の終了表・INSERTだけ追加',count($rec['after']['categories'])===7&&$rec['after']['permissions']===['INSERT']);
$reject('旧禁止区分4をDB拒否',fn()=>$db->exec("INSERT INTO portal_club_categories VALUES('4','不正',1)"));
$reject('再適用拒否',fn()=>$r->apply($r->snapshot(),$ddl));
$db->exec("UPDATE users SET value='changed' WHERE id=1");$reject('後続業務変更を保持',fn()=>$r->rollback($rec));$db->exec("UPDATE users SET value='fixture' WHERE id=1");
$db->exec("INSERT INTO portal_club_intake_closures(year,closed_by,reason) VALUES(2027,1,'架空照合完了')");$reject('終了記録があれば復元拒否',fn()=>$r->rollback($rec));$db->exec('DELETE FROM portal_club_intake_closures');
$r->rollback($rec);$check('原本・制約・権限の復元',$before===$r->snapshot());
$reject('DDL途中失敗は限定復元',fn()=>$r->apply($before,$ddl.';INVALID_SYNTHETIC_SQL;'));$check('途中失敗後の原本保持',$before===$r->snapshot());
$rec=$r->apply($before,$ddl);$r->rollback($rec);$check('再適用と復元',$before===$r->snapshot());
echo json_encode(['status'=>'PASS','cases'=>$cases,'fixture_only'=>true,'production_connections'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
