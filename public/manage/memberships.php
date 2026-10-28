<?php
// Path: public/manage/memberships.php
// 機能: 本人のGeneral/Temporary申請を受付し、確定済みクラブ区分と自身の申請状況を表示する。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$user=jmrc_auth()->requireIdentity();
$db=jmrc_db();
$json=($_GET['format']??null)==='json';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    $key=$_POST['request_key']??null;
    if (!is_string($key)) { throw new \Jmrc\Auth\HttpFailure(422,'申請キーが必要です'); }
    $positive=static function (string $name): int {
        $id=filter_var($_POST[$name]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null) { throw new \Jmrc\Auth\HttpFailure(422,'申請項目を確認してください'); }
        return $id;
    };
    $service=new \Jmrc\Membership\MembershipService($db);
    $result=match ($_POST['member_type']??null) {
        'general'=>$service->submitGeneral($user['id'],$positive('year'),$positive('club_year_id'),$positive('identifier_id'),$key),
        'temporary'=>$service->submitTemporary($user['id'],$positive('event_id'),$key),
        default=>throw new \Jmrc\Auth\HttpFailure(422,'会員区分を選択してください'),
    };
    if ($json) {
        http_response_code($result['replayed']?200:201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
    }
    header('Location: /manage/memberships.php?submitted=1',true,303); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'POST required'); }
$categories=$db->query('SELECT category_digit,name FROM portal_club_categories WHERE enabled=1 ORDER BY category_digit')->fetchAll();
$licenses=$db->prepare("SELECT id,kind FROM portal_identifiers WHERE user_id=? AND kind IN ('jaf_competition','jaf_official') AND OCTET_LENGTH(value_cipher)>0
    AND (expires_on IS NULL OR expires_on>=DATE(UTC_TIMESTAMP()+INTERVAL 9 HOUR))");
$licenses->execute([$user['id']]); $licenses=$licenses->fetchAll();
$clubs=$db->query("SELECT cy.id,cy.year,c.name_full FROM portal_club_years cy JOIN portal_clubs c ON c.id=cy.club_id
    WHERE cy.status='approved' ORDER BY cy.year DESC,c.fixed_code")->fetchAll();
$events=$db->query("SELECT e.id,e.title,e.starts_at FROM portal_events e WHERE e.status='published' ORDER BY e.starts_at")->fetchAll();
$mine=$db->prepare('SELECT m.id,m.member_type,m.year,m.status,m.participation_date,a.status AS application_status
    FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id WHERE m.user_id=? ORDER BY m.id DESC');
$mine->execute([$user['id']]); $mine=$mine->fetchAll();
// 保存statusは過去のキャッシュ。日付経過・入金訂正・承認取消を閲覧時にも再評価する。
$eligibility=new \Jmrc\Billing\Eligibility($db);
foreach ($mine as &$item) { $item['eligibility']=$eligibility->evaluate((int)$item['id']); }
unset($item);
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['categories'=>$categories,'memberships'=>$mine],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><title>個人会員の申請</title>
<h1>個人会員の申請</h1>
<?php if (isset($_GET['submitted'])): ?><p role="status">申請を受け付けました。</p><?php endif; ?>
<p>申請受付の段階では会員資格は有効になりません。所属承認と入金確認が必要です。</p>
<h2>General</h2><p>JAF競技・審判員ライセンスが必要です。運転免許証だけでは加入できません。</p>
<form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="member_type" value="general">
<label>年度<input type="number" name="year" required min="2027"></label>
<label>所属クラブ<select name="club_year_id" required><?php foreach ($clubs as $club): ?>
<option value="<?=$esc($club['id'])?>"><?=$esc($club['year'].' '.$club['name_full'])?></option><?php endforeach; ?></select></label>
<label>登録済みライセンス<select name="identifier_id" required><?php foreach ($licenses as $license): ?>
<option value="<?=$esc($license['id'])?>"><?=$license['kind']==='jaf_competition'?'競技ライセンス':'審判員ライセンス'?></option><?php endforeach; ?></select></label>
<button type="submit" <?=$licenses?'':'disabled'?>>Generalを申請</button></form>
<?php if (!$licenses): ?><p>申請に使用できるライセンスが登録されていません。事務局へ確認してください。</p><?php endif; ?>
<h2>Temporary</h2><p>主催クラブの承認が必要です。開催日当日の1イベントのみ有効で、当日の取消・返金はありません。Generalへ加入する場合は別の新規申請です。</p>
<form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="member_type" value="temporary">
<label>イベント<select name="event_id" required><?php foreach ($events as $event): ?>
<option value="<?=$esc($event['id'])?>"><?=$esc($event['title'])?></option><?php endforeach; ?></select></label><button type="submit">Temporaryを申請</button></form>
<h2>申請状況</h2><ul><?php foreach ($mine as $item): ?><li><?=$esc($item['year'].' '.$item['member_type'].' '.($item['eligibility']['active']?'資格有効':($item['eligibility']['ready']?'有効期間待ち':($item['application_status']==='approved'?'承認済み・資格成立待ち':'申請中'))))?></li><?php endforeach; ?></ul>
<p><a href="/manage/billing.php">会費請求・入金状況へ</a></p>
<h2>正会員クラブ区分</h2><ul><?php foreach ($categories as $category): ?><li><?=$esc($category['category_digit'].'：'.$category['name'])?></li><?php endforeach; ?></ul>
<p><a href="/manage/">マイページへ</a></p></html>
