<?php
// Path: public/manage/index.php
// 機能: 共通認証から会員申請・会費請求・承認へ案内するマイページ。
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
<p><a href="/manage/memberships.php">個人会員の申請</a> / <a href="/manage/temporary-approve.php">主催クラブのTemporary承認</a></p>
<p><a href="/manage/billing.php">会費請求・入金確認・General所属承認</a></p>
<p><a href="/manage/bank.php">ゆうちょ銀行CSV・入金照合</a></p>
<p><a href="/manage/clubs.php">正会員クラブの年度登録・年会費</a></p>
<p><a href="/manage/card.php">Generalデジタル会員証</a></p>
<p><a href="/manage/licenses.php">ライセンス情報の登録・更新</a></p>
<p><a href="/manage/insurance.php">スポーツ安全保険の申請・確認用Excel</a></p>
<form method="post" action="/manage/logout.php">
<input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<button type="submit">サインアウト</button></form></html>
