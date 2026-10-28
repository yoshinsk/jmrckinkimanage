<?php
// Path: src/Club/ClubEligibility.php
// 機能: 年度クラブの承認・満額入金・年度期間を正本から評価する。過去statusだけで有効化しない。
declare(strict_types=1);
namespace Jmrc\Club;
use PDO;
use DateTimeImmutable;
use DateTimeZone;
final class ClubEligibility
{
    public function __construct(private readonly PDO $db) {}
    /** 先行受付に必要なreadyと年度内のactiveを分離。移行証跡のない旧approvedも入金済み扱いしない。 */
    public function evaluate(int $id,?DateTimeImmutable $at=null): array
    {
        $s=$this->db->prepare("SELECT cy.status,a.status AS approval,cc.enabled,y.source_mode,y.valid_from,y.valid_until,
            ch.amount_yen,i.status AS invoice_status,COALESCE((SELECT SUM(al.amount_yen) FROM portal_allocations al
            JOIN portal_payments p ON p.id=al.payment_id WHERE al.invoice_line_id=ch.invoice_line_id AND p.status='confirmed'),0) AS paid_yen
            FROM portal_club_years cy JOIN portal_applications a ON a.id=cy.application_id
            JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit JOIN portal_years y ON y.year=cy.year
            LEFT JOIN portal_club_charges ch ON ch.club_year_id=cy.id LEFT JOIN portal_invoices i ON i.id=ch.invoice_id WHERE cy.id=?");
        $s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if (!$r) { throw new \Jmrc\Auth\HttpFailure(404,'年度クラブがありません'); }
        $paid=$r['amount_yen']!==null && (int)$r['paid_yen']===(int)$r['amount_yen'] && in_array($r['invoice_status'],['issued','finalized'],true);
        $approved=$r['approval']==='approved';
        $ready=$paid && $approved && $r['status']==='approved' && (int)$r['enabled']===1 && $r['source_mode']==='portal';
        $time=($at??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        return ['club_year_id'=>$id,'paid'=>$paid,'approved'=>$approved,'ready'=>$ready,
            'active'=>$ready && $r['valid_from']<=$time && $time<$r['valid_until'],'valid_from'=>$r['valid_from'],'valid_until'=>$r['valid_until']];
    }
}
