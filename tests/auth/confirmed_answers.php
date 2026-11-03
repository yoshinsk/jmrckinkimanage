<?php
// Path: tests/auth/confirmed_answers.php
// 機能: 11月3日回答の料金・成立条件・申告保存・役職相互承認を架空DBで検証し、役職fixtureを復元する。
declare(strict_types=1);
foreach(['Auth/StaffRolePolicy','Auth/StaffRoleService','Club/ClubFeePolicy','Membership/LicenseDeclaration'] as $rule) { require_once '/srv/auth/src/'.$rule.'.php'; }
use Jmrc\Auth\StaffRolePolicy;
use Jmrc\Auth\StaffRoleService;
use Jmrc\Auth\HttpFailure;
use Jmrc\Club\ClubFeePolicy;
use Jmrc\Membership\LicenseDeclaration;

$denied=static function(string $label,callable $f,int $status=403): void {
    try { $f();check($label,false); } catch(HttpFailure $error) { check($label,$error->status===$status); }
};
foreach(['OFFICE','COMMITTEE','FINANCE','APPROVER'] as $role) { check('事務局同等の役職 '.$role,StaffRolePolicy::officeEquivalent($role)); }
check('旧entry管理者は自動昇格しない',!StaffRolePolicy::officeEquivalent('JMRC_ADMIN'));
check('兼任でも自己承認しない',!StaffRolePolicy::canApprove(1,1,['APPROVER','COMMITTEE'],'OFFICE'));
// C07補足: 不明をfalseへ変換せず、例外が成立する場合と成立しない場合を分ける。
foreach([[true,true,'regular'],[true,false,'supporting'],[false,true,'supporting'],[false,false,'supporting'],
    [null,null,null],[true,null,null],[null,true,null],[false,null,'supporting'],[null,false,'supporting']] as [$jaf,$organizer,$expected]) {
    check('他地域クラブの条件 '.json_encode([$jaf,$organizer]),ClubFeePolicy::outsideKinkiMembership(2027,$jaf,$organizer)===$expected);
}
try { ClubFeePolicy::outsideKinkiMembership(2028,true,true);check('翌年度へ例外を無断適用しない',false); }
catch(InvalidArgumentException) { check('翌年度へ例外を無断適用しない',true); }
foreach(['new'=>10000,'renewal'=>0,'rejoin'=>0] as $kind=>$joining) {
    foreach(['regular','supporting'] as $membership) {
        foreach(['official'=>50000,'affiliated'=>30000,'associate'=>20000,'organization'=>$membership==='supporting'?10000:20000] as $category=>$annual) {
            $quote=ClubFeePolicy::quote(2027,$category,$membership,$kind);
            check('クラブ料金 '.$membership.' '.$category.' '.$kind,$quote['annual_yen']===$annual && $quote['joining_yen']===$joining && $quote['total_yen']===$annual+$joining);
        }
    }
    check('賛助企業は入会金なし '.$kind,ClubFeePolicy::quote(2027,'business','supporting',$kind)['joining_yen']===0);
}
foreach([[2028,'official','regular','new'],[2027,'business','regular','new'],[2027,'6','regular','new']] as $args) {
    try { ClubFeePolicy::quote(...$args);check('未確定の料金を補完しない',false); }
    catch(InvalidArgumentException) { check('未確定年度・数字コード・非賛助企業を拒否',true); }
}
check('2台はクラス不成立',!\Jmrc\Series\SeriesRules::classEstablished(2,false));
check('3台はクラス成立',\Jmrc\Series\SeriesRules::classEstablished(3,false));
check('出走台数があっても中止は除外',!\Jmrc\Series\SeriesRules::classEstablished(9,true));
check('終了時3戦がシリーズ成立',\Jmrc\Series\SeriesRules::seriesEstablished(3,true));
check('開催中・2戦以下は年間成立としない',!\Jmrc\Series\SeriesRules::seriesEstablished(3,false) && !\Jmrc\Series\SeriesRules::seriesEstablished(2,true));
check('6戦の高得点4戦だけが有効',\Jmrc\Series\SeriesRules::effectiveScores([2,20,15,6,10,0],6)===[20,15,10,6]);
check('表彰上限は6位と平均の半分の両方',\Jmrc\Series\SeriesRules::awardLimit(70,7)===5 && \Jmrc\Series\SeriesRules::awardLimit(100,7)===6 && \Jmrc\Series\SeriesRules::awardLimit(21,7)===1);
foreach([[],['competition_type'=>'なし','official_type'=>'なし'],['competition_type'=>[], 'official_type'=>'なし'],['competition_type'=>str_repeat('競',81),'official_type'=>'なし']] as $input) {
    $denied('未申告・型不正・競技種別なしを拒否',fn()=>LicenseDeclaration::validate($input,'jaf_competition'),422);
}
check('審判員のみの申告',LicenseDeclaration::validate(['competition_type'=>'なし','official_type'=>'確認用審判種別'],'jaf_official')['competition_type']==='なし');
check('申請時の両種別がDBに保存される',(int)$db->query("SELECT COUNT(*) FROM portal_membership_declarations d JOIN portal_memberships m ON m.id=d.membership_id WHERE m.member_type='general' AND d.competition_type='確認用競技種別' AND d.official_type='確認用審判種別'")->fetchColumn()>0);

