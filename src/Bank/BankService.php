<?php
// Path: src/Bank/BankService.php
// 機能: 実口座単位でCSV明細を重複排除し、候補提示と担当者の明示確認を分離して入金台帳へ連携する。
declare(strict_types=1);
namespace Jmrc\Bank;
use PDO;
use PDOException;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class BankService
{
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    /** 全経路で事務局/財務の承認済み会計grantを確認。旧role_idやクラブ代表だけでは許可しない。 */
    public function access(int $actor): void
    {
        if(($this->config['yucho_import_enabled']??false)!==true){throw new HttpFailure(503,'銀行CSV取込は準備中です');}
        if(!$this->one("SELECT g.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id
            JOIN portal_user_role_grants g ON g.user_id=u.id JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=r.id
            WHERE u.id=? AND u.email_verified=1 AND c.status='active' AND p.permission_code='billing.confirm'
            AND r.code IN ('OFFICE','FINANCE') AND g.scope_type='global' AND g.status='active' AND g.approved_by IS NOT NULL
            AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1",[$actor])){throw new HttpFailure(403,'事務局または財務の会計権限が必要です');}
    }
    public function sources(int $actor): array
    {
        $this->access($actor);return $this->all('SELECT s.id,s.display_name FROM portal_bank_sources s WHERE s.enabled=1 AND EXISTS(SELECT 1 FROM portal_bank_source_accounts l JOIN portal_bank_accounts a ON a.id=l.account_id WHERE l.source_id=s.id) ORDER BY s.id',[]);
    }
    /** ファイル再送は同一ID、範囲/順序/文字幅/文字コードを変えた再出力も実口座+日付/金額/正規化名義で再利用する。 */
    public function import(int $actor,int $source,string $bytes,string $encoding): array
    {
        $this->access($actor);$parsed=(new YuchoCsv())->parse($bytes,$encoding);$box=$this->box();
        return $this->tx(function()use($actor,$source,$bytes,$parsed,$box):array{
            $this->access($actor);$s=$this->source($source,true);
            if($s['dedup_key_check']===null){$this->run('UPDATE portal_bank_sources SET dedup_key_check=? WHERE id=?',[$box->digest('key-check','yucho-source:v1:'.$source),$source]);}
            $old=$this->one('SELECT id FROM portal_bank_imports WHERE bank_source_id=? AND file_sha256=?',[$source,hash('sha256',$bytes,true)]);
            if($old){return ['id'=>(int)$old['id'],'replayed'=>true,'new_rows'=>0,'ignored'=>$parsed['ignored']];}
            $account=$this->one('SELECT a.id FROM portal_bank_source_accounts l JOIN portal_bank_accounts a ON a.id=l.account_id WHERE l.source_id=? ORDER BY a.enabled DESC,a.id LIMIT 1',[$source]);
            if(!$account){throw new HttpFailure(422,'取込元と入金先口座の対応を設定してください');}
            $this->run("INSERT INTO portal_bank_imports(account_id,bank_source_id,file_sha256,parser_version,status,imported_by,imported_at) VALUES(?,?,?,?,'preview',?,UTC_TIMESTAMP(6))",[$account['id'],$source,hash('sha256',$bytes,true),YuchoCsv::VERSION,$actor]);$import=(int)$this->db->lastInsertId();$created=0;$seen=0;
            foreach($parsed['rows'] as $r){
                $name=YuchoCsv::name($r['name']);$fingerprint=$box->digest(json_encode([$r['date'],$r['amount'],$name],JSON_THROW_ON_ERROR),'yucho-row:v1:'.$source);
                $row=$this->one('SELECT id FROM portal_yucho_rows WHERE source_id=? AND fingerprint=?',[$source,$fingerprint]);
                if($row){$id=(int)$row['id'];$seen++;}else{
                    $this->run("INSERT INTO portal_yucho_rows(source_id,fingerprint,receipt_date,amount_yen,payer_hmac,payer_name_cipher) VALUES(?,?,?,?,?,'')",[$source,$fingerprint,$r['date'],$r['amount'],$box->digest($name,'yucho-payer:v1')]);$id=(int)$this->db->lastInsertId();
                    $this->run('UPDATE portal_yucho_rows SET payer_name_cipher=? WHERE id=?',[$box->seal($r['name'],'jmrc:yucho-row:v1:'.$id),$id]);$created++;
                }
                $this->run('INSERT INTO portal_yucho_import_rows(import_id,record_no,row_id) VALUES(?,?,?)',[$import,$r['record_no'],$id]);
            }
            $this->audit($actor,'YUCHO_IMPORTED',$import);
            return ['id'=>$import,'replayed'=>false,'new_rows'=>$created,'seen_rows'=>$seen,'ignored'=>$parsed['ignored']];
        });
    }
    /** 一覧は100件ずつ。明細本文や暗号文を監査ログに流さない。 */
    public function listing(int $actor,int $source,int $after=0): array
    {
        $this->access($actor);$this->source($source);
        $rows=$this->all('SELECT * FROM portal_yucho_rows WHERE source_id=? AND id>? ORDER BY id LIMIT 100',[$source,$after]);
        return array_map(fn($r)=>$this->publicRow($r),$rows);
    }
    public function read(int $actor,int $id): array
    {
        $this->access($actor);$r=$this->row($id);$this->source((int)$r['source_id']);$out=$this->publicRow($r);
        $names=$this->all("SELECT i.id,i.invoice_no,i.payer_user_id,i.payer_club_year_id,ch.amount_yen,
            COALESCE((SELECT SUM(al.amount_yen) FROM portal_allocations al JOIN portal_payments p ON p.id=al.payment_id WHERE al.invoice_line_id=ch.invoice_line_id AND p.status='confirmed'),0) AS paid_yen,
            pr.last_kana,pr.first_kana,cy.name_full,cy.name_short,cy.rep_name,cy.contact_name
            FROM portal_invoices i JOIN portal_invoice_charge_targets ch ON ch.invoice_id=i.id
            JOIN portal_bank_source_accounts l ON l.account_id=i.account_id
            LEFT JOIN portal_profiles pr ON pr.user_id=i.payer_user_id LEFT JOIN portal_club_years cy ON cy.id=i.payer_club_year_id
            WHERE l.source_id=? AND i.status IN ('issued','finalized') ORDER BY i.id LIMIT 501",[$r['source_id']]);
        $candidate=[];$normalized=YuchoCsv::name($out['payer_name']);$overflow=count($names)>500;
        if(!$overflow){foreach($names as $i){$balance=max(0,(int)$i['amount_yen']-(int)$i['paid_yen']);if($balance<=0){continue;}
            $possible=$i['payer_user_id']!==null?[($i['last_kana']??'').($i['first_kana']??'')]:[$i['name_full']??'',$i['name_short']??'',$i['rep_name']??'',$i['contact_name']??''];
            foreach($possible as $n){if($n!=='' && YuchoCsv::name($n)===$normalized){$candidate[]=['invoice_id'=>(int)$i['id'],'invoice_no'=>$i['invoice_no'],'balance_yen'=>$balance,'amount_exact'=>$balance===(int)$r['amount_yen']];break;}}
        }}
        $prior=$this->priorPayments($r,$r['receipt_date']??$r['reviewed_date']);
        $out['candidates']=$candidate;$out['prior_payments']=$prior;$out['candidate_scan_overflow']=$overflow;
        $out['match_state']=!$overflow && $r['receipt_date']!==null && count($candidate)===1 && $candidate[0]['amount_exact'] && !$prior && $out['same_file_occurrences']<=1?'unique_candidate':'manual_review';
        $out['automatic_confirmation']=false;return $out;
    }
    /** new=新規実入金、link=既登録手動入金の紐付け、duplicate=重複確認。すべて担当者の明示POSTのみ。 */
    public function resolve(int $actor,int $id,int $version,string $mode,int $invoice,int $payment,string $date,string $reason,bool $legacyChecked,bool $separate,bool $manualDateChecked=false): array
    {
        $this->access($actor);$date=YuchoCsv::date($date);$reason=trim($reason);
        if(!in_array($mode,['new','link','duplicate'],true) || !$date || $reason==='' || strlen($reason)>1500 || !$legacyChecked){throw new HttpFailure(422,'入金日・確認理由・既存入金との重複確認が必要です');}
        $source=(int)$this->row($id)['source_id'];
        return $this->tx(function()use($actor,$id,$version,$mode,$invoice,$payment,$date,$reason,$source,$separate,$manualDateChecked):array{
            $this->access($actor);$this->source($source,true);$r=$this->row($id,true);$box=$this->box();
            if($r['status']!=='review'){
                $same=$r['resolution_mode']===$mode && $r['reviewed_date']===$date && (int)($r['invoice_id']??0)===($mode==='duplicate'?0:$invoice)
                    && ($mode==='new' || (int)($r['payment_id']??$r['duplicate_payment_id'])===$payment)
                    && $box->open($r['review_reason_cipher'],'jmrc:yucho-review:v1:'.$id)===$reason;
                if(!$same){throw new HttpFailure(409,'この明細は処理済みです');}return $this->publicRow($r)+['replayed'=>true];
            }
            if((int)$r['row_version']!==$version){throw new HttpFailure(409,'明細が更新されています');}
            if($r['receipt_date']!==null && $r['receipt_date']!==$date){throw new HttpFailure(422,'CSVの取扱日と一致する日付を指定してください');}
            if($mode==='new'){
                if($payment!==0 || $invoice<1){throw new HttpFailure(422,'新規入金の請求IDを確認してください');}
                if($this->priorPayments($r,$date) && !$separate){throw new HttpFailure(409,'同日同額の既存入金があります。紐付け・重複、または別実取引の確認が必要です');}
                $i=$this->one('SELECT i.account_id FROM portal_invoices i JOIN portal_bank_source_accounts l ON l.account_id=i.account_id WHERE i.id=? AND l.source_id=?',[$invoice,$source]);
                if(!$i){throw new HttpFailure(422,'請求の入金先口座とCSV取込元が一致しません');}
                $when=(new \DateTimeImmutable($date.' 00:00:00',new \DateTimeZone('Asia/Tokyo')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $key='yucho:'.$source.':'.bin2hex($r['fingerprint']);
                $this->run("INSERT INTO portal_payments(account_id,source_type,source_key,received_at,amount_yen,payer_name_cipher,status) VALUES(?,'bank_csv',?,?,?,?,'pending')",[$i['account_id'],$key,$when,$r['amount_yen'],$box->seal($this->publicRow($r)['payer_name'],'jmrc:yucho-payment:v1:'.$id)]);$payment=(int)$this->db->lastInsertId();
                (new \Jmrc\Billing\BillingService($this->db))->confirmImported($actor,$invoice,$payment,'yucho:'.$id);
            }else{
                $p=$this->one("SELECT p.* FROM portal_payments p JOIN portal_bank_source_accounts l ON l.account_id=p.account_id WHERE p.id=? AND l.source_id=? AND p.status='confirmed' FOR UPDATE",[$payment,$source]);
                if(!$p || (int)$p['amount_yen']!==(int)$r['amount_yen']){throw new HttpFailure(422,'同じ実口座・金額の既存入金を指定してください');}
                $recordedDate=(new \DateTimeImmutable($p['received_at'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
                // 旧手動入力のreceived_atは確認操作日時。実入金日との差は証跡確認後に限り許容し、元台帳は書き換えない。
                if($recordedDate!==$date && !($p['source_type']==='manual' && $manualDateChecked)){throw new HttpFailure(422,'記録日が異なります。旧手動入金の証跡確認が必要です');}
                if($mode==='link'){
                    if($p['source_type']!=='manual' || !$this->one('SELECT al.id FROM portal_allocations al JOIN portal_invoice_charge_targets ch ON ch.invoice_line_id=al.invoice_line_id WHERE al.payment_id=? AND ch.invoice_id=? AND al.amount_yen>0 LIMIT 1',[$payment,$invoice])){throw new HttpFailure(422,'元の手動入金の請求を指定してください');}
                }else{$invoice=0;}
            }
            $this->run('UPDATE portal_yucho_rows SET status=?,payment_id=?,duplicate_payment_id=?,invoice_id=?,reviewed_date=?,review_reason_cipher=?,resolution_mode=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP(6),row_version=row_version+1 WHERE id=?',[
                $mode==='duplicate'?'duplicate':'confirmed',$mode==='duplicate'?null:$payment,$mode==='duplicate'?$payment:null,$invoice?:null,$date,$box->seal($reason,'jmrc:yucho-review:v1:'.$id),$mode,$actor,$id]);
            $this->audit($actor,'YUCHO_'.strtoupper($mode).'_CONFIRMED',$id);return $this->publicRow($this->row($id))+['replayed'=>false];
        });
    }
    private function priorPayments(array $r,?string $date): array
    {
        if(!$date){return [];}
        return $this->all("SELECT p.id,p.account_id,p.source_type,p.amount_yen FROM portal_payments p JOIN portal_bank_source_accounts l ON l.account_id=p.account_id
            WHERE l.source_id=? AND p.status='confirmed' AND p.amount_yen=? AND DATE(p.received_at+INTERVAL 9 HOUR)=? ORDER BY p.id LIMIT 100",[$r['source_id'],$r['amount_yen'],$date]);
    }
    /** 同条件でも別実取引と証跡で確認した場合のみ分離。再取込や件数からは追加しない。 */
    public function split(int $actor,int $id,string $reference,string $reason): array
    {
        $this->access($actor);$reason=trim($reason);
        if(!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$reference) || $reason==='' || strlen($reason)>1500){throw new HttpFailure(422,'別取引の証跡識別子と理由を入力してください');}
        $source=(int)$this->row($id)['source_id'];
        return $this->tx(function()use($actor,$id,$source,$reference,$reason):array{
            $this->access($actor);$this->source($source,true);$r=$this->row($id,true);$box=$this->box();
            if($r['status']!=='confirmed' || $r['split_from_id']!==null){throw new HttpFailure(409,'確定済みの元明細からのみ別取引を分離できます');}
            $evidence=$box->digest($reference,'yucho-evidence:v1:'.$source);
            $old=$this->one('SELECT * FROM portal_yucho_rows WHERE source_id=? AND evidence_hmac=?',[$source,$evidence]);
            if($old){if((int)$old['split_from_id']!==$id){throw new HttpFailure(409,'証跡識別子は使用済みです');}return $this->publicRow($old)+['replayed'=>true];}
            $fingerprint=$box->digest($id.':'.$reference,'yucho-split:v1:'.$source);
            $this->run("INSERT INTO portal_yucho_rows(source_id,fingerprint,receipt_date,amount_yen,payer_hmac,payer_name_cipher,split_from_id,evidence_hmac) VALUES(?,?,?,?,?,'',?,?)",[$source,$fingerprint,$r['receipt_date'],$r['amount_yen'],$r['payer_hmac'],$id,$evidence]);$child=(int)$this->db->lastInsertId();
            $this->run('UPDATE portal_yucho_rows SET payer_name_cipher=?,evidence_cipher=? WHERE id=?',[$box->seal($this->publicRow($r)['payer_name'],'jmrc:yucho-row:v1:'.$child),$box->seal($reference.'\n'.$reason,'jmrc:yucho-evidence:v1:'.$child),$child]);
            $this->audit($actor,'YUCHO_DISTINCT_REVIEW_CREATED',$child);return $this->publicRow($this->row($child))+['replayed'=>false];
        });
    }
    private function publicRow(array $r): array
    {
        $count=$this->one('SELECT COALESCE(MAX(n),0) AS n FROM (SELECT COUNT(*) AS n FROM portal_yucho_import_rows WHERE row_id=? GROUP BY import_id) counts',[$r['id']]);
        return ['id'=>(int)$r['id'],'source_id'=>(int)$r['source_id'],'receipt_date'=>$r['receipt_date'],'amount_yen'=>(int)$r['amount_yen'],
            'payer_name'=>$this->box()->open($r['payer_name_cipher'],'jmrc:yucho-row:v1:'.$r['id']),'status'=>$r['status'],'payment_id'=>$r['payment_id']===null?null:(int)$r['payment_id'],
            'duplicate_payment_id'=>$r['duplicate_payment_id']===null?null:(int)$r['duplicate_payment_id'],'invoice_id'=>$r['invoice_id']===null?null:(int)$r['invoice_id'],
            'row_version'=>(int)$r['row_version'],'same_file_occurrences'=>(int)$count['n'],'split_from_id'=>$r['split_from_id']===null?null:(int)$r['split_from_id']];
    }
    private function source(int $id,bool $lock=false): array
    {
        $r=$this->one('SELECT * FROM portal_bank_sources WHERE id=? AND enabled=1'.($lock?' FOR UPDATE':''),[$id])?:throw new HttpFailure(422,'有効な実口座の取込元を選択してください');
        if($r['dedup_key_check']!==null && !hash_equals($r['dedup_key_check'],$this->box()->digest('key-check','yucho-source:v1:'.$id))){throw new HttpFailure(503,'銀行取込の鍵移行を確認してください');}return $r;
    }
    private function row(int $id,bool $lock=false): array {return $this->one('SELECT * FROM portal_yucho_rows WHERE id=?'.($lock?' FOR UPDATE':''),[$id])?:throw new HttpFailure(404,'銀行明細がありません');}
    private function box(): SecretBox {return new SecretBox($this->config['data_key']??null);}
    private function one(string $sql,array $args): array|false {return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
    private function all(string $sql,array $args): array {return $this->run($sql,$args)->fetchAll(PDO::FETCH_ASSOC);}
    private function run(string $sql,array $args): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function audit(int $actor,string $action,int $id): void
    {
        $entity=$action==='YUCHO_IMPORTED'?'bank_import':'bank_row';
        $this->run("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,?,?,?,?,'{}',UTC_TIMESTAMP(6))",[$actor,$action,$entity,$id,bin2hex(random_bytes(16))]);
    }
    private function tx(callable $fn): array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent bank transaction required');}
        for($i=0;$i<3;$i++){$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
            try{$r=$fn();$this->db->commit();return $r;}catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}
                if($e instanceof PDOException){$n=(int)($e->errorInfo[1]??0);if(in_array($n,[1205,1213],true)&&$i<2){usleep(20000*($i+1));continue;}if($n===1062){throw new HttpFailure(409,'明細または既存入金は処理済みです');}}throw $e;}
        }throw new HttpFailure(503,'銀行照合を再試行してください');
    }
}
