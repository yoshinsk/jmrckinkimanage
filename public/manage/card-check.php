<?php
// Path: public/manage/card-check.php
// 機能: QRのURL断片をPOSTへ渡して資格を照会する。氏名・番号・年度等の公開項目は保留する。
declare(strict_types=1);
require_once __DIR__.'/_card-public.php';
if ($_SERVER['REQUEST_METHOD']!=='GET') {throw new \Jmrc\Auth\HttpFailure(405,'GET required');}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JMRC近畿 資格照会</title><link rel="stylesheet" href="/manage/assets/cards.css"><script src="/manage/assets/card-check.js" defer></script></head>
<body><main><h1>JMRC近畿 資格照会</h1><p id="verification-status" role="status" aria-live="polite">照会中です。</p><p>現在の資格の有効性だけを表示します。本人確認の代わりにはなりません。</p><button id="check-again" type="button">最新の資格を照会する</button><noscript>照会にはJavaScriptが必要です。</noscript></main></body></html>
