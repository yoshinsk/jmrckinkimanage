<?php
// Path: tests/auth/seed.php
// 機能: 隔離DBへ架空アカウントと明示権限のみを作る。CLI専用。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$db = new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4', 'lab_fixture', 'lab_fixture_only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00'; INSERT INTO roles VALUES(1,'JMRC_ADMIN'),(2,'CLUB'),(3,'ENTRANT')");
$insert = $db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,?,?,?,?)');
foreach ([1=>1,2=>2,3=>3,4=>3,5=>3,6=>3,7=>3,8=>3] as $id=>$role) {
    $insert->execute([$id,$role,"u{$id}@example.invalid",$id===4?0:1,password_hash('lab-password-only', PASSWORD_DEFAULT)]);
}
$db->exec("INSERT INTO portal_account_controls(user_id) SELECT id FROM users WHERE id<>6;
    UPDATE portal_account_controls SET status='blocked' WHERE user_id=5;
    INSERT INTO portal_roles(id,code,name) VALUES(1,'OFFICE','事務局'),(2,'CLUB_REP','代表'),(3,'FINANCE','財務');
    INSERT INTO portal_role_permissions(role_id,permission_code) VALUES
    (1,'portal.content.write'),(1,'club.members.read'),(2,'club.members.read'),(2,'club.affiliations.approve'),(3,'billing.confirm'),(3,'billing.correct');
    INSERT INTO portal_clubs(id,fixed_code,name_full,name_short) VALUES(1,'00001','架空1','LAB1'),(2,'00002','架空2','LAB2');
    INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,status,valid_from,granted_by,approved_by) VALUES
    (3,2,'club',1,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1),
    (7,1,'global',NULL,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1),
    (7,3,'global',NULL,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1),
    (8,2,'global',NULL,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1);");
// 主催クラブの旧画面GETを実コードで表示するための架空プロフィール。
$db->exec("INSERT INTO clubs(user_id,name_full,name_short,code,rep_last,rep_first,rep_contact,postcode,address,phone,contact_email,bank_name,bank_branch,bank_account,bank_account_holder)
 VALUES(2,'架空主催','LAB','100001','架空','代表','架空','0000000','架空','000','u2@example.invalid','架空銀行','架空支店','0000000','架空')");
echo "synthetic fixture ready\n";
