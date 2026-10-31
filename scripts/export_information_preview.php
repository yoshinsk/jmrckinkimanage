<?php
// Path: scripts/export_information_preview.php
// 機能: 公開前の固定カタログから、認証情報・送信機能のない事務局配布用単一HTMLを出力する。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/Auth/HttpFailure.php';
require_once __DIR__.'/../src/Content/InformationPreview.php';
// 出力先の指定や業務DB接続を受け付けず、標準出力を呼出し側で保存する。
echo \Jmrc\Content\InformationPreview::render([], null, true);
