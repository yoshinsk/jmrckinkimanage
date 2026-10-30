<?php
// Path: tests/license/mobile_seed.php
// 機能: 実ブラウザーで初回登録する専用架空ユーザーを隔離DBへ準備する。実データなし。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
$db=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','lab_fixture','lab_fixture_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$s=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,3,?,1,?)');
foreach([21,22,181,182] as $id){$s->execute([$id,"u$id@example.invalid",password_hash('lab-password-only',PASSWORD_DEFAULT)]);$db->exec("INSERT INTO portal_account_controls(user_id) VALUES($id)");$db->exec("INSERT INTO portal_profiles(user_id,last_name,first_name) VALUES($id,'架空姓','会員$id')");}
