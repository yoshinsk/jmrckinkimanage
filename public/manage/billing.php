<?php
// Path: public/manage/billing.php
// 機能: 本人の会費請求と会計担当の入金確認・訂正、所属承認をCSRF保護付きで提供する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
$user=jmrc_auth()->requireIdentity();$db=jmrc_db();$service=new \Jmrc\Billing\BillingService($db);
$json=($_GET['format']??'')==='json';$result=null;
// 全年度が保留中なら、直POSTによる入金・承認・訂正も受け付けない。閲覧権限は既存サービスで確認する。
$portalReady=(bool)$db->query("SELECT EXISTS(SELECT 1 FROM portal_years WHERE source_mode='portal')")->fetchColumn();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    if(!$portalReady){throw new \Jmrc\Auth\HttpFailure(409,'会費・入金の受付は準備中です');}
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
$typeLabels=['general'=>'General','temporary'=>'Temporary','club'=>'正会員クラブ'];
$messages=['issue'=>'請求を表示しました。','confirm'=>'入金確認を記録しました。','reverse'=>'充当の訂正を記録しました。','reallocate'=>'再充当を記録しました。','approve'=>'所属承認を記録しました。'];
require_once __DIR__.'/_office-layout.php';
// フォームの送信先は固定し、閲覧中のinvoice指定で操作後の対象請求が上書きされることを防ぐ。
jmrc_office_header('会費・入金状況','会費・入金');
?>
<p class="office-intro">資格は承認・入金確認・有効期間などの条件が揃った場合に有効になります。</p>
<?php if(!$portalReady): ?><p class="portal-notice" role="status">会費・入金の受付は準備中です。請求の発行や入金の登録は行えません。</p><?php elseif($_SERVER['REQUEST_METHOD']==='POST' && $result): ?><p class="office-success" role="status"><?=$e($messages[$_POST['action']])?></p><?php endif; ?>
<nav class="office-actions" aria-label="会費の確認先"><a class="office-button secondary" href="#invoices">発行済みの請求を確認</a><a class="office-button secondary" href="/manage/memberships.php">個人会員の申請へ</a></nav>
<?php if($result && isset($result['invoice_no'])): ?>
<section class="office-panel" aria-labelledby="invoice-heading"><h2 id="invoice-heading">請求 <?=$e($result['invoice_no'])?></h2><p class="office-meta">請求ID #<?=$e($result['id'])?></p>
<div class="office-columns"><dl class="office-definition office-amounts"><dt>請求額</dt><dd data-amount="total"><?=number_format((int)$result['amount_yen'])?> 円</dd><dt>確認済み充当額</dt><dd data-amount="paid"><?=number_format((int)$result['paid_yen'])?> 円</dd><dt>残額</dt><dd data-amount="balance"><?=number_format((int)$result['balance_yen'])?> 円</dd></dl>
<?php if(isset($result['eligibility'])): ?><section aria-labelledby="eligibility-heading"><h3 id="eligibility-heading">入金・承認・資格</h3><dl class="office-definition"><dt>入金</dt><dd data-state="paid"><?=$result['eligibility']['paid']?'確認済み':'未完了'?></dd><dt>承認</dt><dd data-state="approved"><?=$result['eligibility']['approved']?'承認済み':'未完了'?></dd><dt>資格</dt><dd data-state="active"><?=$result['eligibility']['active']?'有効':'現在は無効'?></dd></dl></section><?php endif; ?></div>
<nav class="office-actions" aria-label="請求の確認"><a class="office-button secondary" href="?invoice=<?=$e($result['id'])?>">最新の資格・入金明細を表示</a><?php if($portalReady && !empty($result['payment_url_snapshot'])): ?><a class="office-button" href="<?=$e($result['payment_url_snapshot'])?>">決済ページへ</a><?php endif; ?></nav>
<?php if(isset($result['allocations'])): ?><h3>入金明細</h3><?php if(!$result['allocations']): ?><p>入金の充当はまだありません。</p><?php else: ?><ul class="office-records"><?php foreach($result['allocations'] as $al): ?><li><strong>充当ID #<?=$e($al['id'])?></strong><dl class="office-definition"><dt>入金ID</dt><dd><?=$e($al['payment_id'])?></dd><dt>充当額</dt><dd><?=number_format((int)$al['amount_yen'])?> 円</dd><dt>記録日時（UTC）</dt><dd><?=$e($al['created_at'])?></dd><?php if($al['reverses_id']!==null): ?><dt>訂正対象の充当ID</dt><dd><?=$e($al['reverses_id'])?></dd><?php endif; ?></dl></li><?php endforeach; ?></ul><?php endif; ?><?php endif; ?>
</section><?php endif; ?>
<section class="office-panel" aria-labelledby="fees-heading"><h2 id="fees-heading">申請した会員の会費</h2>
<?php if(!$members): ?><p>対象となる個人会員の申請はまだありません。</p><?php else: ?><ul class="office-records"><?php foreach($members as $m): ?><li><h3><?=$e($m['year'].'年度 '.($typeLabels[$m['member_type']]??$m['member_type']))?></h3><p class="office-meta">申請番号 #<?=$e($m['id'])?></p>
<?php if($portalReady): ?><form method="post" action="/manage/billing.php" class="office-form" aria-label="申請番号 <?=$e($m['id'])?> の請求"><input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="issue"><input type="hidden" name="id" value="<?=$e($m['id'])?>"><div class="office-submit"><button class="office-button" type="submit">請求を表示・発行</button></div></form><?php endif; ?>
</li><?php endforeach; ?></ul><?php endif; ?></section>
<section id="invoices" class="office-panel" aria-labelledby="invoices-heading"><h2 id="invoices-heading">発行済みの請求</h2><p class="office-help">直近100件を表示します。</p>
<?php if(!$invoices): ?><p>発行済みの請求はありません。</p><?php else: ?><ul class="office-list"><?php foreach($invoices as $i): ?><li><a href="?invoice=<?=$e($i['id'])?>"><strong><?=$e($i['invoice_no'])?></strong><small><?=$e($i['year'].'年度 '.($typeLabels[$i['member_type']]??$i['member_type']).' / 請求ID #'.$i['id'])?></small></a></li><?php endforeach; ?></ul><?php endif; ?></section>
<?php if($portalReady && jmrc_auth()->can('billing.confirm')): ?>
<section class="office-panel" aria-labelledby="confirm-heading"><h2 id="confirm-heading">入金確認</h2>
<form method="post" action="/manage/billing.php" class="office-form" aria-label="入金確認"><input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="confirm"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>">
<div class="office-field"><label for="confirm-id">請求ID</label><input id="confirm-id" name="id" type="number" min="1" required></div>
<div class="office-field"><label for="confirm-amount">入金額</label><input id="confirm-amount" name="amount" type="number" min="1" max="1000000000" required></div>
<div class="office-field office-wide"><label for="reference">銀行取引番号</label><input id="reference" name="reference" maxlength="100" required></div>
<div class="office-submit"><button class="office-button" type="submit">確認して充当</button></div></form></section>
<?php endif; ?>
<?php if($portalReady && jmrc_auth()->can('billing.correct')): ?>
<section class="office-panel" aria-labelledby="reverse-heading"><h2 id="reverse-heading">充当の訂正</h2>
<form method="post" action="/manage/billing.php" class="office-form" aria-label="充当の訂正"><input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="reverse"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>">
<div class="office-field"><label for="allocation-id">元の充当ID</label><input id="allocation-id" name="id" type="number" min="1" required></div>
<div class="office-field office-wide"><label for="reason">訂正理由</label><input id="reason" name="reason" required></div>
<div class="office-submit"><button class="office-button" type="submit">逆仕訳して再判定</button></div></form></section>
<section class="office-panel" aria-labelledby="reallocate-heading"><h2 id="reallocate-heading">訂正後の再充当</h2>
<form method="post" action="/manage/billing.php" class="office-form" aria-label="訂正後の再充当"><input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="reallocate"><input type="hidden" name="operation" value="<?=bin2hex(random_bytes(24))?>">
<div class="office-field"><label for="reallocate-id">請求ID</label><input id="reallocate-id" name="id" type="number" min="1" required></div>
<div class="office-field"><label for="payment-id">入金ID</label><input id="payment-id" name="payment" type="number" min="1" required></div>
<div class="office-field"><label for="reallocate-amount">充当額</label><input id="reallocate-amount" name="amount" type="number" min="1" max="1000000000" required></div>
<div class="office-submit"><button class="office-button" type="submit">元の請求へ再充当</button></div></form></section>
<?php endif; ?>
<?php if($portalReady && $approvals): ?><section class="office-panel" aria-labelledby="approvals-heading"><h2 id="approvals-heading">Generalの所属承認</h2><ul class="office-records"><?php foreach($approvals as $a): ?><li><h3><?=$e($a['year'])?>年度 / 申請番号 #<?=$e($a['id'])?></h3><p><?=$e($a['email'])?></p>
<form method="post" action="/manage/billing.php" class="office-form" aria-label="申請番号 <?=$e($a['id'])?> の所属承認"><input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$e($a['id'])?>"><div class="office-submit"><button class="office-button" type="submit">所属を承認</button></div></form>
</li><?php endforeach; ?></ul></section><?php endif; ?>
<?php jmrc_office_footer(); ?>
