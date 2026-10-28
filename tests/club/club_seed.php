<?php
// Path: tests/club/club_seed.php
// 機能: 旧回帰ケースのクラブ入金証跡と隔離用暗号設定を準備する。CLI専用。
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit(1); }
require '/srv/tests/club_fixture.php';
$db=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','lab_fixture','lab_fixture_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->exec("SET time_zone='+00:00'");
foreach([101,102,103] as $id) { seedClubPaid($db,$id); }
