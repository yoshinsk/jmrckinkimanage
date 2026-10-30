<?php
// Path: public/manage/profile-correct.php
// 機能: 事務局が指定会員の氏名・生年月日を理由付きで訂正し、履歴と削除候補を確認する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$actor=jmrc_auth()->requireIdentity()['id'];$service=new \Jmrc\Profile\ProfileService(jmrc_db(),jmrc_auth_config());$service->access($actor);
$integer=static function($v):int{$n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($n===false){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}return $n;};
$user=isset($_GET['user'])?$integer($_GET['user']):null;$saved=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);if(!$user){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}
    $input=$_POST;unset($input['_csrf']);$service->correct($actor,$user,$input);$saved=true;
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$profile=$user?$service->read($actor,$user):null;$changes=$user?$service->changes($actor,$user):[];
$candidates=isset($_GET['last_year'])?$service->retentionCandidates($actor,$integer($_GET['last_year'])):null;
if(($_GET['format']??'')==='json'){header('Content-Type: application/json; charset=utf-8');echo json_encode($candidates??['profile'=>$profile,'changes'=>$changes],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=utf-8');
require_once __DIR__.'/_office-layout.php';
jmrc_office_header('会員情報の訂正・削除候補');
?>
<p class="office-intro">事務局がポータルの氏名・生年月日を訂正します。共通アカウント・既存エントリー履歴・会計記録は変更しません。</p>
<nav class="office-actions" aria-label="作業の選択"><a class="office-button secondary" href="#member">会員情報を確認・訂正</a><a class="office-button secondary" href="#retention">削除候補を確認</a></nav>
<?php if($saved): ?><p class="office-success" role="status">訂正を保存しました。</p><?php endif; ?>
<section id="member" class="office-panel" aria-labelledby="member-heading"><h2 id="member-heading">会員の選択</h2>
<form method="get" class="office-form">
<div class="office-field office-wide"><label for="user-id">会員の共通ユーザーID</label><input id="user-id" name="user" type="number" min="1" required value="<?=$e($user)?>"></div>
<div class="office-submit"><button class="office-button" type="submit">対象を確認する</button></div>
</form></section>
<?php if($profile): ?>
<section class="office-panel" aria-labelledby="profile-heading"><h2 id="profile-heading">ユーザー #<?=$e($user)?> の会員情報</h2>
<form method="post" class="office-form">
<input type="hidden" name="_csrf" value="<?=$e(jmrc_auth()->csrfToken())?>"><input type="hidden" name="row_version" value="<?=$e($profile['row_version'])?>">
<div class="office-field"><label for="last-name">姓</label><input id="last-name" name="last_name" maxlength="64" required value="<?=$e($profile['last_name'])?>"></div>
<div class="office-field"><label for="first-name">名</label><input id="first-name" name="first_name" maxlength="64" required value="<?=$e($profile['first_name'])?>"></div>
<div class="office-field"><label for="birthday">生年月日</label><input id="birthday" name="birthday" type="date" value="<?=$e($profile['birthday'])?>"></div>
<div class="office-field office-wide"><label for="reason">訂正理由</label><textarea id="reason" name="reason" rows="4" maxlength="500" required></textarea></div>
<div class="office-submit"><button class="office-button" type="submit">訂正を保存する</button></div>
</form></section>
<section class="office-panel" aria-labelledby="history-heading"><h2 id="history-heading">変更履歴</h2><p class="office-help">直近50件を表示します。</p>
<?php if(!$changes): ?><p>変更履歴はまだありません。</p><?php endif; ?>
<?php foreach($changes as $r): ?><article class="office-history"><h3><?=$e($r['created_at'])?> UTC</h3><p class="office-meta">操作者 #<?=$e($r['actor_user_id'])?></p><p>訂正理由：<?=$e($r['reason'])?></p>
<div class="office-columns"><?php foreach(['before'=>'変更前','after'=>'変更後'] as $side=>$title): ?><section><h3><?=$e($title)?></h3><dl class="office-definition"><?php foreach(['last_name'=>'姓','first_name'=>'名','birthday'=>'生年月日'] as $key=>$label): ?><dt><?=$e($label)?></dt><dd><?=$e($r[$side][$key]??'未登録')?></dd><?php endforeach; ?></dl></section><?php endforeach; ?></div>
</article><?php endforeach; ?></section>
<?php endif; ?>
<section id="retention" class="office-panel" aria-labelledby="retention-heading"><h2 id="retention-heading">未更新者の削除候補</h2>
<p class="office-readonly">現在は候補の確認のみで、削除は実行しません。</p><p>対象はポータルの会員個人情報です。再申請中の会員も候補から除きます。</p>
<form method="get" class="office-form">
<div class="office-field"><label for="last-year">最終登録年度</label><input id="last-year" name="last_year" type="number" min="2000" max="9998" required value="<?=$e($_GET['last_year']??'')?>"></div>
<div class="office-submit"><button class="office-button" type="submit">削除候補を確認する</button></div>
</form>
<?php if($candidates): ?>
<div class="office-readonly" role="status"><p><?=$candidates['due']?'候補の抽出結果です。最大200件です。':'翌年4月30日前のため対象外です。'?></p>
<?php if($candidates['due'] && !$candidates['candidates']): ?><p>該当する削除候補はありません。</p><?php endif; ?></div>
<ul class="office-list office-candidates"><?php foreach($candidates['candidates'] as $r): ?><li><strong>ユーザー #<?=$e($r['user_id'])?></strong><span>識別番号 <?=$e($r['identifier_count'])?>件</span><span>プロフィール訂正履歴 <?=$e($r['profile_history_count'])?>件</span></li><?php endforeach; ?></ul>
<?php endif; ?>
</section>
<?php jmrc_office_footer(); ?>
