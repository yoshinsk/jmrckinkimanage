<?php
// Path: tests/membership/membership_seed.php
// 機能: 確定回答の検証用に、架空の年度クラブ/UTCイベント/ライセンスを隔離DBへ投入する。
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
$db=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','lab_fixture','lab_fixture_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00'");
$year=(int)gmdate('Y')+1;
$insert=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,3,?,1,?)');
foreach (range(9,14) as $id) { $insert->execute([$id,"u{$id}@example.invalid",password_hash('lab-password-only',PASSWORD_DEFAULT)]); }
$db->exec('INSERT INTO portal_account_controls(user_id) SELECT id FROM users WHERE id>=9');
foreach ([$year,$year+1] as $y) {
    $db->exec("INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,staff_open_at,member_self_open_at,source_mode,policy_version)
        VALUES ($y,'".($y-1)."-12-31 15:00:00','$y-12-31 15:00:00','".($y-1)."-12-01','$y-02-08','".($y-1)."-12-01',UTC_TIMESTAMP()-INTERVAL 1 DAY,'portal','lab')");
}
foreach ([[101,1,$year],[102,2,$year],[103,1,$year+1]] as [$id,$club,$y]) {
    $db->exec("INSERT INTO portal_applications(id,kind,applicant_user_id,year,status,request_key) VALUES($id,'club',2,$y,'approved','club-$id')");
    $db->exec("INSERT INTO portal_club_years(id,club_id,year,category_digit,display_code,application_id,status,rep_name,contact_name,postcode,address,phone,email)
        VALUES ($id,$club,$y,'1','10000$club',$id,'approved','架空','架空','0000000','架空','000','club@example.invalid')");
}
$events=[
    [101,101,$year,"$year-04-01 15:30:00","$year-04-02 03:00:00",'published'],
    [102,102,$year,"$year-04-01 17:00:00","$year-04-02 04:00:00",'published'],
    [103,101,$year,"$year-04-02 15:30:00","$year-04-03 03:00:00",'published'],
    [104,101,$year,"$year-04-03 12:00:00","$year-04-04 02:00:00",'published'],
    [105,101,$year,"$year-05-01 01:00:00","$year-05-01 03:00:00",'draft'],
    [106,103,$year+1,"$year-12-31 15:30:00",($year+1).'-01-01 03:00:00','published'],
    [107,101,$year,"$year-06-01 15:30:00","$year-06-02 03:00:00",'published'],
    [108,102,$year,"$year-06-01 17:30:00","$year-06-02 03:00:00",'published'],
    [109,101,$year,"$year-07-01 01:00:00","$year-07-01 15:00:00",'published'],
];
$st=$db->prepare('INSERT INTO portal_events(id,organizer_club_year_id,year,title,starts_at,ends_at,status) VALUES(?,?,?,CONCAT(\'架空イベント\',?),?,?,?)');
foreach ($events as [$id,$club,$y,$start,$end,$status]) { $st->execute([$id,$club,$y,$id,$start,$end,$status]); }
$st=$db->prepare('INSERT INTO portal_identifiers(id,user_id,kind,value_cipher,lookup_hmac,key_version,expires_on) VALUES(?,?,?,?,UNHEX(SHA2(?,256)),1,?)');
foreach ([[101,3,'jaf_competition','synthetic',null],[102,9,'jaf_official','synthetic',null],
          [103,3,'driving','synthetic',null],[104,10,'jaf_competition','synthetic',null],
          [105,3,'jaf_official','',null],[106,3,'jaf_official','synthetic',gmdate('Y-m-d',time()-86400)],
          [107,3,'jaf','synthetic',null],[112,12,'jaf_competition','synthetic',null],
          [114,14,'driving','synthetic',null]] as [$id,$user,$kind,$value,$expires]) {
    $st->execute([$id,$user,$kind,$value,'lab-'.$id,$expires]);
}
$db->exec("INSERT INTO portal_role_permissions(role_id,permission_code) VALUES(2,'temporary.approve'),(1,'temporary.approve');
    INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,status,valid_from,granted_by,approved_by)
    VALUES(2,2,'club',1,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1);");
echo "membership fixtures ready\n";
