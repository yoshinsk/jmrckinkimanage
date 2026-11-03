<?php
// Path: src/Series/SeriesRules.php
// 機能: 追加回答で確定した有効戦数と申込時のポイント権利を判定する。得点の生成・保存・公開は行わない。
declare(strict_types=1);
namespace Jmrc\Series;

final class SeriesRules
{
    /** C15・2026/11/3回答。成立5戦以下は全戦、それ以上は70%を四捨五入。中止・不成立を除いた数を渡す。 */
    public static function effectiveRounds(int $heldRounds): int
    {
        if ($heldRounds < 0 || $heldRounds > intdiv(PHP_INT_MAX - 9, 7)) {
            throw new \InvalidArgumentException('開催数を確認してください');
        }
        return $heldRounds <= 5 ? $heldRounds : intdiv($heldRounds * 7 + 5, 10);
    }

    /** 出走3台以上でクラス成立。中止は台数によらず不成立とし、申込台数を代用しない。 */
    public static function classEstablished(int $starters, bool $cancelled): bool
    {
        if ($starters < 0) { throw new \InvalidArgumentException('出走台数を確認してください'); }
        return !$cancelled && $starters >= 3;
    }

    /** シリーズ終了時に当該クラス3戦以上が成立している場合だけ年間成立とする。 */
    public static function seriesEstablished(int $establishedRounds, bool $seasonEnded): bool
    {
        self::effectiveRounds($establishedRounds);
        return $seasonEnded && $establishedRounds >= 3;
    }

    /** 確定済み得点から高得点順の有効戦だけを返す。同点順位や得点配点を新たに決めない。 */
    public static function effectiveScores(array $scores, int $establishedRounds): array
    {
        $count = self::effectiveRounds($establishedRounds);
        if (count($scores) > $establishedRounds) { throw new \InvalidArgumentException('成立戦数と得点件数が一致しません'); }
        foreach ($scores as $score) {
            if (!is_int($score) || $score < 0) { throw new \InvalidArgumentException('確定した整数得点を指定してください'); }
        }
        rsort($scores, SORT_NUMERIC);
        return array_slice($scores, 0, $count);
    }

    /** C14の上限の計算例。分母となる年間競技会数は運営確認済みの値を明示し、未確定なら呼び出さない。 */
    public static function awardLimit(int $totalStarters, int $eventCount): int
    {
        if ($totalStarters < 0 || $eventCount < 1 || $eventCount > intdiv(PHP_INT_MAX, 2)) {
            throw new \InvalidArgumentException('年間出走台数と対象競技会数を確認してください');
        }
        // 人数は整数で平均台数の1/2を超えられないため切捨て。正式な受賞者・同点者は選定しない。
        return min(6, intdiv($totalStarters, $eventCount * 2));
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
