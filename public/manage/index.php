<?php
// Path: public/manage/index.php
// 機能: 共通認証導入段階の最小マイページ。会員資格/保険業務の実装は含まない。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$identity = jmrc_auth()->identity();
if (!$identity) {
    header('Location: /manage/login.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><title>JMRC近畿ポータル</title>
<h1>JMRC近畿ポータル</h1>
<p><?=htmlspecialchars($identity['email'], ENT_QUOTES, 'UTF-8')?></p>
<p><a href="/entry/login.php">オンラインエントリーへ</a></p>
<form method="post" action="/manage/logout.php">
<input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<button type="submit">サインアウト</button></form></html>
