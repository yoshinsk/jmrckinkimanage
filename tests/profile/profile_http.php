<?php
// Path: tests/profile/profile_http.php
// 機能: 追加回答の事務局訂正・暗号履歴・削除候補・受付日程・保険人数条件を架空HTTPSで検証する。
declare(strict_types=1);
require_once '/srv/auth/src/Profile/ProfileService.php';
$pOffice=new Browser();$pOffice->login(7);$pOwner=new Browser();$pOwner->login(60);$pRep=new Browser();$pRep->login(3);$pFinance=new Browser();$pFinance->login(62);$pOld=new Browser();$pOld->login(1);$pAnon=new Browser();
$pPath='/manage/profile-correct.php?format=json&user=60';
foreach(['匿名'=>$pAnon,'本人'=>$pOwner,'クラブ代表'=>$pRep,'財務のみ'=>$pFinance,'旧管理者'=>$pOld] as $label=>$b){check('プロフィール訂正の権限拒否 '.$label,$b->request($pPath)['status']===($label==='匿名'?401:403));}
$pRead=json_decode($pOffice->request($pPath)['body'],true);$pBefore=$pRead['profile'];
$pInput=['last_name'=>'訂正<姓>','first_name'=>'確認名','birthday'=>'1988-02-29','reason'=>'架空本人確認による訂正','row_version'=>$pBefore['row_version']];
function profilePost(Browser $b,string $path,array $in): array{return $b->request($path,$in+['_csrf'=>identity($b)['csrf']]);}
check('プロフィール訂正はCSRF必須',$pOffice->request($pPath,$pInput)['status']===400);
check('プロフィール不正な日付を拒否',profilePost($pOffice,$pPath,array_replace($pInput,['birthday'=>'2025-02-29']))['status']===422);
check('プロフィール未来の生年月日を拒否',profilePost($pOffice,$pPath,array_replace($pInput,['birthday'=>'2099-01-01']))['status']===422);
check('プロフィール訂正理由は必須',profilePost($pOffice,$pPath,array_replace($pInput,['reason'=>'']))['status']===422);
check('プロフィール余分な権限入力を拒否',profilePost($pOffice,$pPath,$pInput+['role_id'=>1])['status']===422);
$pUsers=$db->query('SELECT * FROM users WHERE id=60')->fetch(PDO::FETCH_ASSOC);$pMoney=$db->query('SELECT COUNT(*) FROM portal_payments')->fetchColumn();
$pResult=profilePost($pOffice,$pPath,$pInput);$pAfter=json_decode($pResult['body'],true);
check('事務局が氏名と閏日生年月日を訂正',$pResult['status']===200 && $pAfter['profile']['last_name']==='訂正<姓>' && $pAfter['profile']['birthday']==='1988-02-29');
check('プロフィール版と暗号履歴が一件更新',(int)$pAfter['profile']['row_version']===(int)$pBefore['row_version']+1 && count($pAfter['changes'])===1);
check('訂正履歴は事務局が前後と理由を確認',$pAfter['changes'][0]['before']['birthday']===$pBefore['birthday'] && $pAfter['changes'][0]['reason']===$pInput['reason']);
$raw=$db->query('SELECT snapshot_cipher FROM portal_profile_changes WHERE user_id=60')->fetchColumn();check('プロフィール履歴は平文ではない',!str_contains($raw,'訂正<姓>') && !str_contains($raw,'1988-02-29'));
$html=$pOffice->request('/manage/profile-correct.php?user=60')['body'];check('訂正画面・履歴の氏名をエスケープ',str_contains($html,'訂正&lt;姓&gt;') && !str_contains($html,'訂正<姓>'));
check('プロフィール訂正は共通ID・会計を変更しない',$db->query('SELECT * FROM users WHERE id=60')->fetch(PDO::FETCH_ASSOC)===$pUsers && $db->query('SELECT COUNT(*) FROM portal_payments')->fetchColumn()===$pMoney);
check('古い版による上書きを拒否',profilePost($pOffice,$pPath,$pInput)['status']===409);
$pInput['row_version']=$pAfter['profile']['row_version'];$pNoop=json_decode(profilePost($pOffice,$pPath,$pInput)['body'],true);check('同じ訂正内容は版と履歴を増やさない',$pNoop===$pAfter);
$db->exec('RENAME TABLE portal_audit_events TO profile_unavailable_audit');$fail=profilePost($pOffice,$pPath,array_replace($pInput,['first_name'=>'失敗対象']));$db->exec('RENAME TABLE profile_unavailable_audit TO portal_audit_events');check('プロフィール監査失敗は更新履歴をロールバック',$fail['status']===503 && json_decode($pOffice->request($pPath)['body'],true)===$pAfter);
$db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=7 AND role_id=1");check('プロフィール権限取消を即反映',$pOffice->request($pPath)['status']===403);$db->exec('UPDATE portal_user_role_grants SET revoked_at=NULL WHERE user_id=7 AND role_id=1');
$blocked=false;try{(new \Jmrc\Profile\ProfileService($db,[]))->read(7,60);}catch(\Jmrc\Auth\HttpFailure $e){$blocked=$e->status===503;}check('プロフィール訂正は既定無効',$blocked);
$audit=$db->query("SELECT changes_redacted FROM portal_audit_events WHERE action='PROFILE_CORRECTED'")->fetchColumn();check('プロフィール監査に氏名や生年月日を含めない',$audit==='{}');
// 生年月日未登録の人に、氏名訂正のための架空の日付を要求しない。
$db->exec('UPDATE portal_profiles SET birthday=NULL WHERE user_id=60');
$missingBirth=json_decode($pOffice->request($pPath)['body'],true)['profile'];
$keepUnknown=profilePost($pOffice,$pPath,array_replace($pInput,['row_version'=>$missingBirth['row_version'],'birthday'=>'','first_name'=>'未登録日付を保持']));
check('生年月日未登録のまま氏名を訂正できる',$keepUnknown['status']===200 && json_decode($keepUnknown['body'],true)['profile']['birthday']===null);
$runtime=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','auth_runtime','lab_runtime_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$denied=false;
try{$runtime->exec("UPDATE portal_profile_changes SET snapshot_cipher='tamper' WHERE user_id=60");}catch(PDOException $e){$denied=(int)($e->errorInfo[1]??0)===1142;}check('プロフィール履歴はruntimeから上書き不可',$denied);
// 過去年度の架空登録と再申請を用意し、候補抽出が一切削除しないことを確認する。
$last=(int)gmdate('Y')-2;
$db->exec("INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,staff_open_at,source_mode,policy_version) VALUES($last,'$last-01-01','$last-12-31','$last-01-01','$last-02-01','$last-01-01','portal','retention-lab')");
foreach([60,61] as $uid){$db->exec("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('general',$uid,$last,'approved','retention-$uid')");$a=(int)$db->lastInsertId();$ident=$db->query("SELECT id FROM portal_identifiers WHERE user_id=$uid LIMIT 1")->fetchColumn();$db->exec("INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind) VALUES($uid,$last,'general',$a,$ident,'jaf_competition')");}
// 60の新年度申請だけを不承認扱いにし、61の再申請中は保護する。後で戻す。
$db->exec("UPDATE portal_applications a JOIN portal_memberships m ON m.application_id=a.id SET a.status='rejected' WHERE m.user_id=60 AND m.year>$last");
$profileCount=$db->query('SELECT COUNT(*) FROM portal_profiles')->fetchColumn();$ret=json_decode($pOffice->request('/manage/profile-correct.php?format=json&last_year='.$last)['body'],true);
check('未更新者のみ削除候補を抽出',$ret['due'] && in_array(60,array_column($ret['candidates'],'user_id')) && !in_array(61,array_column($ret['candidates'],'user_id')));
check('削除候補確認は個人情報を削除しない',!$ret['execution_enabled'] && $db->query('SELECT COUNT(*) FROM portal_profiles')->fetchColumn()===$profileCount);
$notDue=json_decode($pOffice->request('/manage/profile-correct.php?format=json&last_year='.gmdate('Y'))['body'],true);check('翌年4月30日前は候補なし',!$notDue['due'] && $notDue['candidates']===[]);
$db->exec("UPDATE portal_applications a JOIN portal_memberships m ON m.application_id=a.id SET a.status='submitted' WHERE m.user_id=60 AND m.year>$last");
// 保険の人数確認は有効な資格だけを数える。将来年度/未入金の多数の意思では満たさない。
$ready=json_decode($pOffice->request('/manage/insurance.php?format=json&club_year_id=101')['body'],true)['readiness'];check('未入金・将来年度の意思は初回4名に数えない',$ready['eligible_count']===0 && !$ready['initial_minimum_met'] && !$ready['can_submit']);
// 既存会計経路で4名を申請・承認・入金し、3名/4名の境界を確認する。
foreach([63,64,65,66] as $uid){
    $b=new Browser();$b->login($uid);$ident=$db->query("SELECT id FROM portal_identifiers WHERE user_id=$uid LIMIT 1")->fetchColumn();
    $sub=submitMember($b,['member_type'=>'general','year'=>$nowYear,'club_year_id'=>900,'identifier_id'=>$ident]);$midNew=(int)json_decode($sub['body'],true)['id'];
    $inv=json_decode(billPost($b,'issue',$midNew)['body'],true);billPost($pOffice,'approve',$midNew);billPost($pOffice,'confirm',(int)$inv['id'],['amount'=>$inv['amount_yen'],'reference'=>'INSURANCE-FOUR-'.$uid]);
    insurancePost($b,['action'=>'request','membership_id'=>$midNew,'consent'=>'1']);
    $ready=json_decode($pOffice->request('/manage/insurance.php?format=json&club_year_id=900')['body'],true)['readiness'];
    if($uid===65){check('保険初回3名は人数不足',$ready['eligible_count']===3 && !$ready['initial_minimum_met']);}
}
check('保険初回4名を満たしても保険料・期間は保留',$ready['eligible_count']===4 && $ready['initial_minimum_met'] && $ready['policy_hold'] && !$ready['can_submit']);
// 年度マスター案は別年に置き換えて実DDL型・時差・重複拒否を検証。source_mode切替を含まない。
$sql=file_get_contents('/srv/tests/registration_calendar.sql');$db->exec(str_replace(['2027','2026'],['2097','2096'],$sql));$cal=$db->query('SELECT * FROM portal_years WHERE year=2097')->fetch(PDO::FETCH_ASSOC);
check('受付日程案は本人1月1日・クラブ12月1日のJSTをUTC保存',$cal['member_self_open_at']==='2096-12-31 15:00:00.000000' && $cal['club_open_at']==='2096-11-30 15:00:00.000000');
check('クラブ2月7日終日の締切を翌日00時で表現',$cal['club_close_at']==='2097-02-07 15:00:00.000000');
check('受付日程案では資格正本を切替えない',$cal['source_mode']==='shadow');
$db->exec('DELETE FROM portal_years WHERE year=2097');
