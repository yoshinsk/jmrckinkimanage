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
    public function __construct(private readonly PDO $db,private readonly array $config=[]) {}

    /** P01/P19: 本人が変更先を申請する。承認前は現在の所属・番号・請求を変更しない。 */
    public function requestClubChange(int $actorId,int $membershipId,int $destinationYearId): array
    {
        return $this->transaction(function () use ($actorId,$membershipId,$destinationYearId): array {
            $this->actor($actorId);
            $m=$this->one("SELECT m.* FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id
                WHERE m.id=? AND m.user_id=? AND m.member_type='general' AND a.status='approved' FOR UPDATE",[$membershipId,$actorId]);
            if (!$m) { throw new HttpFailure(404,'本人の承認済みGeneral資格がありません'); }
            $this->openYear((int)$m['year']);
            $this->changeDestination($destinationYearId,(int)$m['year']);
            $current=$this->one("SELECT id,club_year_id FROM portal_affiliations WHERE membership_id=? AND status='approved' AND valid_until IS NULL FOR UPDATE",[$membershipId]);
            if (!$current || (int)$current['club_year_id']===$destinationYearId) { throw new HttpFailure(409,'現在と異なる変更先クラブを選択してください'); }
            $pending=$this->one("SELECT id,club_year_id,row_version FROM portal_affiliations WHERE membership_id=? AND status='pending' FOR UPDATE",[$membershipId]);
            if ($pending) {
                if ((int)$pending['club_year_id']!==$destinationYearId) { throw new HttpFailure(409,'別の所属変更が承認待ちです'); }
                return ['id'=>(int)$pending['id'],'row_version'=>(int)$pending['row_version'],'replayed'=>true];
            }
            $this->execute("INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at) VALUES(?,?,?,'pending',UTC_TIMESTAMP(6))",[$membershipId,$m['year'],$destinationYearId]);
            $id=(int)$this->db->lastInsertId();
            $this->changeAudit($actorId,'AFFILIATION_CHANGE_REQUESTED',$id);
            return ['id'=>$id,'row_version'=>1,'replayed'=>false];
        });
    }

    /** 変更先代表または事務局代行だけが承認する。変更元代表の承認・連絡は条件にしない。 */
    public function approveClubChange(int $actorId,int $affiliationId,int $version): array
    {
        return $this->transaction(function () use ($actorId,$affiliationId,$version): array {
            $this->actor($actorId);
            $link=$this->one('SELECT membership_id FROM portal_affiliations WHERE id=?',[$affiliationId]);
            if (!$link) { throw new HttpFailure(404,'所属変更の申請がありません'); }
            // 同じ会員の申請・承認を会員行のロックで直列化する。
            $m=$this->one("SELECT m.* FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id
                WHERE m.id=? AND m.member_type='general' AND a.status='approved' FOR UPDATE",[$link['membership_id']]);
            if (!$m) { throw new HttpFailure(409,'会員申請の承認状態を確認してください'); }
            $this->openYear((int)$m['year']);
            $next=$this->one('SELECT * FROM portal_affiliations WHERE id=? FOR UPDATE',[$affiliationId]);
            $target=$this->changeDestination((int)$next['club_year_id'],(int)$m['year']);
            $this->affiliationApprover($actorId,(int)$target['club_id']);
            if ($next['status']==='approved' && $next['valid_until']===null) { return ['id'=>$affiliationId,'replayed'=>true]; }
            if ($next['status']!=='pending' || (int)$next['row_version']!==$version) { throw new HttpFailure(409,'所属変更の申請を開き直してください'); }
            $old=$this->one("SELECT * FROM portal_affiliations WHERE membership_id=? AND status='approved' AND valid_until IS NULL FOR UPDATE",[$m['id']]);
            if (!$old || (int)$old['club_year_id']===(int)$next['club_year_id']) { throw new HttpFailure(409,'現在の所属を確認してください'); }
            $time=(string)$this->one('SELECT UTC_TIMESTAMP(6) AS now',[])['now'];
            if ($old['valid_from']>=$time) { throw new HttpFailure(409,'所属期間を確認してから再度承認してください'); }
            $this->execute('UPDATE portal_affiliations SET valid_until=?,row_version=row_version+1 WHERE id=?',[$time,$old['id']]);
            $this->execute("UPDATE portal_affiliations SET status='approved',valid_from=?,decided_at=?,decided_by=?,row_version=row_version+1 WHERE id=?",[$time,$time,$actorId,$affiliationId]);
            // 所属変更と同じ取引で旧QRを失効し、発行済みの番号だけを新クラブコードへ更新する。
            (new \Jmrc\Card\CardService($this->db,$this->config))->replaceNumberForClubChange($actorId,(int)$m['id']);
            (new \Jmrc\Billing\Eligibility($this->db))->refresh((int)$m['id']);
            $this->changeAudit($actorId,'AFFILIATION_CHANGE_APPROVED',$affiliationId);
            return ['id'=>$affiliationId,'replayed'=>false,'card_reissue_required'=>true];
        });
    }

    /** 変更先は同じ年度の承認済みクラブに限定し、表示コードや旧entry代表IDから推定しない。 */
    private function changeDestination(int $id,int $year): array
    {
        return $this->one("SELECT cy.club_id FROM portal_club_years cy JOIN portal_club_categories c ON c.category_digit=cy.category_digit
            WHERE cy.id=? AND cy.year=? AND cy.status='approved' AND c.enabled=1 FOR UPDATE",[$id,$year])
            ?: throw new HttpFailure(422,'同じ年度の承認済み変更先クラブを選択してください');
    }

    /** 担当クラブ付きの承認権限、または明示された事務局同等権限を最新状態から判定する。 */
    private function affiliationApprover(int $actorId,int $clubId): void
    {
        if (!$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_role_permissions p ON p.role_id=g.role_id
            JOIN portal_roles r ON r.id=g.role_id WHERE g.user_id=? AND p.permission_code='club.affiliations.approve'
            AND ((g.scope_type='club' AND g.scope_club_id=?) OR (g.scope_type='global' AND g.scope_club_id IS NULL AND r.code IN ('OFFICE','FINANCE','COMMITTEE','APPROVER')))
            AND g.status='active' AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL
            AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1 FOR UPDATE",[$actorId,$clubId])) {
            throw new HttpFailure(403,'変更先クラブの代表または事務局の承認権限が必要です');
        }
    }

    /** 変更履歴は所属申請IDで記録する。会費請求や外部メール送信は行わない。 */
    private function changeAudit(int $actorId,string $action,int $id): void
    {
        $this->execute("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at)
            VALUES (?,?,'affiliation',?,?,'{}',UTC_TIMESTAMP(6))",[$actorId,$action,$id,bin2hex(random_bytes(16))]);
        $this->execute("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES(?,?,?,'{}')",[$action.':'.$id,$action,$id]);
    }

    /** 所属クラブ代表または明示された事務局の所属承認権限でGeneralを承認する。 */
    public function approveGeneral(int $actorId,int $membershipId): array
    {
        return $this->transaction(function () use ($actorId,$membershipId): array {
            $this->actor($actorId);
            $m=$this->one("SELECT m.*,a.status AS approval,f.id AS affiliation_id,cy.club_id FROM portal_memberships m
                JOIN portal_applications a ON a.id=m.application_id JOIN portal_affiliations f ON f.membership_id=m.id AND f.valid_until IS NULL
                JOIN portal_club_years cy ON cy.id=f.club_year_id JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
                WHERE m.id=? AND m.member_type='general' AND cy.status='approved' AND cc.enabled=1 FOR UPDATE",[$membershipId]);
            if (!$m) { throw new HttpFailure(404,'承認対象がありません'); }
            $grant=$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_role_permissions p ON p.role_id=g.role_id JOIN portal_roles r ON r.id=g.role_id
                WHERE g.user_id=? AND p.permission_code='club.affiliations.approve' AND ((g.scope_type='global' AND r.code IN ('OFFICE','FINANCE','COMMITTEE','APPROVER')) OR (g.scope_type='club' AND g.scope_club_id=?))
                AND g.status='active' AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
                AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1 FOR UPDATE",[$actorId,$m['club_id']]);
            if (!$grant) { throw new HttpFailure(403,'所属クラブまたは事務局の承認権限が必要です'); }
            if ($m['approval']==='approved') { return $this->result($membershipId,true); }
            if ($m['approval']!=='submitted' || $m['status']!=='pending') { throw new HttpFailure(409,'現在の状態では承認できません'); }
            $this->execute("UPDATE portal_applications SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$actorId,$m['application_id']]);
            $this->execute("UPDATE portal_affiliations SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(6),valid_from=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?",[$actorId,$m['affiliation_id']]);
            (new \Jmrc\Billing\Eligibility($this->db))->refresh($membershipId);
            $this->audit($actorId,'GENERAL_AFFILIATION_APPROVED',$membershipId);
            return $this->result($membershipId,false);
        });
    }

    /** 年度内のGeneral申請を新設する。Temporaryからの差額切替や資格有効化は行わない。 */
    public function submitGeneral(int $actorId, int $year, int $clubYearId, int $identifierId, string $requestKey, array $declaration = []): array
    {
        return $this->transaction(function () use ($actorId,$year,$clubYearId,$identifierId,$requestKey,$declaration): array {
            $this->actor($actorId);
            $existing = $this->replay($actorId,$requestKey,'general',$year,null,$identifierId,$clubYearId);
            if ($existing) {
                $saved=$this->one('SELECT competition_type,official_type FROM portal_membership_declarations WHERE membership_id=?',[$existing['id']]);
                if (!$saved || $saved !== array_map(static fn($v)=>is_string($v)?trim($v):$v,['competition_type'=>$declaration['competition_type']??null,'official_type'=>$declaration['official_type']??null])) {
                    throw new HttpFailure(409,'申告内容が異なります。既存申請を確認してください');
                }
                return $existing;
            }
            $this->openYear($year);
            $club = $this->one("SELECT cy.id FROM portal_club_years cy
                JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
                WHERE cy.id=? AND cy.year=? AND cy.status='approved' AND cc.enabled=1 FOR UPDATE",[$clubYearId,$year]);
            if (!$club) { throw new HttpFailure(422,'承認済みの年度クラブを選択してください'); }
            $license = $this->one("SELECT * FROM portal_identifiers WHERE id=? AND user_id=?
                AND kind IN ('jaf_competition','jaf_official') AND OCTET_LENGTH(value_cipher)>0 FOR UPDATE",[$identifierId,$actorId]);
            if (!$license) { throw new HttpFailure(422,'Generalは本人のJAF競技・審判員ライセンスが必要です'); }
            $declared=LicenseDeclaration::validate($declaration,$license['kind']);
            $today = (new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            if ($license['expires_on'] !== null && $license['expires_on'] < $today) {
                throw new HttpFailure(422,'期限切れのライセンスは使用できません');
            }
            $application = $this->application($actorId,$year,'general',$requestKey);
            $this->execute("INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind)
                VALUES (?,?,'general',?,?,?)",[$actorId,$year,$application,$identifierId,$license['kind']]);
            $id=(int)$this->db->lastInsertId();
            $this->execute('INSERT INTO portal_membership_declarations(membership_id,competition_type,official_type) VALUES(?,?,?)',[$id,$declared['competition_type'],$declared['official_type']]);
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
                AND g.scope_club_id=? AND g.status='active' AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL)
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
            (new \Jmrc\Billing\Eligibility($this->db))->refresh($membershipId);
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
