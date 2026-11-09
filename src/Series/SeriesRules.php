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
        // P07: 成立戦の参加台数平均を先に四捨五入。その1/2を超えない整数人数、上限6名。
        $average=intdiv($totalStarters,$eventCount)+(int)(($totalStarters%$eventCount)*2 >= $eventCount);
        return min(6, intdiv($average,2));
    }

    /** P07: 合計→有効得点の高得点回数→全得点の高得点回数→高得点を得た日。完全同順位は0を維持する。 */
    public static function compare(array $a,array $b,int $rounds): int
    {
        $prepare=static function(array $rows)use($rounds):array{
            if(count($rows)>$rounds || count($rows)>1000){throw new \InvalidArgumentException('成立戦数と結果件数を確認してください');}
            $ids=[];
            foreach($rows as $r){
                if(!is_array($r)||!is_int($r['points']??null)||$r['points']<0||$r['points']>1000000
                    ||!is_string($r['date']??null)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$r['date'])||!is_string($r['event']??null)||$r['event']===''||isset($ids[$r['event']])){throw new \InvalidArgumentException('結果の得点・日付・イベントを確認してください');}
                $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$r['date']);
                if(!$d||$d->format('Y-m-d')!==$r['date']){throw new \InvalidArgumentException('結果の日付を確認してください');}
                $ids[$r['event']]=true;
            }
            usort($rows,static fn($x,$y)=>($y['points']<=>$x['points'])?:strcmp($y['date'],$x['date']));return $rows;
        };
        $count=self::effectiveRounds($rounds);$a=$prepare($a);$b=$prepare($b);
        $ea=array_slice(array_column($a,'points'),0,$count);$eb=array_slice(array_column($b,'points'),0,$count);
        if($c=array_sum($eb)<=>array_sum($ea)){return $c;}
        foreach([[$ea,$eb],[array_column($a,'points'),array_column($b,'points')]] as [$x,$y]){
            $cx=array_count_values($x);$cy=array_count_values($y);$scores=array_unique([...$x,...$y]);rsort($scores,SORT_NUMERIC);
            foreach($scores as $score){if($c=($cy[$score]??0)<=>($cx[$score]??0)){return $c;}}
        }
        foreach($a as $i=>$r){if($c=strcmp($b[$i]['date'],$r['date'])){return $c;}}
        return 0; // P18は3段階で解消する運用。計算上残る0を氏名・IDで勝手に解消しない。
    }

    /** P18: 異なる選手の比較が最後まで同じなら結果の不整合として止め、表彰枠を自動調整しない。 */
    public static function compareDistinct(array $a,array $b,int $rounds): int
    {
        $result=self::compare($a,$b,$rounds);
        if ($result===0) { throw new \InvalidArgumentException('3段階の比較で順位を確定できません。大会結果・得点・開催日を確認してください'); }
        return $result;
    }

    /** P15: 明示された資格停止はポイント無効。入金訂正だけの扱いは未回答なので確認待ちにする。 */
    public static function afterEntry(string $original,?bool $suspended,bool $paymentCorrected=false): string
    {
        if(!in_array($original,['eligible','ineligible','unconfirmed'],true)){throw new \InvalidArgumentException('申込時資格を確認してください');}
        if($suspended===true){return 'ineligible';}
        if($original==='ineligible'){return $original;}
        return $suspended===null||$paymentCorrected?'unconfirmed':$original;
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
