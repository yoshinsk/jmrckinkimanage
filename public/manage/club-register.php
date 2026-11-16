<?php
// Path: public/manage/club-register.php
// 機能: 事前確認なしのクラブ申請、更新候補、暗号化した正式入力、申請後の事務局照合と料金確認をスマートフォンで提供する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
use Jmrc\Auth\HttpFailure;
use Jmrc\Club\ClubIntakeService;
use Jmrc\Club\ClubIntakePolicy;
use Jmrc\Club\ClubRegistrationData;
use Jmrc\Club\ClubFeePolicy;
$auth=jmrc_auth();$user=$auth->requireIdentity();$db=jmrc_db();$config=jmrc_auth_config();$service=new ClubIntakeService($db,$config);
$office=$service->isOffice($user['id']);$json=($_GET['format']??'')==='json';$quote=null;$record=null;$values=[];$reuse=null;
if($_SERVER['REQUEST_METHOD']==='GET'&&!isset($_GET['id'])&&($_GET['reuse']??'')==='entry'){$reuse=(new \Jmrc\Profile\EntryProfile($db))->club($user['id']);if($reuse){$values=$reuse['values'];}}
if(($_GET['all']??'')==='1'&&!$office){throw new HttpFailure(403,'事務局の一覧権限が必要です');}
$number=static function(array $in,string $key,bool $zero=false):int{$n=filter_var($in[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1]]);if($n===false){throw new HttpFailure(422,'対象・年度を確認してください');}return $n;};
$text=static function(array $in,string $key,string $default=''):string{$v=$in[$key]??$default;if(!is_string($v)){throw new HttpFailure(422,'入力形式を確認してください');}return $v;};
if($_SERVER['REQUEST_METHOD']==='POST'){
    $auth->verifyCsrf($_POST['_csrf']??null);$action=$text($_POST,'action');$out=null;
    if($action==='quote'){$values=$_POST;$quote=ClubRegistrationData::quote($db,$number($_POST,'year'),$text($_POST,'jaf_kind'),$text($_POST,'membership_kind'),$text($_POST,'registration_kind'));}
    elseif($action==='submit'){$out=$service->submit($user['id'],$number($_POST,'year'),$_POST,$text($_POST,'request_key'));}
    elseif($action==='update'){$out=$service->update($user['id'],$number($_POST,'id'),$number($_POST,'row_version'),$_POST);}
    elseif($action==='return'){$out=$service->returnForCorrection($user['id'],$number($_POST,'id'),$number($_POST,'row_version'),$text($_POST,'reason'));}
    elseif($action==='link'){
        $target=$text($_POST,'target_ref');$club=0;$legacy=0;
        if($target!==''){if(!preg_match('/^(portal|legacy):([1-9][0-9]{0,18})$/D',$target,$m)){throw new HttpFailure(422,'照合するクラブを選択してください');}if($m[1]==='portal'){$club=(int)$m[2];}else{$legacy=(int)$m[2];}}
        $out=$service->link($user['id'],$number($_POST,'id'),$number($_POST,'row_version'),$club,$text($_POST,'fixed_code'),($_POST['checked']??'')==='1',$legacy);
    }elseif($action==='issue'){
        $r=$service->read($user['id'],$number($_POST,'id'));if(!$r['club_year_id']){throw new HttpFailure(409,'申請後のクラブ照合が必要です');}
        $out=(new \Jmrc\Billing\BillingService($db))->issueClub($user['id'],(int)$r['club_year_id']);
        if(!$json){header('Location: /manage/club-finance.php?intake='.$r['id'],true,303);exit;}
    }else{throw new HttpFailure(422,'操作を選択してください');}
    if($out!==null){if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}header('Location: /manage/club-register.php?id='.$out['id'],true,303);exit;}
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new HttpFailure(405,'POST required');}
$query=$text($_GET,'q');$candidates=$service->candidates($user['id'],$query);
if(isset($_GET['id'])){$record=$service->read($user['id'],$number($_GET,'id'));if(!$values){$values=$record['data'];$values['year']=$record['year'];}}
$years=$db->query('SELECT * FROM portal_years ORDER BY year DESC')->fetchAll();$year=(int)($values['year']??($years[0]['year']??2027));$selected=null;foreach($years as $y){if((int)$y['year']===$year){$selected=$y;}}
$open=$selected&&ClubIntakePolicy::open($selected,new DateTimeImmutable('now',new DateTimeZone('UTC')),$config);
$listing=$service->listing($user['id'],$office&&($_GET['all']??'')==='1');
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode(['open'=>(bool)$open,'office'=>$office,'record'=>$record,'applications'=>$listing,'candidates'=>$candidates,'quote'=>$quote],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$csrf=$e($auth->csrfToken());
$select=static function(string $key,array $options,string $current)use($e):void{echo '<select id="club-'.$e($key).'" name="'.$e($key).'" required><option value="">選択してください</option>';foreach($options as $v=>$label){echo '<option value="'.$e($v).'"'.((string)$v===$current?' selected':'').'>'.$e($label).'</option>';}echo '</select>';};
$hidden=static function(string $action)use($csrf,$e,$record):void{echo '<input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="action" value="'.$e($action).'">';if($record){echo '<input type="hidden" name="id" value="'.$e($record['id']).'"><input type="hidden" name="row_version" value="'.$e($record['row_version']).'">';}};
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>クラブ登録の申請</title><script src="/manage/assets/input-normalization.js" defer></script><script src="/manage/assets/club-intake.js" defer></script><link rel="stylesheet" href="/manage/assets/dashboard.css"><link rel="stylesheet" href="/manage/assets/office.css"></head>
<body class="office-page"><a class="skip-link" href="#main">本文へ移動</a><header class="portal-header"><div class="portal-container portal-header-inner"><a class="portal-brand" href="/manage/">JMRC近畿ポータル</a><span class="account-email"><?=$e($user['email'])?></span></div></header>
<main id="main" class="portal-container" tabindex="-1"><h1>クラブ登録の申請</h1>
<p>事前の事務局確認や代表者権限がなくても申請する方式です。「更新」を選ぶと、提出後に事務局が既存クラブと照合します。候補を選ぶだけではクラブの操作権限は付きません。</p>
<p class="portal-notice" role="status"><?=$open?'クラブ登録の受付中です。':'クラブ登録は受付開始前・準備中です。まだ申請を提出できません。料金と入力項目は確認できます。'?></p>
<p>クラブ受付は12月1日、個人会員受付と正本切替は1月1日に同時実施する方針です。ここで個人会員受付や正本の設定は変更しません。</p>
<nav class="office-actions" aria-label="クラブ手続き"><a class="office-button secondary" href="/manage/club-register.php?reuse=entry">既存エントリーのクラブ情報を使う</a><a class="office-button secondary" href="/manage/club-finance.php">クラブ会費・承認状況</a></nav>
<?php if($reuse):?><p class="portal-notice" role="status">このアカウントの既存クラブ情報を入力欄へ反映しました。JAF番号・区分・登録方法を確認し、空欄の追加項目を入力してください。既存の代表者連絡先：<?=$e($reuse['rep_contact_reference'])?>。申請や権限の付与はまだ行っていません。</p><?php elseif(($_GET['reuse']??'')==='entry'):?><p class="portal-notice" role="status">このアカウントに紐づく有効な旧クラブ情報はありません。新規入力できます。</p><?php endif;?>
<?php if($quote):?><section class="office-panel"><h2>選択した区分の会費</h2><p>年会費 <?=number_format($quote['annual_yen'])?>円 ＋ 入会金 <?=number_format($quote['joining_yen'])?>円 ＝ 合計 <?=number_format($quote['total_yen'])?>円</p><p>料金確認だけでは申請・請求・入金記録は作成されません。</p></section><?php endif;?>
<?php if($record):?><section class="office-panel"><h2>申請状況</h2><p>申請番号 <?=$e($record['id'])?> ／ <?=$e(['submitted'=>'提出済み','renewal_review'=>'更新：事務局の照合待ち','returned'=>'差し戻し：訂正して再申請してください','linked'=>'年度登録へ接続済み'][$record['review_state']])?></p><p>年会費 <?=number_format((int)$record['annual_yen'])?>円・入会金 <?=number_format((int)$record['joining_yen'])?>円・合計 <?=number_format($record['total_yen'])?>円</p>
<?php if($record['review_state']==='returned'):?><p class="portal-notice">訂正のお願い：<?=$e($record['data']['return_reason']??'')?></p><?php endif;?>
<?php if($record['club_year_id']):?><p>入金確認・事務局承認がそろうまで年度資格は有効になりません。</p><a class="office-button" href="/manage/club-finance.php?intake=<?=$e($record['id'])?>">振込先・入金・承認状況を確認する</a><?php endif;?></section><?php endif;?>
<section class="office-panel"><h2>既存クラブの更新候補</h2><form method="get" class="office-form"><?php if($record):?><input type="hidden" name="id" value="<?=$e($record['id'])?>"><?php endif;?><div class="office-field"><label for="candidate-query">クラブ名・正式略称・コード</label><input id="candidate-query" name="q" value="<?=$e($query)?>" maxlength="128"></div><div class="office-submit"><button class="office-button secondary">更新候補を探す</button></div></form><p>候補は最大50件です。見つからない場合も名称を入力して申請でき、更新の照合は事務局が行います。</p></section>
<section class="office-panel"><h2><?=$record?'提出した登録内容':'登録内容を入力する'?></h2>
<form method="post" class="office-form" id="club-intake-form"><?php echo '<input type="hidden" name="_csrf" value="'.$csrf.'">';?>
<?php if($record):?><input type="hidden" name="id" value="<?=$e($record['id'])?>"><input type="hidden" name="row_version" value="<?=$e($record['row_version'])?>"><?php else:?><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><?php endif;?>
<div class="office-field"><label for="club-year">登録年度</label><select id="club-year" name="year" required><?php foreach($years as $y):?><option value="<?=$e($y['year'])?>" <?=(int)$y['year']===$year?'selected':''?>><?=$e($y['year'])?>年度</option><?php endforeach;?></select></div>
<div class="office-field"><label for="club-registration_kind">登録方法</label><?php $select('registration_kind',ClubRegistrationData::REGISTRATIONS,(string)($values['registration_kind']??''));?></div>
<div class="office-field"><label for="club-jaf_number">JAF登録番号（5桁・必須）</label><input id="club-jaf_number" name="jaf_number" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" required value="<?=$e($values['jaf_number']??'')?>"><p class="office-help">JAFの登録番号を入力してください。登録番号とJAF区分は、申請後に事務局が確認します。</p></div>
<div class="office-field office-wide"><label for="club-candidate_ref">更新候補（未選択でも申請可能）</label><select id="club-candidate_ref" name="candidate_ref"><option value="">候補を選ばず入力する</option><?php $found=false;foreach($candidates as $c):$selected=($values['candidate_ref']??'')===$c['ref'];$found=$found||$selected;?><option value="<?=$e($c['ref'])?>" data-name="<?=$e($c['name'])?>" data-short="<?=$e($c['short_name'])?>" <?=$selected?'selected':''?>><?=$e($c['name'].' ／ '.$c['short_name'].' ／ '.$c['code'])?></option><?php endforeach;if(!$found&&!empty($values['candidate_ref'])):?><option value="<?=$e($values['candidate_ref'])?>" selected>提出時の更新候補 <?=$e($values['candidate_ref'])?></option><?php endif;?></select></div>
<?php foreach(ClubRegistrationData::LABELS as $key=>$label):?><div class="office-field"><label for="club-<?=$e($key)?>"><?=$e($label)?><?=$key==='company'?'（任意）':''?></label>
<?php if(in_array($key,['jaf_kind','membership_kind','region'],true)):$options=match($key){'jaf_kind'=>ClubFeePolicy::CATEGORIES,'membership_kind'=>ClubRegistrationData::MEMBERSHIPS,default=>\Jmrc\Club\ClubContact::REGIONS};$select($key,$options,(string)($values[$key]??''));else:$type=str_ends_with($key,'email')?'email':(str_contains($key,'phone')||str_contains($key,'mobile')?'tel':'text');?><input id="club-<?=$e($key)?>" name="<?=$e($key)?>" type="<?=$type?>" maxlength="255" value="<?=$e($values[$key]??'')?>" <?=$key==='company'?'':'required'?> <?=str_starts_with($key,'contact_')?'data-contact="1"':''?>><?php endif;?></div><?php endforeach;?>
<div class="office-field"><label for="club-region_other">その他の所属支部</label><input id="club-region_other" name="region_other" value="<?=$e($values['region_other']??'')?>" maxlength="255"></div>
<div class="office-field"><label for="club-same_contact">代表者が連絡先を兼ねる</label><select id="club-same_contact" name="same_contact"><option value="0">別の担当者</option><option value="1" <?=($values['same_contact']??'')==='1'?'selected':''?>>代表者と同じ（連絡先の再入力を省略）</option></select></div>
<div class="office-field office-wide"><label for="club-bank_details">クラブの口座情報（任意）</label><textarea id="club-bank_details" name="bank_details" maxlength="2000"><?=$e($values['bank_details']??'')?></textarea><p class="office-help">会費を振り込むJMRC近畿の口座とは別の、クラブ側の口座です。暗号化して保存します。</p></div>
<div class="office-submit"><button class="office-button secondary" name="action" value="quote" formnovalidate>会費だけ確認する</button><button class="office-button" name="action" value="<?=$record?'update':'submit'?>" <?=!$open||($record&&$record['review_state']==='linked')?'disabled':''?>><?=$record?($record['review_state']==='returned'?'訂正して再申請する':'申請内容を訂正する'):'申請を提出する'?></button></div></form></section>
<?php if($office&&$record&&in_array($record['review_state'],['submitted','renewal_review'],true)):?><section class="office-panel"><h2>申請の差し戻し</h2><p>番号や区分が誤っている場合は、理由を記載して差し戻します。申請者が訂正して再申請するまで、年度登録へ接続できません。</p><form method="post" class="office-form" aria-label="申請の差し戻し"><?php $hidden('return');?><div class="office-field office-wide"><label for="return-reason">訂正してほしい項目と理由</label><textarea id="return-reason" name="reason" required maxlength="2000"></textarea></div><div class="office-submit"><button class="office-button secondary" <?=ClubIntakePolicy::enabled($config)?'':'disabled'?>>理由を添えて差し戻す</button></div></form></section><?php endif;?>
<?php if($office&&$record&&$record['review_state']!=='linked'):?><section class="office-panel"><h2>提出後の事務局照合</h2><p>更新・再加入は既存クラブを選択します。新規の5桁コードは採番方法を自動推測せず、事務局で確定した値を指定します。これは入金後の年度承認とは別の、登録情報の接続です。</p>
<form method="post" class="office-form"><?php $hidden('link');?><div class="office-field office-wide"><label for="target-ref">照合した既存クラブ（新規は未選択）</label><select id="target-ref" name="target_ref"><option value="">新規クラブ</option><?php foreach($candidates as $c):?><option value="<?=$e($c['ref'])?>" <?=($record['data']['candidate_ref']??'')===$c['ref']?'selected':''?>><?=$e($c['name'].' ／ '.$c['short_name'].' ／ '.$c['code'])?></option><?php endforeach;?></select></div><div class="office-field"><label for="fixed-code">確定した固定5桁コード（新規・旧台帳から初回接続の場合）</label><input id="fixed-code" name="fixed_code" inputmode="numeric" maxlength="5"></div><div class="office-field office-wide"><label><input type="checkbox" name="checked" value="1" required>申請者・クラブ・区分・登録履歴・コードを照合しました</label></div><div class="office-submit"><button class="office-button" <?=ClubIntakePolicy::enabled($config)?'':'disabled'?>>照合結果を年度登録へ接続する</button></div></form></section><?php endif;?>
<section class="office-panel"><h2><?=$office&&($_GET['all']??'')==='1'?'事務局の申請一覧':'自分の申請一覧'?></h2><?php if($office):?><p><a href="?all=1">事務局：提出済み申請を確認する</a></p><?php endif;?><ul class="office-list"><?php foreach($listing as $item):?><li><a href="?id=<?=$e($item['id'])?>"><?=$e($item['year'].' '.$item['data']['club_name'].' ／ '.ClubRegistrationData::REGISTRATIONS[$item['registration_kind']])?></a> <?=$item['review_state']==='renewal_review'?'更新：事務局照合待ち':''?></li><?php endforeach;?></ul></section>
<p><a href="/manage/">マイページへ戻る</a></p></main></body></html>
