<?php
// Path: public/manage/card-check.php
// 機能: QRのURL断片をPOSTへ渡して資格を照会する。有効時のみ氏名・会員番号を表示する。
declare(strict_types=1);
require_once __DIR__.'/_card-public.php';
if ($_SERVER['REQUEST_METHOD']!=='GET') {throw new \Jmrc\Auth\HttpFailure(405,'GET required');}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JMRC近畿 資格照会</title><link rel="stylesheet" href="/manage/assets/cards.css"><script src="/manage/assets/card-check.js" defer></script></head>
<body><main><h1>JMRC近畿 資格照会</h1><p id="verification-status" role="status" aria-live="polite">照会中です。</p><dl id="verification-details" hidden><dt>氏名</dt><dd id="verification-name"></dd><dt>会員番号</dt><dd id="verification-number"></dd></dl><p>有効な資格の氏名・会員番号を表示します。本人確認の代わりにはなりません。</p><button id="check-again" type="button">最新の資格を照会する</button><noscript>照会にはJavaScriptが必要です。</noscript></main></body></html>
