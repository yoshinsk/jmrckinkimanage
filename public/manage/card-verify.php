<?php
// Path: public/manage/card-verify.php
// 機能: 不透明トークンに対応する現在のGeneral資格の真偽だけを匿名POSTへ返す。
declare(strict_types=1);
require_once __DIR__.'/_card-public.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') {throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$active=(new \Jmrc\Card\CardService(jmrc_db(),jmrc_auth_config()))->verify($_POST['token']??null);
header('Content-Type: application/json; charset=utf-8');echo json_encode(['active'=>$active],JSON_THROW_ON_ERROR);
