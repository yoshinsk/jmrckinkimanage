<?php
// Path: tests/auth/followup_answers.php
// 機能: P18/P19を架空会員で検証し、変更元の承認不要・不正承認拒否・番号QR更新・請求不変を確認する。
declare(strict_types=1);
require_once '/srv/auth/src/Card/SecretBox.php';require_once '/srv/auth/src/Card/CardService.php';
require_once '/srv/auth/src/Membership/MembershipService.php';require_once '/srv/auth/src/Membership/LicenseDeclaration.php';
require_once '/srv/auth/src/Operations/AnswerRules.php';require_once '/srv/auth/src/Series/SeriesRules.php';
$followBad=static function(string $name,callable $fn,int $status=422):void{try{$fn();check($name,false);}catch(\Jmrc\Auth\HttpFailure $e){check($name,$e->status===$status);}catch(InvalidArgumentException){check($name,$status===422);}};
$csv="event_id,class_code,entrant_id,position,points,starters\n1,PN1,1,1,20,3\n1,PN1,2,1,20,3\n";
$followBad('P18同大会同クラスの同順位を入力訂正へ',fn()=>\Jmrc\Operations\AnswerRules::results($csv));
check('P18別クラスの同じ順位は許可',count(\Jmrc\Operations\AnswerRules::results(str_replace('PN1,2','PN2,2',$csv))['rows'])===2);
$r=[['event'=>'1','date'=>'2027-04-01','points'=>20]];
$followBad('P18異なる選手で比較不能なら結果確認へ',fn()=>\Jmrc\Series\SeriesRules::compareDistinct($r,$r,1));
check('P18通常の比較は継続',\Jmrc\Series\SeriesRules::compareDistinct($r,[['event'=>'1','date'=>'2027-04-01','points'=>10]],1)<0);
$fy=(int)gmdate('Y');$cfg=require '/srv/private/auth.config.php';
$runtime=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','auth_runtime','lab_runtime_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$runtime->exec("SET time_zone='+00:00'");
$svc=new \Jmrc\Membership\MembershipService($runtime,$cfg);$cards=new \Jmrc\Card\CardService($runtime,$cfg);
$db->exec("INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(99070,3,'change-member@example.invalid',1,'fixture'),(99071,3,'change-rep@example.invalid',1,'fixture');
 INSERT INTO portal_account_controls(user_id) VALUES(99070),(99071)");
foreach([99070,99071] as $cid){
 $db->exec("INSERT INTO portal_clubs(id,fixed_code,name_full,name_short) VALUES($cid,'$cid','変更確認$cid','変更$cid');
 INSERT INTO portal_applications(id,kind,applicant_user_id,year,status,request_key) VALUES($cid,'club',2,$fy,'approved','followup-club-$cid');
 INSERT INTO portal_club_years(id,club_id,year,category_digit,display_code,application_id,status,rep_name,contact_name,postcode,address,phone,email)
 VALUES($cid,$cid,$fy,'1','1$cid',$cid,'approved','架空','架空','0000000','架空','000','fixture@example.invalid')");
 seedClubPaid($db,$cid);
}
$db->exec("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,status,valid_from,granted_by,approved_by)
 VALUES(2,2,'club',99070,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1),(99071,2,'club',99071,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1)");
$box=new \Jmrc\Card\SecretBox($cfg['data_key']);
$db->prepare("INSERT INTO portal_identifiers(id,user_id,kind,value_cipher,lookup_hmac,key_version) VALUES(99070,99070,'jaf_competition',?,?,1)")->execute([$box->seal('FOLLOWUP99070','jmrc:identifier:v1:99070'),$box->digest('FOLLOWUP99070','jaf_competition')]);
$m=$svc->submitGeneral(99070,$fy,99070,99070,bin2hex(random_bytes(24)),['competition_type'=>'国内B','official_type'=>'なし']);$mid=(int)$m['id'];
$svc->approveGeneral(2,$mid);
// 既存HTTPの請求・入金関数を使用。新会員本人のログイン用パスワードは架空値だけ。
$db->prepare('UPDATE users SET password_hash=? WHERE id=99070')->execute([password_hash('lab-password-only',PASSWORD_DEFAULT)]);
$db->exec("UPDATE users SET email='u99070@example.invalid' WHERE id=99070");
$cb=new Browser();$cb->login(99070);$of=new Browser();$of->login(7);
$invoice=json_decode(billPost($cb,'issue',$mid)['body'],true);check('P19変更前の請求作成',isset($invoice['id']));
check('P19変更前の入金',billPost($of,'confirm',(int)$invoice['id'],['amount'=>(int)$invoice['amount_yen'],'reference'=>'FOLLOWUP-PAID'])['status']===200);
$initialState=(new \Jmrc\Billing\Eligibility($runtime))->evaluate($mid);
check('P19変更前は承認・満額入金済みの有効資格',$initialState['active']);
$old=$cards->issue(99070,$mid);$oldToken=substr($old['qr_url'],strrpos($old['qr_url'],'#')+1);
$billHash=$db->query('CHECKSUM TABLE portal_invoices,portal_invoice_lines,portal_payments,portal_allocations,portal_membership_charges')->fetchAll(PDO::FETCH_ASSOC);
$followBad('P19他人の所属変更申請拒否',fn()=>$svc->requestClubChange(99071,$mid,99071),404);
$change=$svc->requestClubChange(99070,$mid,99071);
check('P19申請再送は同じ1件',$svc->requestClubChange(99070,$mid,99071)['id']===$change['id']);
check('P19承認前は旧QR・番号維持',$cards->verify($oldToken)&&$cards->read(99070,$mid)['member_number']===$old['member_number']);
$followBad('P19変更元代表だけでは承認不可',fn()=>$svc->approveClubChange(2,$change['id'],1),403);
$followBad('P19本人は承認不可',fn()=>$svc->approveClubChange(99070,$change['id'],1),403);
$followBad('P19旧管理者だけでは承認不可',fn()=>$svc->approveClubChange(1,$change['id'],1),403);
$followBad('P19古い版を拒否',fn()=>$svc->approveClubChange(99071,$change['id'],0),409);
check('P19変更先代表のみで承認',$svc->approveClubChange(99071,$change['id'],1)['replayed']===false);
check('P19承認再送は重複しない',$svc->approveClubChange(99071,$change['id'],1)['replayed']);
check('P19承認直後に旧QR失効',!$cards->verify($oldToken));
$new=$cards->issue(99070,$mid);check('P19会員番号は変更先6桁コード',$new['member_number']==='FOLLOWUP99070-G-199071');
check('P19新QRは有効',$cards->verify(substr($new['qr_url'],strrpos($new['qr_url'],'#')+1)));
check('P19請求・入金・年度会費は変更しない',$billHash===$db->query('CHECKSUM TABLE portal_invoices,portal_invoice_lines,portal_payments,portal_allocations,portal_membership_charges')->fetchAll(PDO::FETCH_ASSOC));
$back=$svc->requestClubChange(99070,$mid,99070);
$db->exec('RENAME TABLE portal_outbox TO followup_failed_outbox');
try { $svc->approveClubChange(7,$back['id'],1);check('P19監査通知障害は全体ロールバック',false); }
catch(PDOException){check('P19監査通知障害は全体ロールバック',$cards->read(99070,$mid)['member_number']==='FOLLOWUP99070-G-199071');}
finally{$db->exec('RENAME TABLE followup_failed_outbox TO portal_outbox');}
check('P19事務局代行も可能',!$svc->approveClubChange(7,$back['id'],1)['replayed']);
check('P19元クラブへ戻る場合は同じ番号を再利用し履歴保持',(int)$db->query("SELECT COUNT(*) FROM portal_member_numbers WHERE membership_id=$mid")->fetchColumn()===2 && (int)$db->query("SELECT COUNT(*) FROM portal_affiliations WHERE membership_id=$mid AND status='approved'")->fetchColumn()===3);
