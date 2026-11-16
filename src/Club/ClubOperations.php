<?php
// Path: src/Club/ClubOperations.php
// 機能: 照合済みクラブの振込案内・入金・訂正・年度承認を接続。既存台帳と権限を使い、個人請求や別クラブへの操作を防ぐ。
declare(strict_types=1);
namespace Jmrc\Club;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Billing\BillingService;
use Jmrc\Card\SecretBox;
final class ClubOperations
{
    public const FILTERS=['all'=>'すべて','uninvoiced'=>'請求前','unpaid'=>'入金待ち・不足','approval'=>'年度承認待ち','ready'=>'年度登録済み','inactive'=>'差し戻し・不承認'];
    public function __construct(private readonly PDO $db,private readonly array $config){}
    /** 現在の承認済み・期限内の職務権限を使用し、財務をクラブ承認者へ昇格させない。 */
    public function capabilities(int $actor):array
    {
        $this->actor($actor);$out=[];
        foreach(['confirm'=>'billing.confirm','correct'=>'billing.correct','approve'=>'club.registration.approve','accounts'=>'billing.accounts.manage'] as $key=>$permission){
            $out[$key]=(bool)$this->one("SELECT g.id FROM portal_user_role_grants g JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=r.id WHERE g.user_id=? AND p.permission_code=? AND g.scope_type='global' AND r.code IN ('OFFICE','FINANCE','COMMITTEE','APPROVER') AND g.status='active' AND (g.approved_by IS NOT NULL OR g.bootstrap_approval_ref IS NOT NULL) AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) LIMIT 1",[$actor,$permission]);
        }
        $out['staff']=$out['confirm']||$out['correct']||$out['approve'];return $out;
    }
    /** 一覧は照合済みの申請だけ。通常利用者は本人分、事務局・財務は担当権限で全体を確認する。 */
    public function listing(int $actor,int $year=0,string $filter='all'):array
    {
        if(!isset(self::FILTERS[$filter])){throw new HttpFailure(422,'表示する状況を選択してください');}$caps=$this->capabilities($actor);
        $s=$this->run("SELECT i.id FROM portal_club_intakes i WHERE i.review_state='linked' AND (i.applicant_user_id=? OR ?=1) AND (?=0 OR i.year=?) ORDER BY i.id DESC LIMIT 100",[$actor,(int)$caps['staff'],$year,$year]);
        $out=[];foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){$r=$this->read($actor,(int)$id);if($filter==='all'||$filter===$r['stage']){$out[]=$r;}}return $out;
    }
    /** 暗号文を返さず、請求発行時の口座案内・申請者ID・残額・最新資格を読み出す。GETでは業務更新しない。 */
    public function read(int $actor,int $intake):array
    {
        $caps=$this->capabilities($actor);$r=$this->one("SELECT i.id,i.year,i.applicant_user_id,i.annual_yen,i.joining_yen,i.registration_kind,i.jaf_kind,i.membership_kind,i.club_year_id,cy.club_id,cy.display_code,cy.status,cy.name_full,cy.name_short,y.source_mode FROM portal_club_intakes i JOIN portal_club_years cy ON cy.id=i.club_year_id JOIN portal_years y ON y.year=i.year WHERE i.id=? AND i.review_state='linked'",[$intake])?:throw new HttpFailure(404,'事務局の照合が完了したクラブ申請がありません');
        if(!$caps['staff']){(new ClubService($this->db))->requireAccess($actor,(int)$r['club_id']);}
        $charge=$this->one('SELECT invoice_id FROM portal_club_charges WHERE club_year_id=?',[$r['club_year_id']]);
        $invoice=$charge?(new BillingService($this->db))->read($actor,(int)$charge['invoice_id']):null;
        $state=(new ClubEligibility($this->db))->evaluate((int)$r['club_year_id']);$guide=['ready'=>false,'details'=>null,'notice'=>'振込先は請求の発行後に表示します。'];
        if($invoice){$bank=$this->one('SELECT account_id,account_snapshot_cipher FROM portal_invoices WHERE id=?',[$invoice['id']]);$guide=$this->guide($bank);}
        $stage=!in_array($r['status'],['pending','approved'],true)?'inactive':(!$invoice?'uninvoiced':(!$state['paid']?'unpaid':(!$state['approved']?'approval':($state['ready']?'ready':'unpaid'))));
        $allocations=$invoice?$this->run('SELECT al.id,al.payment_id,al.amount_yen,al.reverses_id,al.reason,al.created_at,al.created_by FROM portal_allocations al WHERE al.invoice_line_id=? ORDER BY al.id',[$invoice['invoice_line_id']])->fetchAll(PDO::FETCH_ASSOC):[];
        $payments=$invoice?$this->run("SELECT DISTINCT p.id,p.amount_yen,p.received_at,p.confirmed_at,p.source_key,COALESCE((SELECT SUM(a.amount_yen) FROM portal_allocations a WHERE a.payment_id=p.id),0) AS allocated_yen FROM portal_payments p JOIN portal_allocations al ON al.payment_id=p.id WHERE al.invoice_line_id=? ORDER BY p.id",[$invoice['invoice_line_id']])->fetchAll(PDO::FETCH_ASSOC):[];
        foreach($payments as &$p){$p['unallocated_yen']=(int)$p['amount_yen']-(int)$p['allocated_yen'];}unset($p);
        $history=$this->run("SELECT action,actor_user_id,occurred_at FROM portal_audit_events WHERE entity_type='club_year' AND entity_id=? ORDER BY occurred_at DESC,id DESC LIMIT 30",[$r['club_year_id']])->fetchAll(PDO::FETCH_ASSOC);
        return ['intake'=>$r,'invoice'=>$invoice,'guide'=>$guide,'eligibility'=>$state,'stage'=>$stage,'allocations'=>$allocations,'payments'=>$payments,'history'=>$history,'capabilities'=>$caps,'enabled'=>ClubIntakePolicy::enabled($this->config)];
    }
    /** 対象請求は照合済み年度クラブから解決する。POSTのinvoice_id・任意の充当先は信用しない。 */
    public function execute(int $actor,int $intake,string $action,array $in):array
    {
        $r=$this->read($actor,$intake);$cy=(int)$r['intake']['club_year_id'];
        if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ会費・承認の操作は準備中です');}
        if(!in_array($r['intake']['source_mode'],['shadow','portal'],true)){throw new HttpFailure(409,'この年度はポータルのクラブ処理対象ではありません');}
        $billing=new BillingService($this->db);
        if($action==='issue'){
            if(!$r['invoice']){$this->requireBank((int)$r['intake']['year']);}
            $billing->issueClub($actor,$cy);return $this->read($actor,$intake);
        }
        $invoice=$r['invoice']?:throw new HttpFailure(409,'先にクラブ会費の請求を発行してください');
        if($action==='confirm'){
            if(!$r['capabilities']['confirm']){throw new HttpFailure(403,'入金確認の権限が必要です');}
            if(!$r['guide']['ready']){throw new HttpFailure(409,'請求時の振込先情報を事務局で確認してください');}
            $billing->confirm($actor,(int)$invoice['id'],$this->number($in,'amount'),$this->text($in,'reference'),$this->text($in,'operation'),$this->text($in,'received_on'));
        }elseif($action==='reverse'){
            if(!$r['capabilities']['correct']){throw new HttpFailure(403,'入金訂正の権限が必要です');}
            $id=$this->number($in,'allocation');
            if(!$this->one('SELECT id FROM portal_allocations WHERE id=? AND invoice_line_id=?',[$id,$invoice['invoice_line_id']])){throw new HttpFailure(409,'このクラブの入金明細を指定してください');}
            $billing->reverse($actor,$id,$this->text($in,'operation'),$this->text($in,'reason'));
        }elseif($action==='reallocate'){
            if(!$r['capabilities']['correct']){throw new HttpFailure(403,'入金訂正の権限が必要です');}
            $billing->reallocate($actor,(int)$invoice['id'],$this->number($in,'payment'),$this->number($in,'amount'),$this->text($in,'operation'));
        }elseif($action==='approve'){
            if(!in_array($r['intake']['status'],['pending','approved'],true)){throw new HttpFailure(409,'差し戻し・不承認の年度登録は、この操作では変更しません');}
            if(($in['checked']??'')!=='1'){throw new HttpFailure(422,'申請内容と入金確認の確認欄にチェックしてください');}
            (new ClubService($this->db))->approve($actor,$cy);
        }else{throw new HttpFailure(422,'操作を選択してください');}
        return $this->read($actor,$intake);
    }
    /** 請求が保存した口座情報だけを復号する。現在の別口座・用途・決済URLへ差し替えない。 */
    private function guide(array $bank):array
    {
        $cipher=$bank['account_snapshot_cipher']??$bank['details_cipher']??'';$id=(int)($bank['account_id']??$bank['id']??0);
        if(!is_string($cipher)||!str_starts_with($cipher,'gcm1:')){return ['ready'=>false,'details'=>null,'notice'=>'振込先の保管形式を事務局で確認してください。'];}
        try{$details=(new SecretBox($this->config['data_key']??null))->open($cipher,'jmrc:bank-account:v1:'.$id);}
        catch(HttpFailure){return ['ready'=>false,'details'=>null,'notice'=>'振込先を読み出せません。事務局で確認してください。'];}
        return ['ready'=>trim($details)!=='','details'=>$details,'notice'=>trim($details)===''?'振込先の案内が未登録です。':null];
    }
    private function requireBank(int $year):void
    {
        $s=$this->run("SELECT id,details_cipher FROM portal_bank_accounts WHERE purpose_code='club_fee' AND (year=? OR year IS NULL) AND enabled=1 AND (valid_from IS NULL OR valid_from<=UTC_TIMESTAMP(6)) AND (valid_until IS NULL OR UTC_TIMESTAMP(6)<valid_until)",[$year]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||!$this->guide($rows[0])['ready']){throw new HttpFailure(409,'クラブ会費の正式な振込先が未設定、重複、または確認待ちです。事務局へ確認してください。');}
    }
    private function actor(int $id):void{if(!$this->one("SELECT u.id FROM users u JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=? AND u.email_verified=1 AND c.status='active'",[$id])){throw new HttpFailure(401,'サインイン状態を確認してください');}}
    private function number(array $in,string $key):int{$n=filter_var($in[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]);if($n===false||$n===null){throw new HttpFailure(422,'金額・明細を確認してください');}return $n;}
    private function text(array $in,string $key):string{if(!is_string($in[$key]??null)){throw new HttpFailure(422,'入力項目を確認してください');}return $in[$key];}
    private function run(string $sql,array $args):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
    private function one(string $sql,array $args):array|false{return $this->run($sql,$args)->fetch(PDO::FETCH_ASSOC);}
}
