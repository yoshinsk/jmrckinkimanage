<?php
// Path: src/Billing/Eligibility.php
// 機能: 支払・承認・本人/クラブ/ライセンス/期間を正本から再評価し、自己申告で資格を付与しない。
declare(strict_types=1);
namespace Jmrc\Billing;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Eligibility
{
    public function __construct(private readonly PDO $db) {}

    /** atは内部検証/参照用。公開HTTPから任意時刻を受け取らない。paidとactiveを分離する。 */
    public function evaluate(int $id, ?DateTimeImmutable $at=null): array
    {
        $at=($at??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $st=$this->db->prepare('SELECT m.*,a.status AS approval,u.email_verified,c.status AS account_status,
            y.source_mode,y.valid_from AS year_from,y.valid_until AS year_until
            FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id
            JOIN users u ON u.id=m.user_id LEFT JOIN portal_account_controls c ON c.user_id=u.id
            JOIN portal_years y ON y.year=m.year WHERE m.id=?');
        $st->execute([$id]); $m=$st->fetch(PDO::FETCH_ASSOC);
        if (!$m) { throw new \Jmrc\Auth\HttpFailure(404,'会員申請がありません'); }
        $st=$this->db->prepare("SELECT ch.amount_yen,i.status,COALESCE((SELECT SUM(al.amount_yen)
            FROM portal_allocations al JOIN portal_payments p ON p.id=al.payment_id
            WHERE al.invoice_line_id=ch.invoice_line_id AND p.status='confirmed'),0) AS allocated
            FROM portal_membership_charges ch JOIN portal_invoices i ON i.id=ch.invoice_id WHERE ch.membership_id=?");
        $st->execute([$id]); $bill=$st->fetch(PDO::FETCH_ASSOC);
        $paid=$bill && in_array($bill['status'],['issued','finalized'],true) && (int)$bill['allocated']===(int)$bill['amount_yen'];
        $st=$this->db->prepare("SELECT cy.id,f.valid_from FROM portal_affiliations f JOIN portal_club_years cy ON cy.id=f.club_year_id AND cy.year=f.year
            JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
            WHERE f.membership_id=? AND f.year=? AND f.status='approved' AND f.valid_until IS NULL
            AND cy.status='approved' AND cc.enabled=1");
        $st->execute([$id,$m['year']]); $club=$st->fetch(PDO::FETCH_ASSOC);
        $clubReady=$club && (new \Jmrc\Club\ClubEligibility($this->db))->evaluate((int)$club['id'],$at)['ready'];
        $ready=$paid && $m['approval']==='approved' && $club!==false && (int)$m['email_verified']===1
            && $clubReady
            && $m['account_status']==='active' && $m['status']!=='suspended' && $m['source_mode']==='portal';
        if ($m['member_type']==='general') {
            $st=$this->db->prepare("SELECT expires_on FROM portal_identifiers WHERE id=? AND user_id=?
                AND kind IN ('jaf_competition','jaf_official') AND OCTET_LENGTH(value_cipher)>0");
            $st->execute([$m['general_license_identifier_id'],$m['user_id']]);$license=$st->fetch(PDO::FETCH_ASSOC);
            $day=$at->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
            $ready=$ready && $license && ($license['expires_on']===null || $license['expires_on']>=$day);
            $from=$m['year_from'];$until=$m['year_until'];
        } else {
            $st=$this->db->prepare("SELECT organizer_club_year_id,participation_date,starts_at,ends_at FROM portal_events WHERE id=? AND status='published'");
            $st->execute([$m['event_id']]);$event=$st->fetch(PDO::FETCH_ASSOC);
            $ready=$ready && $event && (int)$club['id']===(int)$event['organizer_club_year_id'] && $m['participation_date']===$event['participation_date'];
            if ($event) {
                $last=(new DateTimeImmutable($event['ends_at'],new DateTimeZone('UTC')))->modify('-1 microsecond')->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
                $ready=$ready && $last===$m['participation_date'];
            }
            $from=$m['valid_from'];$until=$m['valid_until'];
        }
        // 年度・所属の期間を超えて有効にしない。将来の所属開始もreadyとactiveを分離する。
        if ($from!==null) { $from=max($from,$m['year_from'],$club['valid_from']??$from); }
        if ($until!==null) { $until=min($until,$m['year_until']); }
        $time=$at->format('Y-m-d H:i:s.u');
        $active=(bool)($ready && $from!==null && $until!==null && $from<=$time && $time<$until);
        return ['membership_id'=>$id,'paid'=>(bool)$paid,'approved'=>$m['approval']==='approved',
            'ready'=>(bool)$ready,'active'=>$active,'valid_from'=>$from,'valid_until'=>$until];
    }

    /** キャッシュ状態は補助情報。利用時は必ずevaluateも実行し、期間経過/外部訂正を即時反映する。 */
    public function refresh(int $id): array
    {
        $state=$this->evaluate($id);
        $st=$this->db->prepare("UPDATE portal_memberships SET status=?,valid_from=?,valid_until=?,
            activated_at=IF(?=1,COALESCE(activated_at,UTC_TIMESTAMP(6)),activated_at),row_version=row_version+1
            WHERE id=? AND status<>'suspended'");
        $st->execute([$state['active']?'active':'pending',$state['valid_from'],$state['valid_until'],(int)$state['active'],$id]);
        return $state;
    }
}