check('役職試験は隔離DBだけ',$db->query('SELECT DATABASE()')->fetchColumn()==='jmrckinki_auth_lab');
$oldRoles=$db->query('SELECT * FROM portal_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$oldPermissions=$db->query('SELECT * FROM portal_role_permissions ORDER BY role_id,permission_code')->fetchAll(PDO::FETCH_ASSOC);
$ids=[90101,90102,90103,90104,90105,90106];
try {
    $db->exec(file_get_contents('/srv/tests/staff_permissions.sql'));
    $insert=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,3,?,1,?)');
    foreach($ids as $id) { $insert->execute([$id,'u'.$id.'@example.invalid',password_hash('lab-password-only',PASSWORD_DEFAULT)]);$db->exec('INSERT INTO portal_account_controls(user_id) VALUES('.$id.')'); }
    // 初期役職は架空の第三者が付与済みのfixture。通常申請の自己承認経路では作らない。
    foreach([90101=>'APPROVER',90102=>'COMMITTEE'] as $id=>$role) {
        $s=$db->prepare("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by) SELECT ?,id,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,7,7 FROM portal_roles WHERE code=?");$s->execute([$id,$role]);
    }
    $db->exec("UPDATE portal_user_role_grants SET approved_by=NULL,bootstrap_approval_ref='fixture-external-initial' WHERE user_id=90101");
    $initialBrowser=new Browser();$initialBrowser->login(90101);check('外部指定の初期承認者も共通認証で利用可能',$initialBrowser->request('/manage/review.php')['status']===200);
    $service=new StaffRoleService($db,true);
    $denied('役職受付保留では書込しない',fn()=>(new StaffRoleService($db))->request(90103,'OFFICE'),503);
    $office=$service->request(90103,'OFFICE');
    check('役職申請再送は同じpending',$service->request(90103,'OFFICE')['id']===$office['id']);
    $denied('運営委員は事務局登録を承認しない',fn()=>$service->approve(90102,$office['id'],1));
    $denied('旧entry管理者も承認不可',fn()=>$service->approve(1,$office['id'],1));
    $denied('古い版で承認しない',fn()=>$service->approve(90101,$office['id'],2),409);
    check('承認者が事務局を承認',$service->approve(90101,$office['id'],1)['status']==='active');
    $committee=$service->request(90104,'COMMITTEE');
    check('承認者が運営委員を承認',$service->approve(90101,$committee['id'],1)['status']==='active');
    $approver=$service->request(90105,'APPROVER');
    $denied('承認者同士での登録承認は不可',fn()=>$service->approve(90101,$approver['id'],1));
    check('運営委員が承認者を承認',$service->approve(90102,$approver['id'],1)['status']==='active');
    $self=$service->request(90101,'OFFICE');
    $denied('実grantに承認者があっても自己承認不可',fn()=>$service->approve(90101,$self['id'],1));
    $waiting=$service->request(90106,'OFFICE');
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=90101 AND status='active'");
    $denied('承認者取消を次の操作に反映',fn()=>$service->approve(90101,$waiting['id'],1));
    foreach([90102,90104,90105] as $id) {
        $b=new Browser();$b->login($id);
        check('運営委員・承認者は事務局画面を閲覧 '.$id,$b->request('/manage/review.php')['status']===200);
        check('運営委員・承認者は口座と訂正の業務権限を持つ '.$id,$b->request('/manage/bank-settings.php')['status']===200 && $b->request('/manage/profile-correct.php')['status']===200);
    }
    check('相互承認の操作ログを記録',(int)$db->query("SELECT COUNT(*) FROM portal_audit_events WHERE action='STAFF_ROLE_APPROVED' AND actor_user_id IN (90101,90102)")->fetchColumn()===3);
} finally {
    // 後続のスマートフォン試験へ権限変更を持ち越さない。対象はこの試験が作ったIDだけ。
    $set=implode(',',$ids);
    $db->exec("DELETE FROM portal_audit_events WHERE actor_user_id IN ($set);DELETE FROM portal_user_role_grants WHERE user_id IN ($set);DELETE FROM portal_account_controls WHERE user_id IN ($set);DELETE FROM audit_logs WHERE user_id IN ($set);DELETE FROM users WHERE id IN ($set)");
    $db->exec('DELETE FROM portal_role_permissions');
    foreach($oldPermissions as $row) { $s=$db->prepare('INSERT INTO portal_role_permissions(role_id,permission_code,created_at) VALUES(?,?,?)');$s->execute(array_values($row)); }
    $db->exec('DELETE FROM portal_roles WHERE id NOT IN ('.implode(',',array_column($oldRoles,'id')).')');
    foreach($oldRoles as $row) { $s=$db->prepare('UPDATE portal_roles SET code=?,name=?,created_at=?,updated_at=?,row_version=? WHERE id=?');$s->execute([$row['code'],$row['name'],$row['created_at'],$row['updated_at'],$row['row_version'],$row['id']]); }
}
check('役職と権限のfixtureを元へ戻す',$db->query('SELECT * FROM portal_roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$oldRoles && $db->query('SELECT * FROM portal_role_permissions ORDER BY role_id,permission_code')->fetchAll(PDO::FETCH_ASSOC)===$oldPermissions);
