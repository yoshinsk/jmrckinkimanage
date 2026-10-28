<?php
// Path: src/Billing/BillingService.php
// 機能: 会費の一意請求、事務局/財務の入金確認・逆仕訳と資格再判定を同一取引で行う。
declare(strict_types=1);
namespace Jmrc\Billing;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;

final class BillingService
{
    public function __construct(private readonly PDO $db) {}

    /** 年度クラブごとに一件だけ発行。金額は年度・区分のマスターから取得し、仮の金額を採用しない。 */
    public function issueClub(int $actor,int $clubYear): array
    {
        return $this->tx(function () use ($actor,$clubYear): array {
            $cy=$this->one('SELECT * FROM portal_club_years WHERE id=? FOR UPDATE',[$clubYear])?:throw new HttpFailure(404,'年度クラブがありません');
            $this->clubAccess($actor,(int)$cy['club_id']);
            $old=$this->one('SELECT invoice_id FROM portal_club_charges WHERE club_year_id=?',[$clubYear]);
            if ($old) { return $this->invoice((int)$old['invoice_id']); }
            $a=$this->one('SELECT status FROM portal_applications WHERE id=? FOR UPDATE',[$cy['application_id']]);
            // 既存承認を取り込んだだけの年度に、会費を再請求しない。移行時の照合を先に行う。
            if ($a['status']==='approved') { throw new HttpFailure(409,'既存年度登録の会費証跡を事務局で照合してください'); }
            if (!in_array($a['status'],['submitted','approved'],true) || !in_array($cy['status'],['pending','approved'],true)) { throw new HttpFailure(409,'現在の状態では請求できません'); }
            if (!$this->one("SELECT year FROM portal_years WHERE year=? AND source_mode='portal' AND UTC_TIMESTAMP(6)<valid_until FOR UPDATE",[$cy['year']])) { throw new HttpFailure(409,'年度会費の対象外です'); }
            if (!$this->one('SELECT category_digit FROM portal_club_categories WHERE category_digit=? AND enabled=1',[$cy['category_digit']])) { throw new HttpFailure(422,'クラブ区分を確認してください'); }
            $rules=$this->all("SELECT * FROM portal_fee_rules WHERE year=? AND purpose_code='club_fee' AND member_type=? AND charge_unit='year'
                AND enabled=1 AND valid_from<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<valid_until FOR UPDATE",[$cy['year'],'club_'.$cy['category_digit']]);
            $accounts=$this->all("SELECT * FROM portal_bank_accounts WHERE purpose_code='club_fee' AND (year=? OR year IS NULL) AND enabled=1
                AND (valid_from IS NULL OR valid_from<=UTC_TIMESTAMP(6)) AND (valid_until IS NULL OR UTC_TIMESTAMP(6)<valid_until) FOR UPDATE",[$cy['year']]);
            if (count($rules)!==1 || (int)$rules[0]['amount_yen']<=0) { throw new HttpFailure(422,'年度・クラブ区分の年会費マスターを設定してください'); }
            if (count($accounts)!==1) { throw new HttpFailure(422,'クラブ年会費の入金先口座を設定してください'); }
            $rule=$rules[0];$bank=$accounts[0];
            if ($bank['payment_url']!==null && !preg_match('~^https://[^\s]+$~D',$bank['payment_url'])) { throw new HttpFailure(422,'決済URLはHTTPSで登録してください'); }
            $this->run("INSERT INTO portal_invoices(application_id,invoice_no,payer_club_year_id,status,account_id,account_snapshot_cipher,payment_url_snapshot,idempotency_key,issued_at)
                VALUES(?,?,?,'issued',?,?,?,?,UTC_TIMESTAMP(6))",[$cy['application_id'],'CLUB-'.$cy['year'].'-'.$clubYear,$clubYear,$bank['id'],$bank['details_cipher'],$bank['payment_url'],'club:'.$clubYear]);
            $invoice=(int)$this->db->lastInsertId();
            $this->run("INSERT INTO portal_invoice_lines(invoice_id,fee_rule_id,purpose_code,club_year_id,description,quantity,unit_amount_yen,amount_yen)
                VALUES(?,?,'club_fee',?,?,1,?,?)",[$invoice,$rule['id'],$clubYear,'正会員クラブ年会費 '.$cy['year'],$rule['amount_yen'],$rule['amount_yen']]);$line=(int)$this->db->lastInsertId();
            $this->run('INSERT INTO portal_club_charges(club_year_id,invoice_id,invoice_line_id,amount_yen) VALUES(?,?,?,?)',[$clubYear,$invoice,$line,$rule['amount_yen']]);
            $this->record($actor,'INVOICED',['membership_id'=>null,'club_year_id'=>$clubYear],'invoice:'.$invoice);return $this->invoice($invoice);
        });
    }

    /** 本人/会計担当が請求を発行。マスター未設定・重複・entry既存会費は確認が済むまで停止。 */
    public function issue(int $actor,int $member): array
    {
        return $this->tx(function () use ($actor,$member): array {
            $m=$this->member($member);
            $this->actor($actor);
            if ($actor!==(int)$m['user_id']) { $this->permission($actor,'billing.confirm'); }
            $old=$this->charge($member);
            if ($old) { return $this->invoice($old['invoice_id']); }
            $application=$this->one('SELECT status FROM portal_applications WHERE id=? FOR UPDATE',[$m['application_id']]);
            if (!in_array($application['status'],['submitted','approved'],true) || $m['status']==='suspended') { throw new HttpFailure(409,'現在の申請状態では請求できません'); }
            $year=$this->one("SELECT source_mode,valid_until FROM portal_years WHERE year=? FOR UPDATE",[$m['year']]);
            if ($year['source_mode']!=='portal' || $year['valid_until']<=gmdate('Y-m-d H:i:s.u')) { throw new HttpFailure(409,'この年度の会費は受付対象外です'); }
            if ($m['member_type']==='temporary') {
                $event=$this->one("SELECT participation_date FROM portal_events WHERE id=? AND status='published' FOR UPDATE",[$m['event_id']]);
                $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                if (!$event || $event['participation_date']!==$m['participation_date'] || $event['participation_date']<$today) { throw new HttpFailure(409,'終了・中止・変更されたイベントの請求はできません'); }
            }
            $legacy=$this->one("SELECT f.id FROM jmrc_entry_fees f JOIN jmrc_entries e ON e.id=f.entry_id
                JOIN jmrc_events ev ON ev.id=e.event_id WHERE e.user_id=? AND YEAR(ev.event_date)=?
                AND (f.item_name LIKE 'JMRC個人入会金%' OR f.item_name LIKE 'JMRC個人会員費%') AND f.amount>0 LIMIT 1 FOR UPDATE",[$m['user_id'],$m['year']]);
            if ($legacy) { throw new HttpFailure(409,'既存entryに会費明細があります。事務局で照合してください'); }
            $rules=$this->all("SELECT * FROM portal_fee_rules WHERE year=? AND purpose_code='membership_fee' AND member_type=?
                AND enabled=1 AND valid_from<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<valid_until FOR UPDATE",[$m['year'],$m['member_type']]);
            if (count($rules)!==1 || (int)$rules[0]['amount_yen']<=0
                || $rules[0]['charge_unit']!==($m['member_type']==='general'?'year':'event')) {
                throw new HttpFailure(422,'年度会費マスターを確認してください');
            }
            $accounts=$this->all("SELECT * FROM portal_bank_accounts WHERE purpose_code='membership_fee' AND (year=? OR year IS NULL)
                AND enabled=1 AND (valid_from IS NULL OR valid_from<=UTC_TIMESTAMP(6))
                AND (valid_until IS NULL OR UTC_TIMESTAMP(6)<valid_until) FOR UPDATE",[$m['year']]);
            if (count($accounts)!==1) { throw new HttpFailure(422,'入金先口座マスターを確認してください'); }
            $rule=$rules[0];$account=$accounts[0];$key='membership:'.$member;
            if ($account['payment_url']!==null && !preg_match('~^https://[^\s]+$~D',$account['payment_url'])) { throw new HttpFailure(422,'決済URLはHTTPSで登録してください'); }
            $this->run("INSERT INTO portal_invoices(application_id,invoice_no,payer_user_id,status,account_id,account_snapshot_cipher,payment_url_snapshot,idempotency_key,issued_at)
                VALUES (?,?,?,'issued',?,?,?,?,UTC_TIMESTAMP(6))",[$m['application_id'],'JMRC-'.$m['year'].'-'.$member,$m['user_id'],$account['id'],$account['details_cipher'],$account['payment_url'],$key]);
            $invoice=(int)$this->db->lastInsertId();
            $this->run("INSERT INTO portal_invoice_lines(invoice_id,fee_rule_id,purpose_code,membership_id,description,quantity,unit_amount_yen,amount_yen)
                VALUES (?,?,'membership_fee',?,?,1,?,?)",[$invoice,$rule['id'],$member,$m['member_type'].'会費 '.$m['year'],$rule['amount_yen'],$rule['amount_yen']]);
            $line=(int)$this->db->lastInsertId();
            $this->run('INSERT INTO portal_membership_charges(membership_id,invoice_id,invoice_line_id,amount_yen) VALUES (?,?,?,?)',[$member,$invoice,$line,$rule['amount_yen']]);
            $this->audit($actor,'MEMBERSHIP_INVOICED',$member,'invoice:'.$invoice);
            return $this->invoice($invoice);
        });
    }

    /** 入金確認と必要額の充当。過入金は未充当残で保持し、別請求/返金へ自動で移さない。 */
    public function confirm(int $actor,int $invoice,int $amount,string $reference,string $operation): array
    {
        return $this->tx(function () use ($actor,$invoice,$amount,$reference,$operation): array {
            $ch=$this->lockInvoice($invoice);$this->actor($actor);$this->permission($actor,'billing.confirm');
            if ($amount<=0 || $amount>1000000000 || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$reference)) { throw new HttpFailure(422,'入金額と取引番号を確認してください'); }
            $this->key($operation);
            $prior=$this->one('SELECT * FROM portal_allocations WHERE operation_key=?',[$operation]);
            if ($prior) {
                $p=$this->one('SELECT * FROM portal_payments WHERE id=?',[$prior['payment_id']]);
                if ((int)$prior['invoice_line_id']!==(int)$ch['invoice_line_id'] || (int)$p['amount_yen']!==$amount || $p['source_key']!==$reference) { throw new HttpFailure(409,'処理キーが別の入金で使用済みです'); }
                return $this->invoice($invoice);
            }
            $i=$this->invoice($invoice);
            if (!in_array($i['status'],['issued','finalized'],true) || $i['balance_yen']<=0) { throw new HttpFailure(409,'この請求は追加入金の充当対象ではありません'); }
            // source_keyは振込名義のIDではなく、担当者が確認した銀行取引を識別する番号。
            if ($this->one("SELECT id FROM portal_payments WHERE account_id=? AND source_type='manual' AND source_key=?",[$i['account_id'],$reference])) { throw new HttpFailure(409,'この銀行取引は登録済みです'); }
            $this->run("INSERT INTO portal_payments(account_id,source_type,source_key,received_at,amount_yen,payer_name_cipher,status,confirmed_by,confirmed_at)
                VALUES (?,'manual',?,UTC_TIMESTAMP(6),?,'','confirmed',?,UTC_TIMESTAMP(6))",[$i['account_id'],$reference,$amount,$actor]);
            $payment=(int)$this->db->lastInsertId();
            $allocated=min($amount,$i['balance_yen']);
            $this->run('INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,created_by) VALUES (?,?,?,?,?)',[$payment,$ch['invoice_line_id'],$allocated,$operation,$actor]);
            $this->synchronize($ch['membership_id']===null?null:(int)$ch['membership_id'],$invoice);
            $this->record($actor,'PAYMENT_CONFIRMED',$ch,$operation);
            return $this->invoice($invoice)+['payment_id'=>$payment,'unallocated_yen'=>$amount-$allocated];
        });
    }

    /** 確定後の記録修正。元行と同額/同対象の逆仕訳だけを追加し、元データや返金を作らない。 */
    public function reverse(int $actor,int $allocation,string $operation,string $reason): array
    {
        $original=$this->one('SELECT al.*,ch.invoice_id FROM portal_allocations al JOIN portal_invoice_charge_targets ch ON ch.invoice_line_id=al.invoice_line_id WHERE al.id=?',[$allocation]);
        if (!$original) { throw new HttpFailure(404,'対象入金がありません'); }
        return $this->tx(function () use ($actor,$allocation,$operation,$reason,$original): array {
            $ch=$this->lockInvoice((int)$original['invoice_id']);$this->actor($actor);$this->permission($actor,'billing.correct');$this->key($operation);
            if (trim($reason)==='' || strlen($reason)>1500) { throw new HttpFailure(422,'修正理由を入力してください'); }
            $row=$this->one('SELECT * FROM portal_allocations WHERE id=? FOR UPDATE',[$allocation]);
            $this->one('SELECT id FROM portal_payments WHERE id=? FOR UPDATE',[$row['payment_id']]);
            if ((int)$row['amount_yen']<=0 || $row['reverses_id']!==null) { throw new HttpFailure(409,'逆仕訳は元の正の充当に対して実行してください'); }
            $prior=$this->one('SELECT * FROM portal_allocations WHERE operation_key=?',[$operation]);
            if ($prior) {
                if ((int)$prior['reverses_id']!==$allocation || $prior['reason']!==$reason) { throw new HttpFailure(409,'処理キーは別の修正で使用済みです'); }
                return $this->invoice((int)$ch['invoice_id']);
            }
            $this->run('INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,reverses_id,created_by,reason) VALUES (?,?,?,?,?,?,?)',[$row['payment_id'],$row['invoice_line_id'],-(int)$row['amount_yen'],$operation,$allocation,$actor,$reason]);
            $this->synchronize($ch['membership_id']===null?null:(int)$ch['membership_id'],(int)$ch['invoice_id']);
            $this->record($actor,'PAYMENT_REVERSED',$ch,$operation);
            return $this->invoice((int)$ch['invoice_id']);
        });
    }

    /** 本人または会計担当だけに請求の公開情報を返す。口座暗号文や他人の個人情報を返さない。 */
    public function read(int $actor,int $invoice): array
    {
        $row=$this->invoice($invoice);$this->actor($actor);
        if ($row['club_year_id']!==null) {
            $cy=$this->one('SELECT club_id FROM portal_club_years WHERE id=?',[$row['club_year_id']]);$this->clubAccess($actor,(int)$cy['club_id']);
        } elseif ($actor!==(int)$row['payer_user_id']) { $this->permission($actor,'billing.confirm'); }
        unset($row['account_snapshot_cipher']);
        $row['allocations']=$this->all('SELECT id,payment_id,amount_yen,reverses_id,created_at FROM portal_allocations WHERE invoice_line_id=? ORDER BY id',[$row['invoice_line_id']]);
        $state=$row['club_year_id']!==null?(new \Jmrc\Club\ClubEligibility($this->db))->evaluate((int)$row['club_year_id']):(new Eligibility($this->db))->evaluate((int)$row['membership_id']);
        return $row+['eligibility'=>$state];
    }

    /** 訂正後の未充当残を、同じ入金者の同一請求へ再充当する。別人・別口座へは移さない。 */
    public function reallocate(int $actor,int $invoice,int $payment,int $amount,string $operation): array
    {
        return $this->tx(function () use ($actor,$invoice,$payment,$amount,$operation): array {
            $ch=$this->lockInvoice($invoice);$this->actor($actor);$this->permission($actor,'billing.correct');$this->key($operation);
            $p=$this->one("SELECT * FROM portal_payments WHERE id=? AND status='confirmed' FOR UPDATE",[$payment]);
            $i=$this->invoice($invoice);
            // 元の充当先に限り再充当を許す。過入金の別請求への振替ルールは未確定。
            if (!$p || (int)$p['account_id']!==(int)$i['account_id'] || !$this->one('SELECT id FROM portal_allocations WHERE payment_id=? AND invoice_line_id=? AND amount_yen>0',[$payment,$ch['invoice_line_id']])) { throw new HttpFailure(409,'元の請求への再充当のみ可能です'); }
            $old=$this->one('SELECT * FROM portal_allocations WHERE operation_key=?',[$operation]);
            if ($old) {
                if ((int)$old['payment_id']!==$payment || (int)$old['invoice_line_id']!==(int)$ch['invoice_line_id'] || (int)$old['amount_yen']!==$amount) { throw new HttpFailure(409,'処理キーが使用済みです'); }
                return $this->invoice($invoice);
            }
            $used=$this->one('SELECT COALESCE(SUM(amount_yen),0) AS amount FROM portal_allocations WHERE payment_id=?',[$payment]);
            if ($amount<=0 || $amount>(int)$p['amount_yen']-(int)$used['amount'] || $amount>$i['balance_yen'] || !in_array($i['status'],['issued','finalized'],true)) { throw new HttpFailure(422,'未充当残と請求残額の範囲で指定してください'); }
            $this->run('INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,created_by) VALUES (?,?,?,?,?)',[$payment,$ch['invoice_line_id'],$amount,$operation,$actor]);
            $this->synchronize($ch['membership_id']===null?null:(int)$ch['membership_id'],$invoice);$this->record($actor,'PAYMENT_REALLOCATED',$ch,$operation);
            return $this->invoice($invoice);
        });
    }

    /** サービス内のロック順は会員→請求→充当。必要額は発行時点のchargeから取得。 */
    private function lockInvoice(int $id): array
    {
        $ch=$this->one('SELECT * FROM portal_invoice_charge_targets WHERE invoice_id=?',[$id]);
        if (!$ch) { throw new HttpFailure(404,'会費請求がありません'); }
        if ($ch['club_year_id']!==null) { $this->one('SELECT id FROM portal_club_years WHERE id=? FOR UPDATE',[$ch['club_year_id']]); }
        else { $this->member((int)$ch['membership_id']); }
        $this->one('SELECT id FROM portal_invoices WHERE id=? FOR UPDATE',[$id]);
        return $ch;
    }
    private function member(int $id): array
    {
        return $this->one('SELECT * FROM portal_memberships WHERE id=? FOR UPDATE',[$id]) ?: throw new HttpFailure(404,'会員申請がありません');
    }
    private function charge(int $id): array|false { return $this->one('SELECT * FROM portal_membership_charges WHERE membership_id=?',[$id]); }
    private function invoice(int $id): array
    {
        $row=$this->one("SELECT i.*,ch.membership_id,ch.club_year_id,ch.invoice_line_id,ch.amount_yen,
            COALESCE((SELECT SUM(al.amount_yen) FROM portal_allocations al JOIN portal_payments p ON p.id=al.payment_id
                WHERE al.invoice_line_id=ch.invoice_line_id AND p.status='confirmed'),0) AS paid_yen
            FROM portal_invoices i JOIN portal_invoice_charge_targets ch ON ch.invoice_id=i.id WHERE i.id=?",[$id]);
        if (!$row) { throw new HttpFailure(404,'会費請求がありません'); }
        $row['balance_yen']=max(0,(int)$row['amount_yen']-(int)$row['paid_yen']);
        unset($row['account_snapshot_cipher']);
        return $row;
    }
    private function synchronize(?int $member,int $invoice): void
    {
        $i=$this->invoice($invoice);
        $this->run('UPDATE portal_invoices SET status=?,finalized_at=?,row_version=row_version+1 WHERE id=?',[$i['balance_yen']===0?'finalized':'issued',$i['balance_yen']===0?gmdate('Y-m-d H:i:s'):null,$invoice]);
        if ($member!==null) { (new Eligibility($this->db))->refresh($member); }
        else { (new \Jmrc\Club\ClubService($this->db))->refresh((int)$i['club_year_id']); }
    }
    /** 個人会費と同じ台帳を使い、監査の対象種別だけをクラブ年度へ切り替える。 */
    private function record(int $actor,string $action,array $target,string $key): void
    {
        if ($target['membership_id']!==null) { $this->audit($actor,'MEMBERSHIP_'.$action,(int)$target['membership_id'],$key);return; }
        $action='CLUB_'.$action;$id=(int)$target['club_year_id'];
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES (?,?,'club_year',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$id,bin2hex(random_bytes(16))]);
        $this->run("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$key,$action,$id]);
    }
    /** 代表は自クラブ、事務局/財務は会計grantで請求を参照・発行できる。 */
    private function clubAccess(int $actor,int $club): void
    {
        $this->actor($actor);
        try { (new \Jmrc\Club\ClubService($this->db))->requireAccess($actor,$club); }
        catch(HttpFailure $e) { if($e->status!==403) { throw $e; }$this->permission($actor,'billing.confirm'); }
    }
    private function actor(int $id): void
    {
        if (!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$id])) { throw new HttpFailure(401,'ログイン状態を確認してください'); }
    }
    private function permission(int $id,string $permission): void
    {
        if (!$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_role_permissions p ON p.role_id=g.role_id JOIN portal_roles r ON r.id=g.role_id
            WHERE g.user_id=? AND p.permission_code=? AND g.scope_type='global' AND r.code IN ('OFFICE','FINANCE') AND g.status='active'
            AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
            AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1 FOR UPDATE",[$id,$permission])) { throw new HttpFailure(403,'事務局または財務の会計権限が必要です'); }
    }
    private function key(string $key): void { if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) { throw new HttpFailure(422,'処理キーが不正です'); } }
    private function audit(int $actor,string $action,int $member,string $key): void
    {
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES (?,?,'membership',?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$member,bin2hex(random_bytes(16))]);
        $this->run("INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES (?,?,?,'{}')",[$action.':'.$key,$action,$member]);
    }
    private function run(string $sql,array $values): \PDOStatement { $st=$this->db->prepare($sql);$st->execute($values);return $st; }
    private function one(string $sql,array $values): array|false { return $this->run($sql,$values)->fetch(PDO::FETCH_ASSOC); }
    private function all(string $sql,array $values): array { return $this->run($sql,$values)->fetchAll(PDO::FETCH_ASSOC); }
    private function tx(callable $fn): array
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Independent billing transaction required'); }
        for ($i=0;$i<3;$i++) {
            // 会員ロック待機前の照会で古いREPEATABLE READスナップショットを作らない。
            // 同じ請求の待機者は、先行取引が確定した充当額を必ず読み直す。
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->beginTransaction();
            try { $row=$fn();$this->db->commit();return $row; }
            catch (\Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                if ($e instanceof PDOException) {
                    $code=(int)($e->errorInfo[1]??0);
                    if (in_array($code,[1205,1213],true) && $i<2) { usleep(20000*($i+1));continue; }
                    if ($code===1062) { throw new HttpFailure(409,'請求または入金処理が重複しています'); }
                }
                throw $e;
            }
        }
        throw new HttpFailure(503,'会計処理を再度お試しください');
    }
}
