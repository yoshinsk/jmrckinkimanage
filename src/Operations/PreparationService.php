<?php
// Path: src/Operations/PreparationService.php
// 機能: 事務局の運用準備を実DBの読取だけで確認する。受付・請求・権限・削除・設定の変更は行わない。
declare(strict_types=1);
namespace Jmrc\Operations;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Club\ClubFeePolicy;

final class PreparationService
{
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /** セッションや旧entry管理者の役割だけに依存せず、毎回有効な事務局同等権限を確認する。 */
    public function access(int $actor): void
    {
        if (!$this->scalar("SELECT COUNT(*) FROM users u JOIN portal_account_controls c ON c.user_id=u.id
            JOIN portal_user_role_grants g ON g.user_id=u.id JOIN portal_roles r ON r.id=g.role_id
            JOIN portal_role_permissions p ON p.role_id=r.id AND p.permission_code='profiles.correct'
            WHERE u.id=? AND u.email_verified=1 AND c.status='active' AND r.code IN ('OFFICE','FINANCE','COMMITTEE','APPROVER')
            AND g.scope_type='global' AND g.scope_club_id IS NULL AND g.status='active'
            AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL
            AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)",[$actor])) {
            throw new HttpFailure(403,'事務局の確認権限が必要です');
        }
    }

