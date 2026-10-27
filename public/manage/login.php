<?php
// Path: public/manage/login.php
// 機能: manageのログインを既存entryの共通ログイン画面へ誘導する。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
header('Location: /entry/login.php?app=manage&return_key=dashboard', true, 303);
