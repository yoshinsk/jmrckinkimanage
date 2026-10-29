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
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>会員情報の訂正</title><link rel="stylesheet" href="/manage/assets/cards.css"><link rel="stylesheet" href="/manage/assets/licenses.css"></head><body><main><h1>会員情報の訂正</h1>
<p>事務局がポータルの氏名・生年月日を訂正します。共通アカウント・既存エントリー履歴・会計記録は変更しません。</p>
<?php if($saved): ?><p role="status">訂正を保存しました。</p><?php endif; ?>
<form method="get" class="license-form"><label>会員の共通ユーザーID<input name="user" type="number" min="1" required value="<?=$e($user)?>"></label><button>対象を確認する</button></form>
<?php if($profile): ?><form method="post" class="license-form"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="row_version" value="<?=$e($profile['row_version'])?>">
<label>姓<input name="last_name" maxlength="64" required value="<?=$e($profile['last_name'])?>"></label><label>名<input name="first_name" maxlength="64" required value="<?=$e($profile['first_name'])?>"></label><label>生年月日<input name="birthday" type="date" value="<?=$e($profile['birthday'])?>"></label><label>訂正理由<textarea name="reason" maxlength="500" required></textarea></label><button>訂正を保存する</button></form>
<h2>変更履歴</h2><p>直近50件を表示します。</p><?php foreach($changes as $r): ?><article><p><?=$e($r['created_at'])?> UTC ／ 操作者 #<?=$e($r['actor_user_id'])?></p><p><?=$e($r['reason'])?></p><dl><?php foreach(['last_name'=>'姓','first_name'=>'名','birthday'=>'生年月日'] as $key=>$label): ?><dt><?=$e($label)?></dt><dd><?=$e($r['before'][$key])?> → <?=$e($r['after'][$key])?></dd><?php endforeach; ?></dl></article><?php endforeach; ?><?php endif; ?>
<h2>未更新者の削除候補</h2><p>対象はポータルの会員個人情報です。現在は候補の確認のみで、削除は実行しません。再申請中の会員も候補から除きます。</p><form method="get" class="license-form"><label>最終登録年度<input name="last_year" type="number" min="2000" max="9998" required></label><button>削除候補を確認する</button></form>
<?php if($candidates): ?><p><?=$candidates['due']?'候補の抽出結果です。最大200件です。':'翌年4月30日前のため対象外です。'?></p><ul><?php foreach($candidates['candidates'] as $r): ?><li>ユーザー #<?=$e($r['user_id'])?> ／ 識別番号 <?=$e($r['identifier_count'])?>件 ／ プロフィール訂正履歴 <?=$e($r['profile_history_count'])?>件</li><?php endforeach; ?></ul><?php endif; ?>
<p><a href="/manage/">マイページへ</a></p></main></body></html>