    /** 設定は許可した真偽値だけを公開。鍵・接続先・口座本文・個人情報を返さない。 */
    public function overview(int $actor): array
    {
        $this->access($actor);
        $flags=[];
        foreach (['license_self_service_enabled','insurance_intents_enabled','yucho_import_enabled','staff_role_requests_enabled'] as $key) {
            $flags[$key]=($this->config[$key]??false)===true;
        }
        $years=$this->query('SELECT year,source_mode,club_open_at,club_close_at,member_self_open_at FROM portal_years ORDER BY year DESC',[])->fetchAll(PDO::FETCH_ASSOC);
        $accounts=$this->query("SELECT a.id,a.purpose_code,a.year,a.enabled,a.valid_from,a.valid_until,
            CASE WHEN a.payment_url IS NULL OR a.payment_url='' THEN 0 ELSE 1 END AS has_payment_url,
            l.source_id,s.enabled AS source_enabled FROM portal_bank_accounts a
            LEFT JOIN portal_bank_source_accounts l ON l.account_id=a.id LEFT JOIN portal_bank_sources s ON s.id=l.source_id
            ORDER BY a.id LIMIT 201",[])->fetchAll(PDO::FETCH_ASSOC);
        return ['checked_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
            'years'=>$years,'flags'=>$flags,'accounts'=>array_slice($accounts,0,200),'accounts_truncated'=>count($accounts)>200,
            'counts'=>[
                'clubs'=>$this->scalar('SELECT COUNT(*) FROM portal_clubs',[]),
                'club_years'=>$this->scalar('SELECT COUNT(*) FROM portal_club_years',[]),
                'event_links'=>$this->scalar('SELECT COUNT(*) FROM portal_events WHERE legacy_event_id IS NOT NULL',[]),
                'organizer_links'=>$this->scalar('SELECT COUNT(*) FROM portal_organizer_links',[]),
                'invoices'=>$this->scalar('SELECT COUNT(*) FROM portal_invoices',[]),
                'payments'=>$this->scalar('SELECT COUNT(*) FROM portal_payments',[]),
                'bank_review'=>$this->scalar("SELECT COUNT(*) FROM portal_yucho_rows WHERE status='review'",[]),
            ],'opening_authorized'=>false,'writes_enabled'=>false];
    }

    /**
     * T01: 明示して選んだ料金条件を年会費・入会金の別明細へ変換し、実在クラブの履歴・既存請求と照合する。
     * 履歴が0件でも新規とは断定しない。料金区分を数字コードから推定せず、既存請求も再発行しない。
     */
    public function clubPlan(int $actor,int $club,int $year,string $category,string $membership,string $registration): array
    {
        $this->access($actor);
        if ($club<1 || !$this->scalar('SELECT COUNT(*) FROM portal_clubs WHERE id=?',[$club])) { throw new HttpFailure(404,'対応済みのクラブがありません'); }
        try { $quote=ClubFeePolicy::quote($year,$category,$membership,$registration); }
        catch (\InvalidArgumentException $error) { throw new HttpFailure(422,$error->getMessage()); }
        $lines=[['kind'=>'annual','label'=>'クラブ年会費','amount_yen'=>$quote['annual_yen']]];
        if ($quote['joining_yen']>0) { $lines[]=['kind'=>'joining','label'=>'入会金','amount_yen'=>$quote['joining_yen']]; }
        $prior=$this->query('SELECT year,status FROM portal_club_years WHERE club_id=? AND year<? ORDER BY year DESC',[$club,$year])->fetchAll(PDO::FETCH_ASSOC);
        $current=$this->query('SELECT cy.id,cy.status,ch.invoice_id,ch.amount_yen FROM portal_club_years cy
            LEFT JOIN portal_club_charges ch ON ch.club_year_id=cy.id WHERE cy.club_id=? AND cy.year=?',[$club,$year])->fetch(PDO::FETCH_ASSOC);
        return ['club_id'=>$club,'quote'=>$quote,'lines'=>$lines,'prior_years'=>$prior,'current'=>$current?:null,
            'existing_invoice_amount_matches'=>$current && $current['invoice_id']!==null ? (int)$current['amount_yen']===$quote['total_yen']:null,
            'history_review_required'=>true,'code_mapping_review_required'=>false,'membership_mapping_review_required'=>true,'can_issue'=>false];
    }

    /**
     * T03: 既存entryの開催日・締切日と、明示的なイベント/主催者対応を照合する。
     * 日付だけの旧締切は既存entry同様その日を含める。複数日・不一致・対応未確認は照会可としない。
     */
    public function eventPlan(int $actor,int $legacyId,?\DateTimeImmutable $now=null): array
    {
        $this->access($actor);
        $event=$this->query('SELECT id,event_date,entry_start_date,entry_end_date,organizer_code FROM jmrc_events WHERE id=? AND is_deleted=0',[$legacyId])->fetch(PDO::FETCH_ASSOC);
        if (!$event) { throw new HttpFailure(404,'既存イベントがありません'); }
        $mapped=$this->query('SELECT e.id,e.status,e.starts_at,e.ends_at,cy.club_id FROM portal_events e
            JOIN portal_club_years cy ON cy.id=e.organizer_club_year_id WHERE e.legacy_event_id=?',[$legacyId])->fetch(PDO::FETCH_ASSOC);
        $jst=new \DateTimeZone('Asia/Tokyo');$dates=[];
        foreach (['event_date','entry_end_date'] as $key) {
            $value=$event[$key];$date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value,$jst):false;
            if (!$date || $date->format('Y-m-d')!==$value) { throw new HttpFailure(409,'既存イベントの日付を確認してください'); }
            $dates[$key]=$date;
        }
        $deadline=$dates['entry_end_date']->modify('+1 day');
        $end=$dates['event_date']->setTime(23,59,59,999999);
        $linked=$mapped ? $this->scalar('SELECT COUNT(*) FROM portal_organizer_links l JOIN clubs c ON c.user_id=l.legacy_club_user_id
            WHERE l.portal_club_id=? AND c.code=?',[$mapped['club_id'],$event['organizer_code']]):0;
        $sameDay=false;
        if ($mapped) {
            $utc=new \DateTimeZone('UTC');
            $start=(new \DateTimeImmutable($mapped['starts_at'],$utc))->setTimezone($jst);
            $last=(new \DateTimeImmutable($mapped['ends_at'],$utc))->modify('-1 microsecond')->setTimezone($jst);
            $sameDay=$start->format('Y-m-d')===$event['event_date'] && $last->format('Y-m-d')===$event['event_date'];
        }
        $mappedReady=$mapped && $mapped['status']==='published' && $sameDay && $linked===1;
        $window=\Jmrc\Events\OrganizerEligibilityPolicy::window(true,$now??new \DateTimeImmutable('now',$jst),$deadline,$end);
        return ['entry_year_policy_ok'=>is_string($event['entry_start_date']) && substr($event['entry_start_date'],0,4)===substr($event['event_date'],0,4),
            'legacy_event_id'=>$legacyId,'event_date'=>$event['event_date'],'entry_end_date'=>$event['entry_end_date'],
            'lookup_from'=>$deadline->format(DATE_ATOM),'lookup_until_exclusive'=>$dates['event_date']->modify('+8 days')->format(DATE_ATOM),
            'event_mapping_confirmed'=>(bool)$mappedReady,'window'=>$mappedReady?$window:'unconfirmed',
            'qualification_basis'=>'query_time','points_basis'=>'application_time','organizer_access_enabled'=>false,
            'reason'=>$mappedReady?'接続確認済み。主催者向けの開放は保留です。':'イベント・主催者・開催日の対応を確認してください。'] ;
    }

    /** T04: 内容を復号せず件数のみ棚卸し。候補は削除命令ではなく、保持記録と未確定の複製を分ける。 */
    public function personalDataInventory(int $actor,int $user): array
    {
        $this->access($actor);
        if ($user<1 || !$this->scalar('SELECT COUNT(*) FROM users WHERE id=?',[$user])) { throw new HttpFailure(404,'既存アカウントがありません'); }
        $definitions=[
            ['プロフィール','candidate','SELECT COUNT(*) FROM portal_profiles WHERE user_id=?'],
            ['ライセンス等の識別番号','candidate','SELECT COUNT(*) FROM portal_identifiers WHERE user_id=?'],
            ['会員番号','candidate','SELECT COUNT(*) FROM portal_member_numbers WHERE user_id=?'],
            ['競技・審判員の申告','candidate','SELECT COUNT(*) FROM portal_membership_declarations d JOIN portal_memberships m ON m.id=d.membership_id WHERE m.user_id=?'],
            ['プロフィール訂正履歴','review','SELECT COUNT(*) FROM portal_profile_changes WHERE user_id=?'],
            ['会員証の照会用記録','review','SELECT COUNT(*) FROM portal_card_tokens t JOIN portal_memberships m ON m.id=t.membership_id WHERE m.user_id=?'],
            ['保険帳票の出力記録','review','SELECT COUNT(*) FROM portal_insurance_export_items i JOIN portal_insurance_requests r ON r.id=i.request_id JOIN portal_memberships m ON m.id=r.membership_id WHERE m.user_id=?'],
            ['共通ログイン用アカウント','keep','SELECT COUNT(*) FROM users WHERE id=?'],
            ['会費の請求記録','keep','SELECT COUNT(*) FROM portal_invoices WHERE payer_user_id=?'],
        ];
        $items=[];foreach ($definitions as [$label,$scope,$sql]) { $items[]=['label'=>$label,'scope'=>$scope,'count'=>$this->scalar($sql,[$user])]; }
        return ['user_id'=>$user,'items'=>$items,'execution_enabled'=>false,
            'external_copies'=>'手元のExcel・CSV、メール添付、バックアップはこの件数に含みません。保存先・担当・期間の確認が必要です。',
            'retained'=>'共通アカウント、既存エントリー、請求・入金・操作ログは保持します。'];
    }

    /** 照会時の資格を再計算する。shadow年度を「未加入」と断定せず、過去のポイント権利は計算しない。 */
    public function latestMembership(int $actor,int $user,?\DateTimeImmutable $now=null): array
    {
        $this->access($actor);
        if ($user<1 || !$this->scalar('SELECT COUNT(*) FROM users WHERE id=?',[$user])) { throw new HttpFailure(404,'既存アカウントがありません'); }
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $time=$now->format('Y-m-d H:i:s.u');
        $years=$this->query('SELECT year,source_mode FROM portal_years WHERE valid_from<=? AND ?<valid_until',[$time,$time])->fetchAll(PDO::FETCH_ASSOC);
        $active=null;
        if (count($years)===1 && $years[0]['source_mode']==='portal') {
            $active=false;
            $ids=$this->query('SELECT id FROM portal_memberships WHERE user_id=? AND year=?',[$user,$years[0]['year']])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) { $active=(new \Jmrc\Billing\Eligibility($this->db))->evaluate((int)$id,$now)['active'] || $active; }
        }
        return ['user_id'=>$user,'basis'=>'query_time','checked_at'=>$now->format(DATE_ATOM),'member_active'=>$active,
            'regular_club_active'=>null,'regular_club_reason'=>'正会員・賛助の実データ対応が未確定のため、所属先を数字コードだけで判定しません。',
            'points_recalculated'=>false,'auto_reject'=>false];
    }

    private function query(string $sql,array $args): \PDOStatement { $s=$this->db->prepare($sql);$s->execute($args);return $s; }
    private function scalar(string $sql,array $args): int { return (int)$this->query($sql,$args)->fetchColumn(); }
}
