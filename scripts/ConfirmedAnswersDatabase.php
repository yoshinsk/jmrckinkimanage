<?php
// Path: scripts/ConfirmedAnswersDatabase.php
// 機能: 11月3日の追加表・初期職務設定を限定適用し、変更前後の一致確認付きで復元する。CLI専用。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
use RuntimeException;

final class ConfirmedAnswersDatabase
{
    public function __construct(private readonly PDO $db, string $database)
    {
        $this->need(PHP_SAPI==='cli','CLI required');
        $this->need(in_array($database,['jmrckinki','jmrckinki_answers_lab'],true) && $db->query('SELECT DATABASE()')->fetchColumn()===$database,'Wrong database');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private function need(bool $test,string $message): void { if(!$test) {throw new RuntimeException($message);} }
    private function scalar(string $sql): mixed { return $this->db->query($sql)->fetchColumn(); }
    private function rows(string $sql): array { return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    private function run(string $sql,array $values=[]): void { $s=$this->db->prepare($sql);$s->execute($values); }

    /** 役職定義・権限・grantの全行だけを保持。パスワード、氏名、銀行口座はバックアップへ含めない。 */
    public function snapshot(): array
    {
        $column=(int)$this->scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_user_role_grants' AND column_name='bootstrap_approval_ref'");
        $table=(int)$this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_membership_declarations'");
        return ['roles'=>$this->rows('SELECT * FROM portal_roles ORDER BY id'),
            'permissions'=>$this->rows('SELECT * FROM portal_role_permissions ORDER BY role_id,permission_code'),
            'grants'=>$this->rows('SELECT * FROM portal_user_role_grants ORDER BY id'),
            'bootstrap_column'=>$column===1,'declaration_table'=>$table===1,
            'declaration_rows'=>$table?(int)$this->scalar('SELECT COUNT(*) FROM portal_membership_declarations'):0];
    }

    /** 外部運営者が指定した初期設定。通常の申請のapproved_byは使用せず、根拠参照を別列へ残す。 */
    public function apply(array $before,int $subject,string $migration,string $permissions): array
    {
        $this->need($this->snapshot()===$before,'Database state drift');
        $this->need(!$before['bootstrap_column'] && !$before['declaration_table'],'Migration already applied');
        $s=$this->db->prepare("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'");$s->execute([$subject]);
        $this->need((int)$s->fetchColumn()===$subject && $subject>0,'Initial account unavailable');
        $s=$this->db->prepare("SELECT COUNT(*) FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id WHERE g.user_id=? AND r.code='OFFICE' AND g.scope_type='global' AND g.status='active' AND g.revoked_at IS NULL AND g.approved_by IS NOT NULL AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)");$s->execute([$subject]);
        $this->need((int)$s->fetchColumn()===1,'Initial office grant missing');
        try {
            // DDLは暗黙commitされるため、失敗時は変更した空の構造を明示的に戻す。
            $this->db->exec($migration);
            $this->db->beginTransaction();$this->db->exec($permissions);
            foreach(['COMMITTEE','APPROVER'] as $role) {
                $this->run("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by,bootstrap_approval_ref)
                    SELECT ?,id,'global','active',UTC_TIMESTAMP(6),?,NULL,'user-directed-initial-staff:2026-11-03' FROM portal_roles WHERE code=?",[$subject,$subject,$role]);
            }
            $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,reason,changes_redacted,occurred_at)
                VALUES(NULL,'STAFF_BOOTSTRAP_CONFIGURED','user',?,?,'外部運営者の指定に基づく初期設定。通常の登録申請の承認ではない。','{\"roles\":[\"COMMITTEE\",\"APPROVER\"]}',UTC_TIMESTAMP(6))",[$subject,bin2hex(random_bytes(16))]);
            $this->db->commit();
        } catch(\Throwable $error) {
            if($this->db->inTransaction()) {$this->db->rollBack();}
            $this->removeEmptyStructure();
            $this->need($this->snapshot()===$before,'Failed migration requires manual recovery');
            throw $error;
        }
        return ['before'=>$before,'after'=>$this->snapshot(),'subject'=>$subject];
    }

    /** 記録した適用後状態と完全一致するときだけ、追加grant・役職定義・空の追加構造を戻す。 */
    public function rollback(array $record): void
    {
        $this->need($this->snapshot()===$record['after'],'Later role or declaration changes; stop rollback');
        $this->need($record['after']['declaration_rows']===0,'Declarations exist; keep data');
        $before=$record['before'];$this->db->beginTransaction();
        try {
            $oldGrants=array_column($before['grants'],'id');
            foreach($record['after']['grants'] as $grant) {
                if(!in_array($grant['id'],$oldGrants,true)) {$this->run('DELETE FROM portal_user_role_grants WHERE id=?',[$grant['id']]);}
            }
            $this->db->exec('DELETE FROM portal_role_permissions');
            $oldRoles=array_column($before['roles'],'id');
            foreach($record['after']['roles'] as $role) {
                if(!in_array($role['id'],$oldRoles,true)) {$this->run('DELETE FROM portal_roles WHERE id=?',[$role['id']]);}
            }
            foreach($before['roles'] as $role) {$this->run('UPDATE portal_roles SET code=?,name=?,created_at=?,updated_at=?,row_version=? WHERE id=?',[$role['code'],$role['name'],$role['created_at'],$role['updated_at'],$role['row_version'],$role['id']]);}
            foreach($before['permissions'] as $permission) {$this->run('INSERT INTO portal_role_permissions(role_id,permission_code,created_at) VALUES(?,?,?)',array_values($permission));}
            $this->db->commit();
        } catch(\Throwable $error) {if($this->db->inTransaction()) {$this->db->rollBack();}throw $error;}
        $this->removeEmptyStructure();
        $this->need($this->snapshot()===$before,'Database rollback verification failed');
    }

    /** 新表が空で、初期設定参照がすべて消えている場合に限り、この版の構造だけを撤去する。 */
    private function removeEmptyStructure(): void
    {
        $state=$this->snapshot();
        if($state['bootstrap_column']) {
            $this->need((int)$this->scalar('SELECT COUNT(*) FROM portal_user_role_grants WHERE bootstrap_approval_ref IS NOT NULL')===0,'Initial grants remain');
        }
        if($state['declaration_table']) {
            $this->need($state['declaration_rows']===0,'New declaration data must be retained');
            $this->db->exec('DROP TABLE portal_membership_declarations');
        }
        if($state['bootstrap_column']) {
            $check=(int)$this->scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='portal_user_role_grants' AND constraint_name='chk_external_staff_bootstrap'");
            if($check) {$this->db->exec('ALTER TABLE portal_user_role_grants DROP CONSTRAINT chk_external_staff_bootstrap');}
            $this->db->exec('ALTER TABLE portal_user_role_grants DROP COLUMN bootstrap_approval_ref');
        }
    }
}
