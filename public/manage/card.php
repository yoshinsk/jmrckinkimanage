<?php
// Path: public/manage/card.php
// 機能: General本人のみのデジタル会員証。発行・QR失効はPOST、参照は最新資格を都度確認する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\Card\CardService(jmrc_db(),jmrc_auth_config());
$json=($_GET['format']??'')==='json';$result=null;
$id=filter_var($_POST['membership_id']??$_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    if (!$id) {throw new \Jmrc\Auth\HttpFailure(422,'対象の年度資格を指定してください');}
    $result=match($_POST['action']??'') {
        'issue'=>$service->issue($user['id'],$id),
        'revoke'=>$service->revoke($user['id'],$id),
        default=>throw new \Jmrc\Auth\HttpFailure(422,'操作を指定してください'),
    };
}elseif($_SERVER['REQUEST_METHOD']!=='GET') {throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
elseif($id) {$result=$service->read($user['id'],$id);}
$s=jmrc_db()->prepare("SELECT id,year FROM portal_memberships WHERE user_id=? AND member_type='general' ORDER BY year DESC");$s->execute([$user['id']]);$members=$s->fetchAll();
if ($json) {header('Content-Type: application/json; charset=utf-8');echo json_encode($result??['memberships'=>$members],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Generalデジタル会員証</title><link rel="stylesheet" href="/manage/assets/cards.css"><script src="/manage/assets/qrcodegen.js" defer></script><script src="/manage/assets/cards.js" defer></script></head>
<body><main id="card-page" data-membership-id="<?=$e($id?:'')?>"><h1>Generalデジタル会員証</h1><nav><?php foreach($members as $m): ?><a href="?id=<?=$e($m['id'])?>"><?=$e($m['year'])?>年度</a> <?php endforeach; ?></nav>
<?php if($id): ?><p id="card-status" role="status" aria-live="polite"><?=!empty($result['available'])?'現在有効な会員証です。':'会員証は現在表示できません。資格と発行状況を確認してください。'?></p>
<section id="digital-card" <?=empty($result['available'])?'hidden':''?> aria-label="会員証"><h2>JMRC近畿 General</h2><dl><dt>氏名</dt><dd data-card-field="name"><?=$e($result['name']??'')?></dd><dt>年度</dt><dd data-card-field="year"><?=$e($result['year']??'')?></dd><dt>会員番号</dt><dd data-card-field="member_number"><?=$e($result['member_number']??'')?></dd><dt>所属クラブ</dt><dd data-card-field="club_abbreviation"><?=$e($result['club_abbreviation']??'')?></dd><dt>ライセンス種類</dt><dd data-card-field="license_type"><?=$e($result['license_type']??'')?></dd></dl><div id="card-qr" data-qr-url="<?=$e($result['qr_url']??'')?>" aria-label="現在の資格を照会するQR"></div><p>QRで現在の資格を照会してください。</p></section>
<?php if(!empty($result['notice'])): ?><p><?=$e($result['notice'])?></p><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="membership_id" value="<?=$e($id)?>"><button name="action" value="issue" <?=empty($result['can_issue'])?'disabled':''?>>会員証を表示・発行する</button><button name="action" value="revoke">この年度のQRを失効する</button></form>
<?php else: ?><p>年度を選択してください。Temporaryの会員証は発行しません。</p><?php endif; ?>
<p>印刷・画像保存後も資格は変わる場合があります。QR照会は氏名や会員番号を公開しません。</p><p><a href="/manage/">マイページへ</a></p><noscript>QRの表示と画面の定期更新にはJavaScriptが必要です。</noscript></main></body></html>
