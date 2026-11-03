<?php
// Path: src/Events/OrganizerEligibilityPolicy.php
// 機能: 主催者の照会期間と最新資格2項目の表示を判定する純粋関数。権限の付与・申込停止・DB照会は行わない。
declare(strict_types=1);
namespace Jmrc\Events;

final class OrganizerEligibilityPolicy
{
    /** C12: 担当イベントの締切から、終了日の7日後23:59まで（日本時間）。欠損時刻を補完しない。 */
    public static function window(bool $authorized, \DateTimeImmutable $now, ?\DateTimeImmutable $deadline, ?\DateTimeImmutable $eventEnd): string
    {
        if (!$authorized) { return 'forbidden'; }
        if ($deadline === null || $eventEnd === null || $eventEnd < $deadline) { return 'unconfirmed'; }
        if ($now < $deadline) { return 'not_open'; }
        // JSTで日を足し、夏時間のあるタイムゾーンから渡された日時にも同じ国内業務期間を適用する。
        $until = $eventEnd->setTimezone(new \DateTimeZone('Asia/Tokyo'))->setTime(0, 0)->add(new \DateInterval('P8D'));
        return $now < $until ? 'open' : 'expired';
    }

    /** 照会時の最新資格だけを渡す。未確認を未加入と断定せず、ポイント権利や申込受理を変更しない。 */
    public static function display(string $window, ?bool $memberActiveNow, ?bool $clubActiveNow): array
    {
        if ($window !== 'open') { return ['visible'=>false, 'window'=>$window, 'auto_reject'=>false]; }
        $label = static fn(?bool $value): string => $value === null ? '確認できません' : ($value ? 'はい' : 'いいえ');
        return ['visible'=>true, 'basis'=>'query_time', 'member'=>$label($memberActiveNow),
            'club'=>$label($clubActiveNow), 'caution'=>$memberActiveNow !== true || $clubActiveNow !== true, 'auto_reject'=>false];
    }
}
