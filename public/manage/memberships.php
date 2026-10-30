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
// 申請フォームには本人受付中の年度だけを提示する。マスターの変更や受付開始は行わない。
$openYears=$db->query("SELECT year FROM portal_years WHERE source_mode='portal' AND member_self_open_at IS NOT NULL
    AND member_self_open_at<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<valid_until ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
$clubs=array_filter($clubs,static fn($club)=>in_array($club['year'],$openYears));
$events=$db->query("SELECT e.id,e.title,e.participation_date FROM portal_events e
    JOIN portal_years y ON y.year=e.year JOIN portal_club_years cy ON cy.id=e.organizer_club_year_id AND cy.year=e.year
    JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
    WHERE e.status='published' AND cy.status='approved' AND cc.enabled=1 AND y.source_mode='portal'
    AND y.member_self_open_at IS NOT NULL AND y.member_self_open_at<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<y.valid_until
    AND e.participation_date>=DATE(UTC_TIMESTAMP()+INTERVAL 9 HOUR)
    AND DATE(e.ends_at-INTERVAL 1 MICROSECOND+INTERVAL 9 HOUR)=e.participation_date
    AND YEAR(e.starts_at+INTERVAL 9 HOUR)=e.year ORDER BY e.starts_at")->fetchAll();
$applicationLabels=['draft'=>'下書き','submitted'=>'申請中','approved'=>'承認済み','rejected'=>'不承認','returned'=>'差戻し','cancelled'=>'取消済み'];
require_once __DIR__.'/_office-layout.php';
jmrc_office_header('個人会員の申請','会員情報');
?>
<p class="office-intro">申請受付の段階では会員資格は有効になりません。所属承認と入金確認が必要です。</p>
<?php if(!$openYears): ?><p class="portal-notice" role="status">個人会員の申請は現在、受付を行っていません。受付状況は事務局へご確認ください。</p><?php elseif(isset($_GET['submitted'])): ?><p class="office-success" role="status">申請を受け付けました。</p><?php endif; ?>
<nav class="office-actions" aria-label="申請の確認先"><a class="office-button secondary" href="#applications">申請状況を確認</a><a class="office-button secondary" href="/manage/billing.php">会費・入金状況へ</a></nav>
<section class="office-panel" aria-labelledby="general-heading"><h2 id="general-heading">General</h2>
<p>JAF競技・審判員ライセンスが必要です。運転免許証だけでは加入できません。</p>
<?php if($openYears): ?>
<form method="post" class="office-form" aria-label="General申請">
<input type="hidden" name="_csrf" value="<?=$esc(jmrc_auth()->csrfToken())?>"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="member_type" value="general">
<div class="office-field"><label for="general-year">年度</label><select id="general-year" name="year" required><option value="">年度を選択</option><?php foreach($openYears as $year): ?><option value="<?=$esc($year)?>"><?=$esc($year)?>年度</option><?php endforeach; ?></select></div>
<div class="office-field office-wide"><label for="club-year">所属クラブ</label><select id="club-year" name="club_year_id" required aria-describedby="club-help"><option value="">所属クラブを選択</option><?php foreach($clubs as $club): ?><option value="<?=$esc($club['id'])?>"><?=$esc($club['year'].' '.$club['name_full'])?></option><?php endforeach; ?></select><p id="club-help" class="office-help">上で選択した年度の所属クラブを選んでください。</p></div>
<div class="office-field office-wide"><label for="license-id">登録済みライセンス</label><select id="license-id" name="identifier_id" required><option value="">ライセンスを選択</option><?php foreach($licenses as $license): ?><option value="<?=$esc($license['id'])?>"><?=$license['kind']==='jaf_competition'?'競技ライセンス':'審判員ライセンス'?></option><?php endforeach; ?></select></div>
<?php if(!$licenses): ?><p class="office-readonly office-wide">申請に使用できるライセンスが登録されていません。事務局へ確認してください。</p><?php endif; ?>
<?php if(!$clubs): ?><p class="office-readonly office-wide">選択できる承認済みクラブがありません。事務局へ確認してください。</p><?php endif; ?>
<div class="office-submit"><button type="submit" class="office-button" <?=$licenses && $clubs?'':'disabled'?>>Generalを申請</button></div>
</form>
<?php else: ?><p class="office-readonly">Generalの申請受付は準備中です。</p><?php endif; ?>
</section>
<section class="office-panel" aria-labelledby="temporary-heading"><h2 id="temporary-heading">Temporary</h2>
<p>主催クラブの承認が必要です。開催日当日の1イベントのみ有効で、当日の取消・返金はありません。Generalへ加入する場合は別の新規申請です。</p>
<?php if($openYears && $events): ?>
<form method="post" class="office-form" aria-label="Temporary申請"><input type="hidden" name="_csrf" value="<?=$esc(jmrc_auth()->csrfToken())?>"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="member_type" value="temporary">
<div class="office-field office-wide"><label for="event-id">イベント</label><select id="event-id" name="event_id" required><option value="">イベントを選択</option><?php foreach($events as $event): ?><option value="<?=$esc($event['id'])?>"><?=$esc($event['participation_date'].' '.$event['title'])?></option><?php endforeach; ?></select></div>
<div class="office-submit"><button type="submit" class="office-button">Temporaryを申請</button></div>
</form>
<?php else: ?><p class="office-readonly"><?=$openYears?'現在、申請できるイベントはありません。':'Temporaryの申請受付は準備中です。'?></p><?php endif; ?>
</section>
<section id="applications" class="office-panel" aria-labelledby="applications-heading"><h2 id="applications-heading">申請状況</h2>
<?php if(!$mine): ?><p>個人会員の申請はまだありません。</p><?php else: ?><ul class="office-records"><?php foreach($mine as $item): ?><li><h3><?=$esc($item['year'])?>年度 <?=$item['member_type']==='general'?'General':'Temporary'?></h3><dl class="office-definition"><dt>申請番号</dt><dd>#<?=$esc($item['id'])?></dd><dt>申請</dt><dd><?=$esc($applicationLabels[$item['application_status']]??$item['application_status'])?></dd><dt>資格</dt><dd><?=$item['eligibility']['active']?'有効':($item['status']==='suspended'?'資格停止中':($item['eligibility']['ready']?'有効期間外':'現在は無効'))?></dd><?php if($item['participation_date']): ?><dt>参加日</dt><dd><?=$esc($item['participation_date'])?></dd><?php endif; ?></dl></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<details class="office-disclosure"><summary>正会員クラブ区分</summary><ul class="office-list"><?php foreach($categories as $category): ?><li><?=$esc($category['category_digit'].'：'.$category['name'])?></li><?php endforeach; ?></ul></details>
<?php jmrc_office_footer(); ?>
