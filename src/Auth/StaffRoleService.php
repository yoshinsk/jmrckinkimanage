<?php
// Path: src/Auth/StaffRoleService.php
// 機能: 既存アカウントの職務申請と相互承認をDBの最新grantで検証する。初期権限付与はこのAPIで代行しない。
declare(strict_types=1);
namespace Jmrc\Auth;
use PDO;

final class StaffRoleService
{
    public function __construct(private readonly PDO $db, private readonly bool $enabled = false) {}

    /** 自分の役職と、現在の相互承認権限で確認できる申請だけを返す。受付停止中も読取は可能。 */
    public function overview(int $actor): array
    {
        $this->identity($actor);
        $roles=$this->activeRoles($actor);
        $allowed=[];
        foreach (array_keys(StaffRolePolicy::LABELS) as $role) {
            // 一覧の対象役職を求めるだけ。各行の自己承認は別途同じPolicyで拒否する。
            if (StaffRolePolicy::canApprove(1, 2, $roles, $role)) { $allowed[]=$role; }
        }
        $where='g.user_id=?';$params=[$actor];
        if ($allowed) { $where.=' OR (g.status=\'pending\' AND r.code IN ('.implode(',',array_fill(0,count($allowed),'?')).'))';$params=[...$params,...$allowed]; }
        $rows=$this->query("SELECT g.id,g.user_id,r.code,g.status,g.row_version,u.email,g.valid_from,g.valid_until,g.revoked_at,
            (u.email_verified=1 AND c.status='active') AS subject_active
            FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id JOIN users u ON u.id=g.user_id
            LEFT JOIN portal_account_controls c ON c.user_id=u.id
            WHERE g.scope_type='global' AND g.scope_club_id IS NULL AND ($where)
            ORDER BY g.id DESC LIMIT 201",$params)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>200;$rows=array_slice($rows,0,200);
        foreach ($rows as &$row) {
            $row['effective']=$row['status']==='active' && in_array($row['code'],$roles,true);
            $row['can_approve']=(bool)$row['subject_active'] && $row['status']==='pending' && $row['revoked_at']===null && $row['valid_until']===null
                && StaffRolePolicy::canApprove($actor,(int)$row['user_id'],$roles,$row['code']);
        }
        return ['enabled'=>$this->enabled,'roles'=>$roles,'grants'=>$rows,'truncated'=>$more];
    }

    /** 現在有効な全体職務だけを返す。本人の停止、未承認、期限切れ、クラブ限定職務を除外する。 */
    public function activeRoles(int $actor): array
    {
        $this->identity($actor);
        return $this->query("SELECT r.code FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id
            WHERE g.user_id=? AND g.scope_type='global' AND g.scope_club_id IS NULL AND g.status='active'
            AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL
            AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)",[$actor])->fetchAll(PDO::FETCH_COLUMN);
    }

    /** 本人が希望する役職をpendingで作る。旧entry役割からの自動昇格は行わない。 */
    public function request(int $actor, string $role): array
    {
        return $this->tx(function () use ($actor,$role): array {
            $this->identity($actor,true);
            if (!isset(StaffRolePolicy::LABELS[$role])) { throw new HttpFailure(422,'申請する役職を確認してください'); }
            $id=$this->query('SELECT id FROM portal_roles WHERE code=?',[$role])->fetchColumn();
            if (!$id) { throw new HttpFailure(503,'役職マスターの準備が必要です'); }
            $old=$this->query("SELECT * FROM portal_user_role_grants WHERE user_id=? AND role_id=? AND scope_type='global' FOR UPDATE",[$actor,$id])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ($old['status']==='pending') { return ['id'=>(int)$old['id'],'status'=>'pending','replayed'=>true]; }
                throw new HttpFailure(409,'登録済みの権限を確認してください。取消済みの再申請は事務局へ連絡してください');
            }
            $this->query("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by)
                VALUES(?,?,'global','pending',UTC_TIMESTAMP(6),?)",[$actor,$id,$actor]);
            $grant=(int)$this->db->lastInsertId();$this->audit($actor,'STAFF_ROLE_REQUESTED',$grant);
            return ['id'=>$grant,'status'=>'pending','replayed'=>false];
        });
    }

    /** 対象grantと実行者の現行grantをロック。対象本人・失効・別スコープ・承認後再実行を拒否する。 */
    public function approve(int $actor, int $grantId, int $version): array
    {
        return $this->tx(function () use ($actor,$grantId,$version): array {
            $this->identity($actor,true);
            $g=$this->query("SELECT g.*,r.code FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id WHERE g.id=? FOR UPDATE",[$grantId])->fetch(PDO::FETCH_ASSOC);
            if (!$g) { throw new HttpFailure(404,'申請がありません'); }
            $this->identity((int)$g['user_id'],true);
            $roles=$this->query("SELECT r.code FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id
                WHERE g.user_id=? AND g.scope_type='global' AND g.scope_club_id IS NULL AND g.status='active'
                AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
                AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) FOR UPDATE",[$actor])->fetchAll(PDO::FETCH_COLUMN);
            if (!StaffRolePolicy::canApprove($actor,(int)$g['user_id'],$roles,$g['code']) || $g['scope_type']!=='global' || $g['scope_club_id']!==null) {
                throw new HttpFailure(403,'この役職の承認権限がありません。自己承認もできません');
            }
            if ($g['status']!=='pending' || (int)$g['row_version']!==$version || $g['revoked_at']!==null
                || $g['approved_by']!==null || $g['valid_until']!==null) { throw new HttpFailure(409,'申請内容が更新されています'); }
            $this->query("UPDATE portal_user_role_grants SET status='active',approved_by=?,valid_from=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$actor,$grantId]);
            $this->audit($actor,'STAFF_ROLE_APPROVED',$grantId);
            return ['id'=>$grantId,'status'=>'active','row_version'=>$version+1];
        });
    }

    /** DBの本人状態を毎回検査。認証済みセッション内のキャッシュだけでは判断しない。 */
    private function identity(int $id, bool $lock=false): void
    {
        if (!$this->query("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id
            WHERE u.id=? AND u.email_verified=1 AND c.status='active'".($lock?' FOR UPDATE':''),[$id])->fetchColumn()) {
            throw new HttpFailure(403,'有効な既存アカウントが必要です');
        }
    }
    private function query(string $sql, array $values): \PDOStatement
    {
        $s=$this->db->prepare($sql);$s->execute($values);return $s;
    }
    /** 権限の変更と監査を同じトランザクションに入れ、個人情報や資格番号をログへ写さない。 */
    private function audit(int $actor,string $action,int $grant): void
    {
        $this->query("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at)
            VALUES(?,?,'staff_role',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$grant,bin2hex(random_bytes(16))]);
    }
    private function tx(callable $operation): array
    {
        if (!$this->enabled) { throw new HttpFailure(503,'役職の申請受付は準備中です'); }
        if ($this->db->inTransaction()) { throw new \LogicException('Independent staff transaction required'); }
        $this->db->beginTransaction();
        try { $result=$operation();$this->db->commit();return $result; }
        catch (\Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); }throw $error; }
    }
}
