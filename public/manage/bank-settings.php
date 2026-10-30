<?php
// Path: public/manage/bank-settings.php
// 機能: 事務局の専用権限で用途別口座・決済URL・実口座対応を編集し、変更履歴を確認する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\Bank\BankMasterService(jmrc_db(),jmrc_auth_config());$service->access($user['id']);
$json=($_GET['format']??'')==='json';$result=null;
$positive=static function($v):int{$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}return $id;};
if($_SERVER['REQUEST_METHOD']==='POST'){
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    // 鍵チェック・暗号文・更新者などをHTTPから直接指定させない。
    $allowed=['_csrf','action','id','row_version','purpose_code','year','display_name','details','payment_url','valid_from','valid_until','enabled','source_id','mapping_verified','reason','code','physical_verified'];
    foreach(array_keys($_POST) as $key){if(!in_array($key,$allowed,true)){throw new \Jmrc\Auth\HttpFailure(422,'未対応の入力項目です');}}
    $result=match($_POST['action']??''){'account'=>$service->saveAccount($user['id'],$_POST),'source'=>$service->saveSource($user['id'],$_POST),default=>throw new \Jmrc\Auth\HttpFailure(422,'操作を指定してください')};
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$account=isset($_GET['account'])?$service->read($user['id'],$positive($_GET['account'])):null;
$source=isset($_GET['source'])?$service->sourceRead($user['id'],$positive($_GET['source'])):null;
$change=isset($_GET['history'])?$service->change($user['id'],$positive($_GET['history'])):null;
$list=$service->listing($user['id']);$sourceForm=$source!==null || ($_GET['new']??'')==='source';
$history=$account?$service->changes($user['id'],'account',(int)$account['id'],isset($_GET['before'])?$positive($_GET['before']):0):($source?$service->changes($user['id'],'source',(int)$source['id'],isset($_GET['before'])?$positive($_GET['before']):0):[]);
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result??$change??$account??$source??$list,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$local=static fn($v)=>$v===null?'':(new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d\TH:i:s.u');
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__.'/_office-layout.php';
jmrc_office_header('入金先・決済URLの設定');
?>
<p class="office-intro">変更は新しい請求に反映されます。発行済み請求の案内・URLは変更しません。銀行口座を変更する場合は新規設定を作成してください。同じ実口座には同じCSV取込元を対応付けます。</p>
<?php if($result): ?>
<div class="office-success" role="status"><p>設定を保存しました。#<?=$e($result['id'])?></p><a class="office-button secondary" href="?<?=$sourceForm||($_POST['action']??'')==='source'?'source':'account'?>=<?=$e($result['id'])?>">保存した設定を確認</a></div>
<?php endif; ?>
<nav class="office-actions" aria-label="設定の新規登録"><a class="office-button secondary" href="bank-settings.php">口座の新規登録</a><a class="office-button secondary" href="?new=source">取込元の新規登録</a></nav>
<div class="office-columns">
  <details class="office-disclosure"><summary>用途別口座（<?=count($list['accounts'])?>件）</summary>
    <?php if(!$list['accounts']): ?><p>口座設定はまだありません。</p><?php else: ?>
    <ul class="office-list"><?php foreach($list['accounts'] as $a): ?><li><a href="?account=<?=$e($a['id'])?>"><strong><?=$e($a['display_name'])?></strong><small><?=$e('#'.$a['id'].' / '.(\Jmrc\Bank\BankMasterService::PURPOSES[$a['purpose_code']]??$a['purpose_code']).' / '.($a['year']??'全年度').' / '.($a['enabled']?'有効':'無効'))?></small></a></li><?php endforeach; ?></ul><?php endif; ?>
  </details>
  <details class="office-disclosure"><summary>CSV取込元・実口座（<?=count($list['sources'])?>件）</summary>
    <?php if(!$list['sources']): ?><p>CSV取込元はまだありません。</p><?php else: ?>
    <ul class="office-list"><?php foreach($list['sources'] as $s): ?><li><a href="?source=<?=$e($s['id'])?>"><strong><?=$e($s['display_name'])?></strong><small><?=$e('#'.$s['id'].' / '.($s['enabled']?'有効':'停止'))?></small></a></li><?php endforeach; ?></ul><?php endif; ?>
  </details>
</div>
<?php if(!$change && !$sourceForm): ?>
<section class="office-panel" aria-labelledby="account-heading">
<h2 id="account-heading"><?=$account?'口座設定の変更 #'.$e($account['id']):'口座設定の新規登録'?></h2>
<?php if($account && $account['details_notice']): ?><p class="office-readonly"><?=$e($account['details_notice'])?></p><?php endif; ?>
<form class="office-form" method="post" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="account"><input type="hidden" name="id" value="<?=$e($account['id']??0)?>"><input type="hidden" name="row_version" value="<?=$e($account['row_version']??0)?>">
<fieldset><legend>口座・決済URL</legend><div class="office-fields">
  <div class="office-field"><label for="purpose">用途</label><select id="purpose" name="purpose_code" aria-describedby="purpose-help"><?php if($account && !isset(\Jmrc\Bank\BankMasterService::PURPOSES[$account['purpose_code']])): ?><option value="<?=$e($account['purpose_code'])?>" selected><?=$e('既存の用途（'.$account['purpose_code'].'）')?></option><?php endif; ?><?php foreach(\Jmrc\Bank\BankMasterService::PURPOSES as $code=>$name): ?><option value="<?=$e($code)?>" <?=($account['purpose_code']??'')===$code?'selected':''?>><?=$e($name)?></option><?php endforeach; ?></select></div>
  <div class="office-field"><label for="year">年度</label><select id="year" name="year" aria-describedby="purpose-help"><option value="">全年度</option><?php foreach($list['years'] as $y): ?><option value="<?=$e($y['year'])?>" <?=(int)($account['year']??0)===(int)$y['year']?'selected':''?>><?=$e($y['year'])?></option><?php endforeach; ?></select></div>
  <p id="purpose-help" class="office-help office-wide">登録済みの用途・年度を変更する場合は、新しい設定を作成してください。</p>
  <div class="office-field office-wide"><label for="display-name">口座の表示名</label><input id="display-name" name="display_name" maxlength="128" value="<?=$e($account['display_name']??'')?>" required></div>
  <div class="office-field office-wide"><label for="details">口座案内</label><textarea id="details" name="details" rows="5" maxlength="4000" aria-describedby="details-help" <?=empty($account)?'required':''?>><?=$e($account['details']??'')?></textarea><p id="details-help" class="office-help">金融機関・支店・種別・番号・名義などの案内を入力します。更新時の空欄は既存値を保持します。使用済み設定では案内を変更できません。</p></div>
  <div class="office-field office-wide"><label for="payment-url">ペイメントコアURL</label><input id="payment-url" name="payment_url" type="url" maxlength="2048" value="<?=$e($account['payment_url']??'')?>" placeholder="https://" aria-describedby="url-help"><p id="url-help" class="office-help">空欄でURLを解除できます。URLへのアクセスや支払済み登録は自動実行しません。</p></div>
</div></fieldset>
<fieldset><legend>利用期間・状態</legend><div class="office-fields">
  <div class="office-field"><label for="valid-from">開始日時（日本時間）</label><input id="valid-from" name="valid_from" type="datetime-local" step="0.000001" value="<?=$e($local($account['valid_from']??null))?>"></div>
  <div class="office-field"><label for="valid-until">終了日時（日本時間・その時刻は対象外）</label><input id="valid-until" name="valid_until" type="datetime-local" step="0.000001" value="<?=$e($local($account['valid_until']??null))?>"></div>
  <div class="office-field"><label for="enabled">請求先としての利用</label><select id="enabled" name="enabled"><option value="0" <?=empty($account['enabled'])?'selected':''?>>無効</option><option value="1" <?=!empty($account['enabled'])?'selected':''?>>有効</option></select></div>
</div></fieldset>
<fieldset><legend>CSV取込元との対応</legend><div class="office-fields">
  <div class="office-field office-wide"><label for="source-id">CSV取込元</label><select id="source-id" name="source_id"><option value="0">対応なし</option><?php foreach($list['sources'] as $s): ?><option value="<?=$e($s['id'])?>" <?=(int)($account['source_id']??0)===(int)$s['id']?'selected':''?>><?=$e($s['display_name'].' '.($s['enabled']?'':'（停止中）'))?></option><?php endforeach; ?></select></div>
  <label class="office-check office-wide"><input type="checkbox" name="mapping_verified" value="1"><span>選択した取込元と同じ実口座であることを資料で確認しました</span></label>
</div></fieldset>
<div class="office-field office-wide"><label for="reason">変更理由</label><input id="reason" name="reason" maxlength="500" required></div>
<div class="office-submit"><button class="office-button" type="submit">口座設定を保存</button></div>
</form></section>
<?php elseif(!$change): ?>
<section class="office-panel" aria-labelledby="source-heading">
<h2 id="source-heading"><?=$source?'取込元の変更 #'.$e($source['id']):'取込元の新規登録'?></h2>
<form class="office-form" method="post">
<input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="action" value="source"><input type="hidden" name="id" value="<?=$e($source['id']??0)?>"><input type="hidden" name="row_version" value="<?=$e($source['row_version']??0)?>">
<div class="office-field"><label for="code">実口座コード</label><input id="code" name="code" pattern="[A-Za-z0-9_.:-]{1,64}" value="<?=$e($source['code']??'')?>" <?=$source?'readonly':''?> required></div>
<div class="office-field"><label for="source-name">取込元の表示名</label><input id="source-name" name="display_name" maxlength="128" value="<?=$e($source['display_name']??'')?>" required></div>
<div class="office-field"><label for="source-enabled">CSV取込の利用</label><select id="source-enabled" name="enabled"><option value="0" <?=empty($source['enabled'])?'selected':''?>>停止</option><option value="1" <?=!empty($source['enabled'])?'selected':''?>>有効</option></select></div>
<?php if(!$source): ?><label class="office-check office-wide"><input type="checkbox" name="physical_verified" value="1" required><span>既存取込元と重複しない実口座であることを確認しました</span></label><?php endif; ?>
<div class="office-field office-wide"><label for="reason">変更理由</label><input id="reason" name="reason" maxlength="500" required></div>
<div class="office-submit"><button class="office-button" type="submit">取込元を保存</button></div>
</form></section>
<?php endif; ?>
<?php if($history): ?>
<section class="office-panel" aria-labelledby="history-heading"><h2 id="history-heading">変更履歴</h2>
<ul class="office-list"><?php foreach($history as $h): ?><li><a href="?history=<?=$e($h['id'])?>"><?=$e('#'.$h['id'].' / '.$h['changed_at'].' UTC / 担当ID '.$h['actor_user_id'].' / 版 '.$h['row_version'])?></a></li><?php endforeach; ?></ul>
<?php if(count($history)===50): ?><a class="office-button secondary" href="?<?=$account?'account':'source'?>=<?=$e($account['id']??$source['id'])?>&amp;before=<?=$e(end($history)['id'])?>">以前の履歴</a><?php endif; ?>
</section><?php endif; ?>
<?php if($change): ?>
<section class="office-panel" aria-labelledby="change-heading"><h2 id="change-heading">変更履歴 #<?=$e($change['id'])?></h2>
<p class="office-meta"><?=$e('担当ID '.$change['actor_user_id'].' / '.$change['changed_at'].' UTC')?></p><p class="office-readonly"><?=$e('変更理由：'.$change['reason'])?></p>
<?php $labels=['id'=>'設定ID','purpose_code'=>'用途','year'=>'年度','display_name'=>'表示名','payment_url'=>'ペイメントコアURL','valid_from'=>'開始日時（UTC）','valid_until'=>'終了日時（UTC）','enabled'=>'有効状態','updated_by'=>'更新担当ID','updated_at'=>'更新日時（UTC）','row_version'=>'版','source_id'=>'CSV取込元ID','details'=>'口座案内','code'=>'実口座コード']; ?>
<div class="office-columns"><?php foreach(['before'=>'変更前','after'=>'変更後'] as $side=>$title): ?>
<section><h3><?=$e($title)?></h3><?php if($change[$side]===null): ?><p>未登録</p><?php else: ?><dl class="office-definition"><?php foreach($change[$side] as $key=>$value): ?><dt><?=$e($labels[$key]??$key)?></dt><dd><?=$e($key==='purpose_code'?(\Jmrc\Bank\BankMasterService::PURPOSES[$value]??$value):($key==='enabled'?($value?'有効':'無効'):($value??'未設定')))?></dd><?php endforeach; ?></dl><?php endif; ?></section>
<?php endforeach; ?></div>
<a class="office-button secondary" href="?<?=$change['entity_type']==='account'?'account':'source'?>=<?=$e($change['entity_id'])?>">対象の設定へ</a></section>
<?php endif; ?>
<?php // 設定の管理権限と、CSV取込の利用開始・権限は別に判定する。
if((jmrc_auth_config()['yucho_import_enabled']??false)===true && jmrc_auth()->can('billing.confirm')): ?>
<p><a class="office-button secondary" href="bank.php">CSV取込・入金照合へ</a></p>
<?php else: ?><p class="office-readonly">CSV取込・入金照合は準備中です。</p><?php endif; ?>
<?php jmrc_office_footer(); ?>
