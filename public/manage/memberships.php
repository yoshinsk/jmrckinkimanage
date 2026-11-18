<?php
// Path: public/manage/memberships.php
// 機能: 本人のGeneral/Temporary申請を受付し、確定済みクラブ区分と自身の申請状況を表示する。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$user=jmrc_auth()->requireIdentity();
$db=jmrc_db();
$json=($_GET['format']??null)==='json';
$reuse=($_GET['reuse']??'')==='entry';$entryProfile=$reuse?(new \Jmrc\Profile\EntryProfile($db))->personal($user['id']):null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    $key=$_POST['request_key']??null;
    if (!is_string($key)) { throw new \Jmrc\Auth\HttpFailure(422,'申請キーが必要です'); }
    $positive=static function (string $name): int {
        $id=filter_var($_POST[$name]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null) { throw new \Jmrc\Auth\HttpFailure(422,'申請項目を確認してください'); }
        return $id;
    };
    $service=new \Jmrc\Membership\MembershipService($db,jmrc_auth_config());
    $result=match ($_POST['member_type']??null) {
        'general'=>$service->submitGeneral($user['id'],$positive('year'),$positive('club_year_id'),$positive('identifier_id'),$key,$_POST),
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
$licenses=(new \Jmrc\License\LicenseService($db,jmrc_auth_config()))->listing($user['id']);
$clubs=$db->query("SELECT cy.id,cy.year,COALESCE(cy.name_full,c.name_full) AS name_full,COALESCE(cy.name_short,c.name_short) AS name_short,cy.display_code FROM portal_club_years cy JOIN portal_clubs c ON c.id=cy.club_id
    WHERE cy.status='approved' ORDER BY cy.year DESC,c.fixed_code")->fetchAll();
$mine=$db->prepare('SELECT m.id,m.member_type,m.year,m.status,m.participation_date,d.competition_type,d.official_type,a.status AS application_status
    FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id LEFT JOIN portal_membership_declarations d ON d.membership_id=m.id WHERE m.user_id=? ORDER BY m.id DESC');
$mine->execute([$user['id']]); $mine=$mine->fetchAll();
// 保存statusは過去のキャッシュ。日付経過・入金訂正・承認取消を閲覧時にも再評価する。
$eligibility=new \Jmrc\Billing\Eligibility($db);
foreach ($mine as &$item) { $item['eligibility']=$eligibility->evaluate((int)$item['id']); }
unset($item);
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['categories'=>$categories,'memberships'=>$mine]+($reuse?['reused_profile'=>$entryProfile]:[]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
header('Content-Type: text/html; charset=utf-8');
// 申請フォームには本人受付中の年度だけを提示する。マスターの変更や受付開始は行わない。
$openYears=$db->query("SELECT year FROM portal_years WHERE source_mode='portal' AND member_self_open_at IS NOT NULL
    AND member_self_open_at<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<valid_until ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
$clubs=array_filter($clubs,static fn($club)=>in_array($club['year'],$openYears));
$suggested=array_values(array_filter($clubs,static fn($club)=>$entryProfile&&$club['display_code']===trim((string)$entryProfile['club_code'])));
$suggestedId=count($suggested)===1?(int)$suggested[0]['id']:0;$types=[];
if($reuse){foreach($licenses as $license){foreach($license['license_types'] as $type){$types[$license['kind']][]=$type;}}}
$events=$db->query("SELECT e.id,e.title,e.participation_date FROM portal_events e
    JOIN portal_years y ON y.year=e.year JOIN portal_club_years cy ON cy.id=e.organizer_club_year_id AND cy.year=e.year
    JOIN portal_club_categories cc ON cc.category_digit=cy.category_digit
    WHERE e.status='published' AND cy.status='approved' AND cc.enabled=1 AND y.source_mode='portal'
    AND y.member_self_open_at IS NOT NULL AND y.member_self_open_at<=UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6)<y.valid_until
    AND e.participation_date>=DATE(UTC_TIMESTAMP()+INTERVAL 9 HOUR)
    AND DATE(e.ends_at-INTERVAL 1 MICROSECOND+INTERVAL 9 HOUR)=e.participation_date
    AND YEAR(e.starts_at+INTERVAL 9 HOUR)=e.year ORDER BY e.starts_at")->fetchAll();
// 画面の非表示だけに依存せず、同じ保留をMembershipServiceでも検証する。
$temporaryEnabled=(jmrc_auth_config()['temporary_intake_enabled']??false)===true;
if(!$temporaryEnabled){$events=[];}
$applicationLabels=['draft'=>'下書き','submitted'=>'申請中','approved'=>'承認済み','rejected'=>'不承認','returned'=>'差戻し','cancelled'=>'取消済み'];
require_once __DIR__.'/_office-layout.php';
jmrc_office_header('個人会員の申請','会員情報');
?>
<link rel="stylesheet" href="/manage/assets/licenses.css?v=20261118">
<nav class="office-actions" aria-label="登録情報の再利用"><a class="office-button secondary" href="/manage/memberships.php?reuse=entry">既存の個人登録情報を使う</a><a class="office-button secondary" href="/manage/licenses.php?reuse=entry">既存のJAFライセンス情報を確認する</a></nav>
<?php if($entryProfile):?><section class="office-panel" aria-labelledby="reused-profile"><h2 id="reused-profile">このアカウントの登録済み情報</h2><p>氏名・生年月日はポータルの訂正済み情報を優先します。住所・連絡先・所属の候補は既存エントリーから参照します。再登録は不要です。変更が必要な場合は事務局へ連絡してください。</p><dl class="office-definition"><?php foreach(['last_name'=>'姓','first_name'=>'名','last_kana'=>'姓ふりがな','first_kana'=>'名ふりがな','entry_name'=>'競技会エントリー名','birthday'=>'生年月日','postcode'=>'郵便番号','address'=>'住所','phone'=>'電話','contact_mobile'=>'携帯電話','club_abbrev'=>'旧登録の所属クラブ','club_code'=>'旧クラブコード'] as $key=>$label):?><dt><?=$esc($label)?></dt><dd><?=$esc($entryProfile[$key]??'未登録')?></dd><?php endforeach;?></dl><p>旧所属は入力候補です。同年度の承認済みクラブを確認して申請してください。過去の個人会員登録・入金だけで新年度の資格を有効にすることはありません。</p></section><?php elseif($reuse):?><p class="portal-notice">このアカウントの既存個人登録情報はありません。申請条件と受付日程を確認してください。</p><?php endif;?>
<p class="office-intro">申請受付の段階では会員資格は有効になりません。所属承認と入金確認が必要です。</p>
<?php if(!$openYears): ?><p class="portal-notice" role="status">個人会員の申請は現在、受付を行っていません。受付状況は事務局へご確認ください。</p><?php elseif(isset($_GET['submitted'])): ?><p class="office-success" role="status">申請を受け付けました。</p><?php endif; ?>
<nav class="office-actions" aria-label="申請の確認先"><a class="office-button secondary" href="#applications">申請状況を確認</a><a class="office-button secondary" href="/manage/billing.php">会費・入金状況へ</a></nav>
<section class="office-panel" aria-labelledby="general-heading"><h2 id="general-heading">General</h2>
<p>JAF競技・審判員ライセンスが必要です。運転免許証だけでは加入できません。</p>
<?php if($openYears): ?>
<form method="post" class="office-form" aria-label="General申請">
<input type="hidden" name="_csrf" value="<?=$esc(jmrc_auth()->csrfToken())?>"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><input type="hidden" name="member_type" value="general">
<div class="office-field"><label for="general-year">年度</label><select id="general-year" name="year" required><option value="">年度を選択</option><?php foreach($openYears as $year): ?><option value="<?=$esc($year)?>"><?=$esc($year)?>年度</option><?php endforeach; ?></select></div>
<div class="office-field office-wide"><label for="club-year">所属クラブ</label><select id="club-year" name="club_year_id" required aria-describedby="club-help"><option value="">所属クラブを選択</option><?php foreach($clubs as $club): ?><option value="<?=$esc($club['id'])?>" <?=(int)$club['id']===$suggestedId?'selected':''?>><?=$esc($club['year'].' '.$club['name_short'].'（'.$club['name_full'].'）')?></option><?php endforeach; ?></select><p id="club-help" class="office-help">上で選択した年度の所属クラブを選んでください。旧コードと一致する候補が1件なら表示します。確定は申請後の所属承認で行います。</p></div>
<div class="office-field office-wide"><label for="license-id">登録済みライセンス</label><select id="license-id" name="identifier_id" required><option value="">ライセンスを選択</option><?php foreach($licenses as $license): ?><option value="<?=$esc($license['id'])?>"><?=$license['kind']==='jaf_competition'?'競技ライセンス':'審判員ライセンス'?></option><?php endforeach; ?></select></div>
<p class="office-help office-wide">JAFライセンス番号は競技・審判員で共通です。両方の種類を選んでください。保有しない側は「なし」だけを選びます。旧自由記載は自動変換しません。</p>
<?php foreach(['competition_type'=>['jaf_competition','競技ライセンスの種類'],'official_type'=>['jaf_official','審判員ライセンスの種類']] as $field=>[$kind,$title]):?>
<fieldset class="license-form office-wide"><legend><?=$esc($title)?>（必須・複数選択可）</legend>
<?php foreach(array_merge(['なし'],\Jmrc\License\LicenseTypes::options($kind)) as $index=>$label):$control=$field.'-'.$index;?>
<div class="license-choice"><input id="<?=$esc($control)?>" type="checkbox" name="<?=$field?>[]" value="<?=$esc($label)?>" <?=in_array($label,$types[$kind]??[],true)?'checked':''?>><label for="<?=$esc($control)?>"><?=$esc($label)?></label></div>
<?php endforeach;?></fieldset><?php endforeach;?>
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
<?php else: ?><p class="office-readonly"><?=$temporaryEnabled&&$openYears?'現在、申請できるイベントはありません。':'Temporaryの申請受付は準備中です。'?></p><?php endif; ?>
</section>
<section id="applications" class="office-panel" aria-labelledby="applications-heading"><h2 id="applications-heading">申請状況</h2>
<?php if(!$mine): ?><p>個人会員の申請はまだありません。</p><?php else: ?><ul class="office-records"><?php foreach($mine as $item): ?><li><h3><?=$esc($item['year'])?>年度 <?=$item['member_type']==='general'?'General':'Temporary'?></h3><dl class="office-definition"><dt>申請番号</dt><dd>#<?=$esc($item['id'])?></dd><dt>申請</dt><dd><?=$esc($applicationLabels[$item['application_status']]??$item['application_status'])?></dd><dt>資格</dt><dd><?=$item['eligibility']['active']?'有効':($item['status']==='suspended'?'資格停止中':($item['eligibility']['ready']?'有効期間外':'現在は無効'))?></dd><?php if($item['competition_type']!==null): ?><dt>申告した競技種別</dt><dd><?=$esc($item['competition_type'])?></dd><dt>申告した審判員種別</dt><dd><?=$esc($item['official_type'])?></dd><?php endif; ?><?php if($item['participation_date']): ?><dt>参加日</dt><dd><?=$esc($item['participation_date'])?></dd><?php endif; ?></dl></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<details class="office-disclosure"><summary>正会員クラブ区分</summary><ul class="office-list"><?php foreach($categories as $category): ?><li><?=$esc($category['category_digit'].'：'.$category['name'])?></li><?php endforeach; ?></ul></details>
<?php jmrc_office_footer(); ?>
