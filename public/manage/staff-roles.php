<?php
// Path: public/manage/staff-roles.php
// 機能: 共通アカウントの職務申請・相互承認。未設定時は受付停止し、事務局は読取だけを確認できる。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$auth=jmrc_auth();$actor=$auth->requireIdentity()['id'];
$enabled=(jmrc_auth_config()['staff_role_requests_enabled']??false)===true;
if (!$enabled) { $auth->requirePermission('profiles.correct'); }
$service=new \Jmrc\Auth\StaffRoleService(jmrc_db(),$enabled);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $auth->verifyCsrf($_POST['_csrf']??null);
    if (!$enabled) { throw new \Jmrc\Auth\HttpFailure(503,'役職の申請受付は準備中です'); }
    if (($_POST['action']??null)==='request' && is_string($_POST['role']??null)) {
        $service->request($actor,$_POST['role']);
    } elseif (($_POST['action']??null)==='approve') {
        $id=filter_var($_POST['grant']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !$version) { throw new \Jmrc\Auth\HttpFailure(422,'申請番号・更新版を確認してください'); }
        $service->approve($actor,$id,$version);
    } else { throw new \Jmrc\Auth\HttpFailure(422,'操作を確認してください'); }
    header('Location: /manage/staff-roles.php?saved=1',true,303);exit;
} elseif ($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'GETまたはPOSTを使用してください'); }
$data=$service->overview($actor);
if (($_GET['format']??null)==='json') { header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
require_once __DIR__.'/_office-layout.php';jmrc_office_header('役職の申請・承認');
?>
<p class="office-intro">自分の役職と、自分が承認できる申請を確認します。役職を兼任していても、自分の申請は承認できません。</p>
<?php if (!$enabled): ?><p class="office-readonly" role="status">役職の申請受付は準備中です。現在は確認だけで、役職を変更できません。</p><?php endif; ?>
<?php if ($enabled && isset($_GET['saved'])): ?><p class="office-success" role="status">下の現在の状態を確認してください。</p><?php endif; ?>
<section class="office-panel"><h2>相互承認のルール</h2><p>事務局・運営委員の登録は承認者が承認します。承認者の登録は運営委員が承認します。</p></section>
<?php if ($enabled): ?><section class="office-panel"><h2>自分の役職を申請する</h2><form method="post" class="office-form">
<input type="hidden" name="_csrf" value="<?=$e($auth->csrfToken())?>"><input type="hidden" name="action" value="request">
<div class="office-field"><label for="role">希望する役職</label><select id="role" name="role" required><?php foreach (\Jmrc\Auth\StaffRolePolicy::LABELS as $code=>$label): ?><option value="<?=$e($code)?>"><?=$e($label)?></option><?php endforeach; ?></select></div>
<div class="office-submit"><button class="office-button" type="submit">役職を申請する</button></div></form></section><?php endif; ?>
<section class="office-panel"><h2>自分の役職・承認対象</h2>
<?php if (!$data['grants']): ?><p>表示できる役職・申請はありません。</p><?php endif; ?>
<?php foreach ($data['grants'] as $row): ?><article class="office-history"><h3><?=$e(\Jmrc\Auth\StaffRolePolicy::LABELS[\Jmrc\Auth\StaffRolePolicy::canonical($row['code'])]??$row['code'])?></h3>
<p><?=$e($row['email'])?> / 状態：<?=$e(['pending'=>'承認待ち','active'=>$row['effective']?'有効':'登録済み（現在は無効）','revoked'=>'取消済み'][$row['status']]??$row['status'])?></p>
<?php if ($enabled && $row['can_approve']): ?><form method="post" class="office-form"><input type="hidden" name="_csrf" value="<?=$e($auth->csrfToken())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="grant" value="<?=$e($row['id'])?>"><input type="hidden" name="version" value="<?=$e($row['row_version'])?>"><div class="office-submit"><button class="office-button" type="submit">この役職登録を承認する</button></div></form><?php endif; ?>
</article><?php endforeach; ?>
<?php if ($data['truncated']): ?><p>直近200件を表示しています。それ以前の確認は事務局へご連絡ください。</p><?php endif; ?>
</section><?php jmrc_office_footer(); ?>
