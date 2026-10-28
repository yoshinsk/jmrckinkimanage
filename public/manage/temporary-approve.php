<?php
// Path: public/manage/temporary-approve.php
// 機能: 主催クラブの明示権限を持つ担当者だけがTemporary申請を確認・承認する。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$user=jmrc_auth()->requireIdentity(); $db=jmrc_db(); $json=($_GET['format']??null)==='json';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    $id=filter_var($_POST['membership_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { throw new \Jmrc\Auth\HttpFailure(422,'対象の申請を選択してください'); }
    $result=(new \Jmrc\Membership\MembershipService($db))->approveTemporary($user['id'],$id);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
    }
    header('Location: /manage/temporary-approve.php?approved=1',true,303); exit;
}
if ($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'POST required'); }
$statement=$db->prepare("SELECT DISTINCT m.id,m.participation_date,e.title,a.status,
    COALESCE(NULLIF(TRIM(CONCAT_WS(' ',pf.last_name,pf.first_name)),''),u.email) AS applicant
    FROM portal_memberships m JOIN users u ON u.id=m.user_id LEFT JOIN portal_profiles pf ON pf.user_id=m.user_id
    JOIN portal_applications a ON a.id=m.application_id JOIN portal_events e ON e.id=m.event_id
    JOIN portal_club_years cy ON cy.id=e.organizer_club_year_id
    JOIN portal_user_role_grants g ON g.scope_club_id=cy.club_id AND g.scope_type='club' AND g.user_id=?
    JOIN portal_role_permissions p ON p.role_id=g.role_id AND p.permission_code='temporary.approve'
    WHERE m.member_type='temporary' AND a.status='submitted' AND g.status='active'
    AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL AND g.valid_from<=UTC_TIMESTAMP(6)
    AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until) ORDER BY m.participation_date,m.id");
$statement->execute([$user['id']]); $rows=$statement->fetchAll();
if ($json) {
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><title>Temporaryの承認</title><h1>Temporaryの承認</h1>
<?php if (isset($_GET['approved'])): ?><p role="status">主催クラブの承認を記録しました。入金確認前の会員資格は有効になりません。</p><?php endif; ?>
<?php foreach ($rows as $row): ?><form method="post">
<p><?=$esc($row['participation_date'].' '.$row['title'].' ／ '.$row['applicant'])?></p><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<input type="hidden" name="membership_id" value="<?=$esc($row['id'])?>"><button type="submit">承認する</button></form><?php endforeach; ?>
<?php if (!$rows): ?><p>担当する主催クラブの承認待ち申請はありません。</p><?php endif; ?>
<p><a href="/manage/">マイページへ</a></p></html>
