<?php
// Path: scripts/operator_lab/seed.php
// 機能: 専用DB名と設置マーカーを確認し、架空アカウント・更新クラブ・口座だけを初期登録する。CLI専用。
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!is_file(__DIR__.'/operator-lab.json')){exit(1);}
$cfg=require __DIR__.'/auth.config.php';
if($cfg['db']['dsn']!=='mysql:host=127.0.0.1;dbname=jmrc_portal_operator_lab;charset=utf8mb4'){throw new RuntimeException('専用DBではありません');}
$db=new PDO($cfg['db']['dsn'],$cfg['db']['user'],$cfg['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='jmrc_portal_operator_lab'||(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0){throw new RuntimeException('空の専用DBだけに初期登録できます');}
require __DIR__.'/src/Card/SecretBox.php';
$secrets=json_decode(file_get_contents(__DIR__.'/accounts.json'),true,512,JSON_THROW_ON_ERROR);
$db->exec("SET time_zone='+00:00';INSERT INTO roles VALUES(1,'JMRC_ADMIN'),(2,'CLUB'),(3,'ENTRANT')");
$st=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,?,?,1,?)');
foreach([[2,2,'club'],[3,2,'other'],[7,3,'office'],[13,3,'finance']] as [$id,$role,$key]){$st->execute([$id,$role,$secrets[$key]['email'],password_hash($secrets[$key]['password'],PASSWORD_DEFAULT)]);}
$db->exec("INSERT INTO portal_account_controls(user_id) SELECT id FROM users;
INSERT INTO portal_roles(id,code,name) VALUES(1,'OFFICE','練習事務局'),(2,'CLUB_REP','練習クラブ代表'),(3,'FINANCE','練習財務');
INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,valid_until,granted_by,approved_by) VALUES
(7,1,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 90 DAY,13,13),
(13,3,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 90 DAY,7,7);
INSERT INTO jmrc_affiliated_clubs(id,club_code,name,short_name,branch_name,format_status,join_date,is_deleted) VALUES(1,'199981','練習更新クラブ','TEST-UPDATE','練習支部','公認クラブ','2026-01-01',0),(2,'299982','別の練習クラブ','TEST-OTHER','練習支部','加盟クラブ','2026-01-01',0);");
// 本番の年度設定は触らず、練習中だけ2027年度の提出期間を現在時刻の前後に設定する。
$db->exec("INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,staff_open_at,member_self_open_at,source_mode,policy_version)
VALUES(2027,'2026-12-31 15:00:00','2099-12-31 15:00:00',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 90 DAY,UTC_TIMESTAMP()+INTERVAL 100 DAY,'2099-01-01','shadow','operator-lab-only')");
foreach([2=>'練習更新クラブ',3=>'別の練習クラブ'] as $id=>$name){
 $s=$db->prepare("INSERT INTO clubs(user_id,name_full,name_short,code,rep_last,rep_first,rep_contact,postcode,address,phone,contact_email,bank_name,bank_branch,bank_account,bank_account_holder) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
 $s->execute([$id,$name,$id===2?'TEST-UPDATE':'TEST-OTHER',$id===2?'199981':'299982','練習','代表','練習担当','0000000','練習専用住所','0000000000',$secrets[$id===2?'club':'other']['email'],'振込禁止・架空銀行','架空支店','0000000','練習']);
}
$box=new \Jmrc\Card\SecretBox($cfg['data_key']);$cipher=$box->seal("【架空・振込禁止】銀行も口座も存在しません。入金確認は画面上の練習だけです。",'jmrc:bank-account:v1:1');
// BankMasterServiceと同じ暗号化の関連値で保存する。実金融機関の口座番号は使わない。
$st=$db->prepare("INSERT INTO portal_bank_accounts(id,purpose_code,year,display_name,details_cipher,enabled,updated_by) VALUES(1,'club_fee',2027,'架空口座・振込禁止',?,1,7)");$st->execute([$cipher]);
echo "synthetic operator data ready\n";
