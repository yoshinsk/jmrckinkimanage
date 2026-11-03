<?php
// Path: src/Auth/StaffRolePolicy.php
// 機能: C10/C11の職務名・同等権限・相互承認を定義する。本人認証・grant付与は呼出側が行う。
declare(strict_types=1);
namespace Jmrc\Auth;

final class StaffRolePolicy
{
    public const LABELS = ['OFFICE'=>'事務局', 'COMMITTEE'=>'運営委員', 'APPROVER'=>'承認者'];

    /** 既存FINANCEは移行時の別名。未知のroleや旧entry管理者を事務局へ昇格させない。 */
    public static function canonical(string $role): string
    {
        return $role === 'FINANCE' ? 'COMMITTEE' : $role;
    }

    /** 日常業務は同等。Temporary主催クラブ承認など対象限定の権限をこの判定だけで付与しない。 */
    public static function officeEquivalent(string $role): bool
    {
        return isset(self::LABELS[self::canonical($role)]);
    }

    /** 申請対象本人の承認を最初に拒否。複数役職を兼ねても自己承認には使えない。 */
    public static function canApprove(int $actorId, int $subjectId, array $activeGlobalRoles, string $requestedRole): bool
    {
        if ($actorId <= 0 || $subjectId <= 0 || $actorId === $subjectId) { return false; }
        $required = match (self::canonical($requestedRole)) {
            'OFFICE', 'COMMITTEE' => 'APPROVER',
            'APPROVER' => 'COMMITTEE',
            default => null,
        };
        return $required !== null && in_array($required, array_map(self::canonical(...), $activeGlobalRoles), true);
    }
}
