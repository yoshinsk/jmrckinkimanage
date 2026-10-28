<?php
// Path: tests/club/club_fixture.php
// 機能: 既存回帰試験の承認済み架空クラブへ、明示的な架空会費入金証跡を用意する。
declare(strict_types=1);
/** 1円はテスト上の架空既存入金。実際のクラブ会費や本番移行値ではない。 */
function seedClubPaid(PDO $db,int $clubYear): void
{
    $account=$db->query("SELECT id FROM portal_bank_accounts WHERE purpose_code='club_fee' LIMIT 1")->fetchColumn();
    if (!$account) {
        $db->exec("INSERT INTO portal_bank_accounts(purpose_code,display_name,details_cipher,enabled,updated_by) VALUES('club_fee','架空移行口座','fixture',1,1)");$account=(int)$db->lastInsertId();
    }
    $app=$db->query("SELECT application_id FROM portal_club_years WHERE id=$clubYear")->fetchColumn();
    $db->exec("INSERT INTO portal_invoices(application_id,invoice_no,payer_club_year_id,status,account_id,account_snapshot_cipher,idempotency_key,issued_at,finalized_at)
      VALUES($app,'FIXTURE-CLUB-$clubYear',$clubYear,'finalized',$account,'fixture','fixture-club:$clubYear',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");$invoice=(int)$db->lastInsertId();
    $db->exec("INSERT INTO portal_invoice_lines(invoice_id,purpose_code,club_year_id,description,quantity,unit_amount_yen,amount_yen) VALUES($invoice,'club_fee',$clubYear,'架空既存会費',1,1,1)");$line=(int)$db->lastInsertId();
    $db->exec("INSERT INTO portal_club_charges(club_year_id,invoice_id,invoice_line_id,amount_yen) VALUES($clubYear,$invoice,$line,1);
      INSERT INTO portal_payments(account_id,source_type,source_key,received_at,amount_yen,payer_name_cipher,status,confirmed_by,confirmed_at)
      VALUES($account,'manual','FIXTURE-CLUB-$clubYear',UTC_TIMESTAMP(6),1,'','confirmed',1,UTC_TIMESTAMP(6))");$payment=(int)$db->lastInsertId();
    $db->exec("INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,created_by) VALUES($payment,$line,1,'fixture-club-$clubYear',1)");
}
