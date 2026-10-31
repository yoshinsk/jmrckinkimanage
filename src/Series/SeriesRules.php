<?php
// Path: src/Series/SeriesRules.php
// 機能: 追加回答で確定した有効戦数と申込時のポイント権利を判定する。得点の生成・保存・公開は行わない。
declare(strict_types=1);
namespace Jmrc\Series;

final class SeriesRules
{
    /** 開催数の70%を切り上げる。整数演算で誤差を避け、未開催を0戦、負数・桁あふれを入力不正とする。 */
    public static function effectiveRounds(int $heldRounds): int
    {
        if ($heldRounds < 0 || $heldRounds > intdiv(PHP_INT_MAX - 9, 7)) {
            throw new \InvalidArgumentException('開催数を確認してください');
        }
        return intdiv($heldRounds * 7 + 9, 10);
    }

    /** 申込時に保存した確定根拠だけを使用する。現在の所属・入金から過去資格を推測せず、根拠不足は確認待ち。 */
    public static function pointRight(bool $authoritative, ?string $memberType, ?bool $memberActiveAtEntry, ?bool $clubActiveAtEntry): string
    {
        if (!$authoritative) { return 'unconfirmed'; }
        if ($memberType === 'temporary') { return 'ineligible'; }
        if ($memberType !== 'general' || $memberActiveAtEntry === null || $clubActiveAtEntry === null) {
            return 'unconfirmed';
        }
        return $memberActiveAtEntry && $clubActiveAtEntry ? 'eligible' : 'ineligible';
    }
}
