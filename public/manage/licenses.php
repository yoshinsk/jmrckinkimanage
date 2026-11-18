<?php
// Path: public/manage/licenses.php
// 機能: 本人の指定複数種別・発行日を登録。旧自由記載と期限を参照で残し、受付停止を維持する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';require_once __DIR__.'/_office-layout.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\License\LicenseService(jmrc_db(),jmrc_auth_config());$result=null;$json=($_GET['format']??'')==='json';
if($_SERVER['REQUEST_METHOD']==='POST'){jmrc_auth()->verifyCsrf($_POST['_csrf']??null);$result=$service->save($user['id'],$_POST);}
elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$edit=null;if(isset($_GET['id'])){$id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}$edit=$service->read($user['id'],$id);}
$rows=$service->listing($user['id']);$reused=null;
if(!$edit&&($_GET['reuse']??'')==='entry'){$reused=(new \Jmrc\Profile\EntryProfile(jmrc_db()))->license($user['id']);}
$values=$result??$edit??$reused??[];$kind=$edit['kind']??($_POST['kind']??$_GET['kind']??'jaf_competition');
if(!is_string($kind)){throw new \Jmrc\Auth\HttpFailure(422,'競技・審判員の区分を確認してください');}
$options=\Jmrc\License\LicenseTypes::options($kind);$selected=$values['license_types']??[];$enabled=(jmrc_auth_config()['license_self_service_enabled']??false)===true;
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result??$edit??['licenses'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');jmrc_office_header('ライセンス情報','個人登録');
?>
<link rel="stylesheet" href="/manage/assets/licenses.css?v=20261118">
<p>券面を確認し、保有する種類をすべて選んでください。競技と審判員は別々に登録できます。番号は同じ番号を入力できます。</p>
<p>ライセンスは本人申告です。発行日・旧有効期限だけで資格を失効させません。番号・種類の変更では旧QRを失効し、会員証の再発行は事務局確認となります。</p>
<?php if(!$enabled):?><p class="office-notice" role="status">ライセンスの本人登録・更新は準備中です。まだ保存できません。</p><?php endif;?>
<?php if($result):?><p role="status">ライセンス情報を保存しました。</p><?php endif;?>
<nav class="office-actions" aria-label="ライセンスの登録区分"><a class="office-button secondary" href="licenses.php?kind=jaf_competition">競技の登録</a><a class="office-button secondary" href="licenses.php?kind=jaf_official">審判員の登録</a><a class="office-button secondary" href="licenses.php?reuse=entry">このアカウントの既存JAF登録情報を入力欄へ表示する</a></nav>
<?php if($reused):?><p role="status">旧JAF番号と種類を参考表示しました。種類は自動選択しません。券面で確認して選んでください。運転免許の期限や旧有効期限から発行日は補完しません。</p><?php endif;?>
<?php if(!empty($edit['notice'])):?><p role="alert"><?=$e($edit['notice'])?></p><?php endif;?>
<section class="office-panel"><h2 id="license-heading"><?=$edit?'登録内容の更新':'新規登録'?>：<?=$kind==='jaf_competition'?'競技':'審判員'?></h2>
<?php if(($values['license_type']??'')!==''&&!$selected):?><p>旧登録の種類（参考）：<?=$e($values['license_type'])?></p><?php endif;?>
<form class="license-form" method="post" action="/manage/licenses.php" aria-labelledby="license-heading" autocomplete="off">
<input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="id" value="<?=$e($edit['id']??0)?>"><input type="hidden" name="kind" value="<?=$e($kind)?>">
<?php if($edit):?><input type="hidden" name="row_version" value="<?=$e($edit['row_version'])?>"><?php endif;?>
<label for="jaf-number">JAFライセンス番号（必須）</label><input id="jaf-number" name="number" maxlength="64" value="<?=$e($values['number']??'')?>" required autocomplete="off" spellcheck="false" autocapitalize="none" autocorrect="off">
<fieldset aria-describedby="types-help"><legend>ライセンスの種類（必須・複数選択可）</legend><p id="types-help">保有する種類を1つ以上選んでください。旧自由記載から自動変換はしません。</p>
<?php foreach($options as $i=>$label):?><div class="license-choice"><input type="checkbox" id="license-type-<?=$i?>" name="license_types[]" value="<?=$e($label)?>" <?=in_array($label,$selected,true)?'checked':''?>><label for="license-type-<?=$i?>"><?=$e($label)?></label></div><?php endforeach;?></fieldset>
<label for="issued-on">発行日（任意）</label><input id="issued-on" name="issued_on" type="date" value="<?=$e($values['issued_on']??'')?>" aria-describedby="issue-help"><p id="issue-help">券面の発行日を入力します。不明な場合や複数の券面で日付が異なる場合は空欄にし、事務局へ確認してください。種別ごとの発行日の入力方法は確認中です。</p>
<?php if($edit&&$edit['expires_on']!==null):?><p>旧有効期限（参考・変更しません）：<?=$e($edit['expires_on'])?></p><?php endif;?>
<button class="office-button" type="submit" <?=!$enabled||isset($edit['notice'])?'disabled':''?>>保存する</button></form></section>
<section class="office-panel"><h2>登録済みライセンス</h2><ul><?php foreach($rows as $r):?><li><a href="?id=<?=$e($r['id'])?>"><?=$r['kind']==='jaf_competition'?'競技':'審判員'?> ／ <?=$e($r['license_type'])?> ／ 発行日：<?=$e($r['issued_on']??'未登録')?></a></li><?php endforeach;?></ul></section>
<nav class="office-actions"><a class="office-button secondary" href="card.php">会員証へ</a><a class="office-button secondary" href="memberships.php">会員申請へ</a></nav>
<?php jmrc_office_footer();
