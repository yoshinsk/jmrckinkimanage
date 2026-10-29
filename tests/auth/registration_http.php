<?php
// Path: tests/auth/registration_http.php
// 機能: 旧登録・確認・再設定の実処理と旧ログイン済みセッションの限定引継ぎを隔離HTTPSで検証する。
declare(strict_types=1);
$reg=new Browser();$email='release-new@example.invalid';$p1='release-lab-password-1';$p2='release-lab-password-2';
$form=['email'=>$email,'password'=>$p1,'password_confirm'=>$p1,'role'=>'ENTRANT','personal_membership'=>'入会しない(他地区含)','last_name'=>'架空','first_name'=>'登録検証','last_kana'=>'カクウ','first_kana'=>'トウロク','gender'=>'男','birthday'=>'1990-01-01','license_no'=>'000000000123','license_expiry'=>'2099-12-31','postcode'=>'0000000','address'=>'架空住所','phone'=>'00000000000'];
$r=$reg->request('/entry/register_handler.php',$form+['_csrf'=>$reg->token()]);
$s=$db->prepare('SELECT * FROM users WHERE email=?');$s->execute([$email]);$u=$s->fetch(PDO::FETCH_ASSOC);
if(!$u){throw new RuntimeException('登録検証: '.substr(strip_tags($r['body']),0,200).': '.(string)$db->query("SELECT message FROM audit_logs WHERE action='REGISTER_ERROR' ORDER BY id DESC LIMIT 1")->fetchColumn());}
check('旧新規登録が認証状態も同時作成',$r['status']===200 && $u && (int)$db->query('SELECT COUNT(*) FROM portal_account_controls WHERE user_id='.(int)$u['id'])->fetchColumn()===1);
$uid=(int)$u['id'];$r=$reg->request('/entry/login.php',['email'=>$email,'password'=>$p1,'_csrf'=>$reg->token()]);check('メール確認前の新規利用者は認証しない',$r['status']===401);
$form['password']=$p2;$form['password_confirm']=$p2;$reg->request('/entry/register_handler.php',$form+['_csrf'=>$reg->token()]);$s->execute([$email]);$u2=$s->fetch(PDO::FETCH_ASSOC);
check('未確認の再登録はID維持・認証版更新',(int)$u2['id']===$uid && password_verify($p2,$u2['password_hash']) && (int)$db->query("SELECT auth_version FROM portal_account_controls WHERE user_id=$uid")->fetchColumn()===2);
$token=$db->query("SELECT token FROM email_verifications WHERE user_id=$uid AND consumed=0 ORDER BY id DESC LIMIT 1")->fetchColumn();$r=$reg->request('/entry/verify.php?token='.$token);
check('旧メール確認処理の実コードが完走',$r['status']===200 && (int)$db->query("SELECT email_verified FROM users WHERE id=$uid")->fetchColumn()===1);
$r=$reg->request('/entry/login.php',['email'=>$email,'password'=>$p2,'_csrf'=>$reg->token(),'app'=>'manage','return_key'=>'dashboard']);check('旧登録から確認後に共通サインイン可能',$r['status']===303 && identity($reg)['uid']===$uid && identity($reg,'entry')['uid']===$uid);
$hash=$db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn();$reg->request('/entry/register_handler.php',$form+['_csrf'=>identity($reg)['csrf']]);check('確認済みユーザーの再登録で認証情報を変更しない',$db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn()===$hash);
$reset=bin2hex(random_bytes(32));$s=$db->prepare("INSERT INTO email_verifications(user_id,purpose,token,email,expires_at) VALUES(?,'reset_password',?,?,UTC_TIMESTAMP()+INTERVAL 1 DAY)");$s->execute([$uid,$reset,$email]);
$r=$reg->request('/entry/reset_handler.php',['token'=>$reset,'password'=>$p1,'password_confirm'=>$p1,'_csrf'=>identity($reg)['csrf']]);check('旧パスワード再設定の実処理が成功',$r['status']===200 && password_verify($p1,$db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn()));
check('パスワード再設定後は旧セッション失効',$reg->request('/manage/identity.php')['status']===401);
check('既存IDのまま変更後パスワードで再ログイン',$reg->request('/entry/login.php',['email'=>$email,'password'=>$p1,'_csrf'=>$reg->token()])['status']===303);
// 旧ログイン済みsessionだけをCLIで作成。公開入口でuid/ridを受け取る仕組みではない。
$sid='labmigrate'.bin2hex(random_bytes(16));file_put_contents('/srv/sessions/sess_'.$sid,'uid|i:3;rid|i:3;verified|b:1;_csrf|s:64:"'.str_repeat('b',64).'";');$old=new Browser();$old->cookies=['PHPSESSID'=>$sid];$id=identity($old);
check('限定期間の旧ログイン済みセッションを維持',$id['uid']===3 && $id['csrf']===str_repeat('b',64) && identity($old,'entry')['uid']===3);
$db->exec("UPDATE portal_account_controls SET auth_version=auth_version+1 WHERE user_id=3");check('引継ぎ後も認証版失効が有効',$old->request('/manage/identity.php')['status']===401);
// 再登録前に停止・既存ポータル情報を検査し、旧プロフィール削除を実行させない。
$db->exec("UPDATE users SET email_verified=0 WHERE id=$uid; UPDATE portal_account_controls SET status='blocked' WHERE user_id=$uid");$hash=$db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn();$reg->request('/entry/register_handler.php',$form+['_csrf'=>$reg->token()]);check('停止アカウントを再登録で復活させない',$db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn()===$hash && $db->query("SELECT status FROM portal_account_controls WHERE user_id=$uid")->fetchColumn()==='blocked');
$db->exec("UPDATE portal_account_controls SET status='active' WHERE user_id=$uid; INSERT INTO portal_profiles(user_id,last_name) VALUES($uid,'保持対象')");$reg->request('/entry/register_handler.php',$form+['_csrf'=>$reg->token()]);check('ポータル情報がある未確認IDは再登録破壊を防止',$db->query("SELECT last_name FROM portal_profiles WHERE user_id=$uid")->fetchColumn()==='保持対象' && $db->query("SELECT password_hash FROM users WHERE id=$uid")->fetchColumn()===$hash);
