<?php
// Path: src/Auth/HttpFailure.php
// 機能: 認証/認可の失敗をHTTP状態コードと固定の公開メッセージで伝える。
declare(strict_types=1);

namespace Jmrc\Auth;

final class HttpFailure extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
