<?php
// Path: public/manage/billing.php
// 機能: 本人の会費請求と会計担当の入金確認・訂正、所属承認をCSRF保護付きで提供する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
$user=jmrc_auth()->requireIdentity();$db=jmrc_db();$service=new \Jmrc\Billing\BillingService($db);
$json=($_GET['format']??'')==='json';$result=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { throw new \Jmrc\Auth\HttpFailure(422,'対象を指定してください'); }
    $positive=static function(string $name): int {
        $value=filter_var($_POST[$name]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]);
        if (!$value) { throw new \Jmrc\Auth\HttpFailure(422,'金額・対象IDを確認してください'); }return $value;
    };
    $text=static function(string $name): string {
        if (!is_string($_POST[$name]??null)) { throw new \Jmrc\Auth\HttpFailure(422,'入力項目を確認してください'); }return $_POST[$name];
    };
    $result=match($_POST['action']??'') {
        'issue'=>$service->issue($user['id'],$id),
        'confirm'=>$service->confirm($user['id'],$id,$positive('amount'),$text('reference'),$text('operation')),
        'reverse'=>$service->reverse($user['id'],$id,$text('operation'),$text('reason')),
        'reallocate'=>$service->reallocate($user['id'],$id,$positive('payment'),$positive('amount'),$text('operation')),
        'approve'=>(new \Jmrc\Membership\MembershipService($db))->approveGeneral($user['id'],$id),
        default=>throw new \Jmrc\Auth\HttpFailure(422,'操作を選択してください'),
    };
    // 発行・入金APIにも暗号文を含めない。
    unset($result['account_snapshot_cipher']);
} elseif ($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'POST required'); }
if (isset($_GET['invoice'])) { $result=$service->read($user['id'],(int)$_GET['invoice']); }
if ($json) { header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
$s=$db->prepare('SELECT id,year,member_type FROM portal_memberships WHERE user_id=? ORDER BY year DESC,id');$s->execute([$user['id']]);$members=$s->fetchAll();
$staff=jmrc_auth()->can('billing.confirm');
$s=$db->prepare("SELECT i.id,i.invoice_no,COALESCE(m.year,cy.year) AS year,COALESCE(m.member_type,'club') AS member_type FROM portal_invoices i
 JOIN portal_invoice_charge_targets ch ON ch.invoice_id=i.id LEFT JOIN portal_memberships m ON m.id=ch.membership_id
 LEFT JOIN portal_club_years cy ON cy.id=ch.club_year_id WHERE i.payer_user_id=? OR ?=1 ORDER BY i.id DESC LIMIT 100");$s->execute([$user['id'],(int)$staff]);$invoices=$s->fetchAll();
// 一覧にもPOST承認と同じスコープを適用し、担当外の申請者を出さない。
$s=$db->prepare("SELECT DISTINCT m.id,m.year,u.email FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id
    JOIN users u ON u.id=m.user_id JOIN portal_affiliations f ON f.membership_id=m.id AND f.valid_until IS NULL
    JOIN portal_club_years cy ON cy.id=f.club_year_id JOIN portal_user_role_grants g ON g.user_id=?
    JOIN portal_roles r ON r.id=g.role_id JOIN portal_role_permissions p ON p.role_id=g.role_id AND p.permission_code='club.affiliations.approve'
    WHERE m.member_type='general' AND a.status='submitted' AND g.status='active' AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL
    AND g.valid_from<=UTC_TIMESTAMP(6) AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)
    AND ((g.scope_type='global' AND r.code='OFFICE') OR (g.scope_type='club' AND g.scope_club_id=cy.club_id)) ORDER BY m.id");$s->execute([$user['id']]);$approvals=$s->fetchAll();
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><title>会費請求・入金確認</title><h1>会費請求・入金確認</h1>
<p>資格は承認・入金確認・有効期間などの条件が揃った場合に有効になります。</p>
<?php foreach($members as $m): ?><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="issue"><input type="hidden" name="id" value="<?=$e($m['id'])?>"><p><?=$e($m['year'].' '.$m['member_type'])?> <button>請求を表示・発行</button></p></form><?php endforeach; ?>
<?php if($result && isset($result['invoice_no'])): ?><h2><?=$e($result['invoice_no'])?></h2><p>請求額 <?=$e($result['amount_yen'])?> 円 ／ 確認済み充当額 <?=$e($result['paid_yen'])?> 円 ／ 残額 <?=$e($result['balance_yen'])?> 円</p><p><a href="?invoice=<?=$e($result['id'])?>">最新の資格・入金明細を表示</a></p><?php if(isset($result['eligibility'])): ?><p>入金：<?=$result['eligibility']['paid']?'確認済み':'未完了'?> ／ 承認：<?=$result['eligibility']['approved']?'承認済み':'未完了'?> ／ 資格：<?=$result['eligibility']['active']?'有効':'現在は無効'?></p><?php foreach($result['allocations'] as $al): ?><p>充当ID <?=$e($al['id'])?> ／ 入金ID <?=$e($al['payment_id'])?> ／ <?=$e($al['amount_yen'])?> 円 ／ <?=$e($al['created_at'])?> UTC</p><?php endforeach; ?><?php endif; ?><?php if(!empty($result['payment_url_snapshot'])): ?><p><a href="<?=$e($result['payment_url_snapshot'])?>">決済ページへ</a></p><?php endif; ?><?php elseif($result): ?><p role="status">所属承認を記録しました。</p><?php endif; ?>
<h2>発行済みの請求</h2><?php foreach($invoices as $i): ?><p><a href="?invoice=<?=$e($i['id'])?>"><?=$e($i['invoice_no'].' '.$i['member_type'])?></a> （請求ID <?=$e($i['id'])?>）</p><?php endforeach; ?>
<?php if(jmrc_auth()->can('billing.confirm')): ?><h2>入金確認</h2><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>"><label>請求ID<input name="id" type="number" required></label><label>入金額<input name="amount" type="number" min="1" required></label><label>銀行取引番号<input name="reference" required></label><button>確認して充当</button></form><?php endif; ?>
<?php if(jmrc_auth()->can('billing.correct')): ?><h2>充当の訂正</h2><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="reverse"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>"><label>元の充当ID<input name="id" type="number" required></label><label>訂正理由<input name="reason" required></label><button>逆仕訳して再判定</button></form><?php endif; ?>
<?php if(jmrc_auth()->can('billing.correct')): ?><h2>訂正後の再充当</h2><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="reallocate"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>"><label>請求ID<input name="id" type="number" required></label><label>入金ID<input name="payment" type="number" required></label><label>充当額<input name="amount" type="number" min="1" required></label><button>元の請求へ再充当</button></form><?php endif; ?>
<h2>Generalの所属承認</h2><?php foreach($approvals as $a): ?><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$e($a['id'])?>"><p><?=$e($a['year'].' '.$a['email'])?> <button>所属を承認</button></p></form><?php endforeach; ?>
<p><a href="/manage/">マイページへ</a></p></html>
