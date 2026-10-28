<?php
// Path: src/Membership/MembershipService.php
// 機能: Generalライセンス条件、Temporary同日制限、主催クラブ承認をトランザクションで実行する。
declare(strict_types=1);

namespace Jmrc\Membership;

use DateTimeImmutable;
use DateTimeZone;
use Jmrc\Auth\HttpFailure;
use PDO;
use PDOException;

final class MembershipService
{
    public function __construct(private readonly PDO $db) {}

    /** 年度内のGeneral申請を新設する。Temporaryからの差額切替や資格有効化は行わない。 */
    public function submitGeneral(int $actorId, int $year, int $clubYearId, int $identifierId, string $requestKey): array
    {
        return $this->transaction(function () use ($actorId,$year,$clubYearId,$identifierId,$requestKey): array {
            $this->actor($actorId);
            $existing = $this->replay($actorId,$requestKey,'general',$year,null,$identifierId,$clubYearId);
            if ($existing) { return $existing; }
            $this->openYear($year);
            $club = $this->one("SELECT cy.id FROM portal_club_years cy
                JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
                WHERE cy.id=? AND cy.year=? AND cy.status='approved' AND cc.enabled=1 FOR UPDATE",[$clubYearId,$year]);
            if (!$club) { throw new HttpFailure(422,'承認済みの年度クラブを選択してください'); }
            $license = $this->one("SELECT * FROM portal_identifiers WHERE id=? AND user_id=?
                AND kind IN ('jaf_competition','jaf_official') AND OCTET_LENGTH(value_cipher)>0 FOR UPDATE",[$identifierId,$actorId]);
            if (!$license) { throw new HttpFailure(422,'Generalは本人のJAF競技・審判員ライセンスが必要です'); }
            $today = (new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            if ($license['expires_on'] !== null && $license['expires_on'] < $today) {
                throw new HttpFailure(422,'期限切れのライセンスは使用できません');
            }
            $application = $this->application($actorId,$year,'general',$requestKey);
            $this->execute("INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind)
                VALUES (?,?,'general',?,?,?)",[$actorId,$year,$application,$identifierId,$license['kind']]);
            $id=(int)$this->db->lastInsertId();
            $this->execute("INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at)
                VALUES (?,?,?,'pending',UTC_TIMESTAMP(6))",[$id,$year,$clubYearId]);
            $this->audit($actorId,'GENERAL_SUBMITTED',$id);
            return $this->result($id,false);
        });
    }

    /** 参加日を申請時に確保。日付はイベントのUTC期間から日本時間で算出し、入力を信用しない。 */
    public function submitTemporary(int $actorId, int $eventId, string $requestKey): array
    {
        return $this->transaction(function () use ($actorId,$eventId,$requestKey): array {
            $this->actor($actorId);
            $existing=$this->replay($actorId,$requestKey,'temporary',null,$eventId,null,null);
            if ($existing) { return $existing; }
            $event=$this->event($eventId);
            $this->openYear((int)$event['year']);
            [$day]=$this->eventDay($event);
            $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            if ($day < $today) { throw new HttpFailure(422,'終了した開催日には申請できません'); }
            $application=$this->application($actorId,(int)$event['year'],'temporary',$requestKey);
            $this->execute("INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id,participation_date)
                VALUES (?,?,'temporary',?,?,?)",[$actorId,$event['year'],$eventId,$application,$day]);
            $id=(int)$this->db->lastInsertId();
            $this->audit($actorId,'TEMPORARY_SUBMITTED',$id);
            return $this->result($id,false);
        });
    }

    /** 主催クラブの明示grantをロックして確認し、申請/所属を同時承認する。入金前はpending。 */
    public function approveTemporary(int $actorId, int $membershipId): array
    {
        return $this->transaction(function () use ($actorId,$membershipId): array {
            $this->actor($actorId);
            $member=$this->one("SELECT m.*,a.status AS application_status FROM portal_memberships m
                JOIN portal_applications a ON a.id=m.application_id WHERE m.id=? AND m.member_type='temporary' FOR UPDATE",[$membershipId]);
            if (!$member) { throw new HttpFailure(404,'対象の申請がありません'); }
            $event=$this->event((int)$member['event_id']);
            // globalな事務局/旧JMRC_ADMINからの自動承認は許さない。承認者はイベント主催クラブに限定する。
            $grant=$this->one("SELECT g.id FROM portal_user_role_grants g
                JOIN portal_role_permissions p ON p.role_id=g.role_id
                WHERE g.user_id=? AND p.permission_code='temporary.approve' AND g.scope_type='club'
                AND g.scope_club_id=? AND g.status='active' AND g.approved_by IS NOT NULL
                AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
                AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1 FOR UPDATE",[$actorId,$event['organizer_club_id']]);
            if (!$grant) { throw new HttpFailure(403,'イベント主催クラブの承認権限が必要です'); }
            [$day,$start,$end]=$this->eventDay($event);
            if ($day!==$member['participation_date']) { throw new HttpFailure(409,'イベント日付が変更されています。事務局へ確認してください'); }
            if (new DateTimeImmutable('now',new DateTimeZone('UTC')) >= $end) { throw new HttpFailure(409,'開催日が終了しています'); }
            if ($member['application_status']==='approved') { return $this->result($membershipId,true); }
            if ($member['application_status']!=='submitted' || $member['status']!=='pending') {
                throw new HttpFailure(409,'現在の状態では承認できません');
            }
            $this->execute("UPDATE portal_applications SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$actorId,$member['application_id']]);
            $this->execute("INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at,decided_at,decided_by,valid_from)
                VALUES (?,?,?,'approved',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,?)",[$membershipId,$member['year'],$event['organizer_club_year_id'],$actorId,$start->format('Y-m-d H:i:s.u')]);
            $this->execute('UPDATE portal_memberships SET valid_from=?,valid_until=?,row_version=row_version+1 WHERE id=?',[$start->format('Y-m-d H:i:s.u'),$end->format('Y-m-d H:i:s.u'),$membershipId]);
            $this->audit($actorId,'TEMPORARY_ORGANIZER_APPROVED',$membershipId);
            return $this->result($membershipId,false);
        });
    }

    /** 申請者単位のロックで同人の再送を直列化。DB制約も他経路の同日重複を拒否する。 */
    private function actor(int $id): void
    {
        if (!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id
            WHERE u.id=? AND u.email_verified=1 AND c.status='active' FOR UPDATE",[$id])) {
            throw new HttpFailure(401,'ログイン状態を確認してください');
        }
    }

    /** 受付期間はマスターの明示値のみ。旧年度や未設定の本人受付を推測で許可しない。 */
    private function openYear(int $year): void
    {
        if (!$this->one("SELECT year FROM portal_years WHERE year=? AND source_mode='portal'
            AND member_self_open_at IS NOT NULL AND member_self_open_at<=UTC_TIMESTAMP(6)
            AND UTC_TIMESTAMP(6)<valid_until FOR UPDATE",[$year])) {
            throw new HttpFailure(422,'この年度は本人登録の受付期間外です');
        }
    }

    private function event(int $id): array
    {
        $event=$this->one("SELECT e.*,cy.club_id AS organizer_club_id FROM portal_events e
            JOIN portal_club_years cy ON cy.id=e.organizer_club_year_id AND cy.year=e.year
            JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
            WHERE e.id=? AND e.status='published' AND cy.status='approved' AND cc.enabled=1 FOR UPDATE",[$id]);
        return $event ?: throw new HttpFailure(422,'承認済み主催クラブの公開イベントを選択してください');
    }

    /** 終端排他的な期間をJST日付に変換。未確定の複数日イベントは拡大解釈せず拒否。 */
    private function eventDay(array $event): array
    {
        $utc=new DateTimeZone('UTC'); $jst=new DateTimeZone('Asia/Tokyo');
        $start=(new DateTimeImmutable($event['starts_at'],$utc))->setTimezone($jst);
        $last=(new DateTimeImmutable($event['ends_at'],$utc))->modify('-1 microsecond')->setTimezone($jst);
        $day=$start->format('Y-m-d');
        if ($day!==$last->format('Y-m-d') || (int)$start->format('Y')!==(int)$event['year']) {
            throw new HttpFailure(422,'複数日または年度不一致のイベントは事務局確認が必要です');
        }
        $midnight=$start->setTime(0,0,0,0);
        return [$day,$midnight->setTimezone($utc),$midnight->modify('+1 day')->setTimezone($utc)];
    }

    /** 冪等キーは本人/種別/対象を照合し、別人のキーや異なる内容への使い回しを拒否。 */
    private function replay(int $actor,string $key,string $kind,?int $year,?int $event,?int $license,?int $club): ?array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) { throw new HttpFailure(422,'申請キーが不正です'); }
        $existing=$this->one('SELECT m.*,a.applicant_user_id,a.kind FROM portal_applications a JOIN portal_memberships m ON m.application_id=a.id WHERE a.request_key=? FOR UPDATE',[$key]);
        if (!$existing) { return null; }
        $same=(int)$existing['applicant_user_id']===$actor && $existing['kind']===$kind
            && ($year===null || (int)$existing['year']===$year) && ($event===null || (int)$existing['event_id']===$event)
            && ($license===null || (int)$existing['general_license_identifier_id']===$license);
        if ($same && $club!==null) {
            $same=(bool)$this->one('SELECT id FROM portal_affiliations WHERE membership_id=? AND club_year_id=?',[$existing['id'],$club]);
        }
        if (!$same) { throw new HttpFailure(409,'申請キーは別の申請で使用済みです'); }
        return $this->result((int)$existing['id'],true);
    }

    private function application(int $actor,int $year,string $kind,string $key): int
    {
        $this->execute("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key,submitted_at) VALUES (?,?,?,'submitted',?,UTC_TIMESTAMP(6))",[$kind,$actor,$year,$key]);
        return (int)$this->db->lastInsertId();
    }

    private function result(int $id,bool $replayed): array
    {
        $row=$this->one('SELECT m.id,m.application_id,m.member_type,m.status,m.participation_date,a.status AS application_status
            FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id WHERE m.id=?',[$id]);
        return $row+['replayed'=>$replayed];
    }

    /** 申請・承認と監査/通知予約を一緒にcommit。出力や外部メールはこの処理内で行わない。 */
    private function audit(int $actor,string $action,int $id): void
    {
        $this->execute("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at)
            VALUES (?,?,'membership',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,bin2hex(random_bytes(16))]);
        $this->execute("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$id,$action,$id]);
    }

    private function one(string $sql,array $values): array|false
    {
        $statement=$this->execute($sql,$values);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function execute(string $sql,array $values): \PDOStatement
    {
        $statement=$this->db->prepare($sql); $statement->execute($values); return $statement;
    }

    /** ロールバック後に重複/参照エラーを業務エラーへ変換。DB障害は上位で503にする。 */
    private function transaction(callable $operation): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Independent membership transaction required'); }
        for ($attempt=0;$attempt<3;$attempt++) {
            $this->db->beginTransaction();
            try { $result=$operation(); $this->db->commit(); return $result; }
            catch (\Throwable $error) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                if ($error instanceof PDOException) {
                    $number=(int)($error->errorInfo[1]??0);
                    if (in_array($number,[1213,1205],true) && $attempt<2) { usleep(20000*($attempt+1)); continue; }
                    if ($number===1062) { throw new HttpFailure(409,'同じ年度または開催日の申請がすでにあります'); }
                    if (in_array($number,[1452,4025],true)) { throw new HttpFailure(422,'申請条件に一致しません'); }
                }
                throw $error;
            }
        }
        throw new HttpFailure(503,'申請を再度お試しください');
    }
}
