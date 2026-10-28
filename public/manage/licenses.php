<?php
// Path: public/manage/licenses.php
// 機能: 本人のJAF競技/審判員ライセンス登録・版管理更新。口座鍵を共有するが用途別AADで暗号を分離。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\License\LicenseService(jmrc_db(),jmrc_auth_config());$result=null;$json=($_GET['format']??'')==='json';
if($_SERVER['REQUEST_METHOD']==='POST'){jmrc_auth()->verifyCsrf($_POST['_csrf']??null);$result=$service->save($user['id'],$_POST);}
elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$edit=null;if(isset($_GET['id'])){$id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}$edit=$service->read($user['id'],$id);}
$rows=$service->listing($user['id']);
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result??$edit??['licenses'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ライセンス情報</title><link rel="stylesheet" href="/manage/assets/cards.css"><link rel="stylesheet" href="/manage/assets/licenses.css"></head><body><main><h1>ライセンス情報</h1>
<p>期限だけの更新では会員番号を変えません。番号・種類の変更では旧QRを失効し、会員証の再発行は事務局確認となります。</p>
<?php if($result): ?><p role="status">ライセンス情報を保存しました。</p><?php endif; ?>
<?php if(!empty($edit['notice'])): ?><p role="alert"><?=$e($edit['notice'])?></p><?php endif; ?>
<h2><?=$edit?'登録内容の更新':'新規登録'?></h2><form class="license-form" method="post" autocomplete="off"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="id" value="<?=$e($edit['id']??0)?>"><?php if($edit): ?><input type="hidden" name="row_version" value="<?=$e($edit['row_version'])?>"><input type="hidden" name="kind" value="<?=$e($edit['kind'])?>"><p>区分：<?=$edit['kind']==='jaf_competition'?'JAF競技':'JAF審判員'?></p><?php else: ?><label>区分<select name="kind"><option value="jaf_competition">JAF競技</option><option value="jaf_official">JAF審判員</option></select></label><?php endif; ?>
<label>JAFライセンス番号<input name="number" maxlength="64" value="<?=$e($edit['number']??'')?>" required autocomplete="off" spellcheck="false"></label><label>ライセンス種類<input name="license_type" maxlength="40" value="<?=$e($edit['license_type']??'')?>"></label><label>有効期限<input name="expires_on" type="date" value="<?=$e($edit['expires_on']??'')?>"></label><p>期限不明の場合は空欄にし、事務局へ確認してください。運転免許証の期限は入力しません。</p><button type="submit" <?=isset($edit['notice'])?'disabled':''?>>保存する</button></form>
<h2>登録済みライセンス</h2><ul><?php foreach($rows as $r): ?><li><a href="?id=<?=$e($r['id'])?>"><?=$r['kind']==='jaf_competition'?'JAF競技':'JAF審判員'?> ／ <?=$e($r['license_type']??'')?> ／ <?=$e($r['expires_on']??'期限未登録')?></a></li><?php endforeach; ?></ul><p><a href="licenses.php">新規登録へ</a> ／ <a href="card.php">会員証へ</a> ／ <a href="memberships.php">会員申請へ</a></p></main></body></html>
