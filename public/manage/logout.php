<?php
// Path: public/manage/logout.php
// 機能: POST+CSRFでentryとmanageの共通セッションを失効させる。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
jmrc_auth()->logout($_SERVER['REQUEST_METHOD'], $_POST['_csrf'] ?? null);
header('Location: /entry/login.php', true, 303);
