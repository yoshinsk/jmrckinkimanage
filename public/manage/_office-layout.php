<?php
// Path: public/manage/_office-layout.php
// 機能: 認証済みの会員・事務局画面に、マイページと共通のヘッダー・戻り先・フッターを出力する。
declare(strict_types=1);
// この補助ファイル自体を公開画面として実行しない。
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/** 本人確認・画面ごとの権限検査後に利用する。既存事務局画面の呼出しはそのまま維持する。 */
function jmrc_office_header(string $title, string $section = '事務局'): void
{
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars($section, ENT_QUOTES, 'UTF-8');
    $csrf = htmlspecialchars(jmrc_auth()->csrfToken(), ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="ja"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$title?> | JMRC近畿ポータル</title>
  <link rel="stylesheet" href="/manage/assets/dashboard.css?v=20261030">
  <link rel="stylesheet" href="/manage/assets/office.css?v=20261030-2">
</head><body>
<a class="skip-link" href="#main">本文へ移動</a>
<header class="portal-header"><div class="portal-container portal-header-inner">
  <a class="portal-brand" href="/manage/" aria-label="JMRC近畿ポータル マイページ">JMRC近畿ポータル</a>
  <form method="post" action="/manage/logout.php"><input type="hidden" name="_csrf" value="<?=$csrf?>"><button class="signout" type="submit">サインアウト</button></form>
</div></header>
<main id="main" class="portal-container office-page" tabindex="-1">
<nav class="office-breadcrumb" aria-label="現在の位置"><a href="/manage/">マイページへ</a><span aria-hidden="true">/</span><span><?=$section?></span></nav>
<h1><?=$title?></h1>
    <?php
}

/** 事務局画面を閉じ、下端にもマイページへの導線を確保する。 */
function jmrc_office_footer(): void
{
    ?>
<nav class="office-bottom" aria-label="ページ下部"><a class="office-button secondary" href="/manage/">マイページへ戻る</a></nav>
</main><footer class="portal-footer"><div class="portal-container"><small>&copy; <?=gmdate('Y')?> JMRC近畿ポータル</small></div></footer>
</body></html>
    <?php
}
