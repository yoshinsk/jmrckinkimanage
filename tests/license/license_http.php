<?php
// Path: tests/license/license_http.php
// 機能: 本人登録更新、期限/種類とQR連携、暗号計画・CLI適用・原子性・元データ不変を検証する。
declare(strict_types=1);
require_once '/srv/auth/src/License/LicenseService.php';require_once '/srv/auth/src/License/LicenseMigration.php';
function licensePost(Browser $b,array $fields): array{return $b->request('/manage/licenses.php?format=json',$fields+['_csrf'=>identity($b)['csrf']]);}
function licenseCounts(PDO $db): array{$r=[];foreach(['portal_identifiers','portal_license_claims','portal_identifier_revisions','portal_license_migrations','portal_audit_events','portal_outbox'] as $t){$r[]=(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}return $r;}
function licenseReject(string $case,callable $fn,int $status): void{$caught=false;try{$fn();}catch(\Jmrc\Auth\HttpFailure $e){$caught=$e->status===$status;}check($case,$caught);}
function runLicenseCli(array $args): array{$pipes=[];$p=proc_open([PHP_BINARY,'/srv/auth/scripts/license_migrate.php',...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return ['exit'=>proc_close($p),'out'=>$out,'err'=>$err];}
$create=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,3,?,1,?)');
foreach([15,16,30,31,32,33] as $uid){$create->execute([$uid,"u$uid@example.invalid",password_hash('lab-password-only',PASSWORD_DEFAULT)]);$db->exec("INSERT INTO portal_account_controls(user_id) VALUES($uid)");}
$owner=new Browser();$owner->login(15);$another=new Browser();$another->login(16);$office=new Browser();$office->login(7);$anonymous=new Browser();
$fields=['kind'=>'jaf_official','number'=>'JAFNEW15','license_type'=>'審判B','expires_on'=>'2099-12-31'];
check('匿名のライセンス一覧拒否',$anonymous->request('/manage/licenses.php?format=json')['status']===401);
check('ライセンス登録CSRF必須',$owner->request('/manage/licenses.php?format=json',$fields)['status']===400);
check('運転免許をJAF区分として登録不可',licensePost($owner,array_replace($fields,['kind'=>'driving']))['status']===422);
check('不正な日付を拒否',licensePost($owner,array_replace($fields,['expires_on'=>'2027-02-30']))['status']===422);
check('空番号を新規登録不可',licensePost($owner,array_replace($fields,['number'=>'']))['status']===422);
$r=licensePost($owner,$fields);$saved=json_decode($r['body'],true);$id=(int)$saved['id'];
check('本人の審判員ライセンスを登録',$r['status']===200 && $saved['kind']==='jaf_official');
check('保存レスポンスに番号・暗号文・HMACなし',!str_contains($r['body'],'JAFNEW15') && !str_contains($r['body'],'cipher') && !str_contains($r['body'],'hmac'));
$read=json_decode($owner->request('/manage/licenses.php?format=json&id='.$id)['body'],true);
check('本人の明示した編集で番号を復号',$read['number']==='JAFNEW15');
check('他人と事務局の通常画面から番号を参照不可',$another->request('/manage/licenses.php?format=json&id='.$id)['status']===404 && $office->request('/manage/licenses.php?format=json&id='.$id)['status']===404);
$before=licenseCounts($db);check('登録再送は同じレコード',licensePost($owner,$fields)['status']===200 && licenseCounts($db)===$before);
check('別アカウントの同じライセンスを自動統合しない',licensePost($another,$fields)['status']===409 && licenseCounts($db)===$before);
check('所有者IDを改竄しても他人へ登録しない',licensePost($owner,array_replace($fields,['number'=>'SPOOF15','user_id'=>16]))['status']===200 && (int)$db->query("SELECT COUNT(*) FROM portal_identifiers WHERE user_id=16")->fetchColumn()===0);
check('他人の対象ID更新を拒否',licensePost($another,$fields+['id'=>$id,'row_version'=>1])['status']===404);
check('古い更新版は拒否',licensePost($owner,$fields+['id'=>$id,'row_version'=>999])['status']===409);
$private=new Browser();$private->login(9);$year=(int)gmdate('Y');$member=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=9 AND year=$year AND member_type='general'")->fetchColumn();
$card=cardRead($private,$member);$token=parse_url($card['qr_url'],PHP_URL_FRAGMENT);$box=new \Jmrc\Card\SecretBox(base64_encode(str_repeat('L',32)));
$old=$db->query('SELECT * FROM portal_identifiers WHERE id=102')->fetch(PDO::FETCH_ASSOC);
$past=['id'=>102,'row_version'=>$old['row_version'],'kind'=>'jaf_official','number'=>'','license_type'=>$old['license_type'],'expires_on'=>'2000-01-01'];
check('期限切れ更新を受付け資格を無効化',licensePost($private,$past)['status']===200 && !qrActive($anonymous,$token) && !cardRead($private,$member)['available']);
check('資格保存状態も期限切れへ反映',$db->query("SELECT status FROM portal_memberships WHERE id=$member")->fetchColumn()==='pending');
$current=$db->query('SELECT * FROM portal_identifiers WHERE id=102')->fetch(PDO::FETCH_ASSOC);$renew=array_replace($past,['row_version'=>$current['row_version'],'expires_on'=>'2099-12-31']);
check('同番号の期限更新で資格復旧',licensePost($private,$renew)['status']===200 && qrActive($anonymous,$token));
check('期限だけの更新は番号・暗号文・QRを維持',$db->query('SELECT value_cipher FROM portal_identifiers WHERE id=102')->fetchColumn()===$old['value_cipher'] && cardRead($private,$member)['qr_url']===$card['qr_url']);
$current=$db->query('SELECT * FROM portal_identifiers WHERE id=102')->fetch(PDO::FETCH_ASSOC);$before=licenseCounts($db);
check('変更なし更新では履歴を増やさない',licensePost($private,array_replace($renew,['row_version'=>$current['row_version']]))['status']===200 && licenseCounts($db)===$before);
$change=array_replace($renew,['row_version'=>$current['row_version'],'license_type'=>'国内A']);
check('種類の変更で旧QRを失効',licensePost($private,$change)['status']===200 && !qrActive($anonymous,$token) && !cardRead($private,$member)['can_issue']);
$snapshot=$db->query('SELECT * FROM portal_identifier_revisions WHERE identifier_id=102 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('変更履歴は暗号文で保存',str_starts_with($snapshot['snapshot_cipher'],'gcm1:') && !str_contains($snapshot['snapshot_cipher'],'JAF000009'));
$before=licenseCounts($db);$db->exec('RENAME TABLE portal_outbox TO license_unavailable_outbox');
check('登録通知予約障害は503',licensePost($owner,array_replace($fields,['number'=>'ROLLBACK15']))['status']===503);$db->exec('RENAME TABLE license_unavailable_outbox TO portal_outbox');
check('識別・重複確保・履歴・監査は一括ロールバック',licenseCounts($db)===$before);
// 旧側にはJAFと運転免許を別々に用意し、JAF以外を移行しないことを確認する。
$p=$db->prepare("INSERT INTO entrants(user_id,last_name,first_name,last_kana,first_kana,postcode,address,phone,contact_mobile,birthday,license_no,license_expiry,jaf_license_no,jaf_license_type) VALUES(?,'架空','移行','カクウ','イコウ','0000000','架空','000','000','2000-01-01','DRIVE-NOT-JAF','2090-01-01',?,'国内B')");
foreach([30,31,32,33] as $uid){$p->execute([$uid,'JAFLEGACY'.$uid]);}
$sourceBefore=hash('sha256',json_encode($db->query('SELECT * FROM entrants ORDER BY user_id')->fetchAll()));
$migration=new \Jmrc\License\LicenseMigration($db,['data_key'=>base64_encode(str_repeat('L',32))]);$before=licenseCounts($db);$plan=$migration->plan(7,[30,31]);
check('移行計画はDBを書き換えない',licenseCounts($db)===$before);
check('移行計画に平文番号を出力しない',!str_contains(json_encode($plan),'JAFLEGACY') && !str_contains(json_encode($plan),'DRIVE-NOT-JAF'));
$review=$plan['review'];$manifest=$plan['manifest'];
licenseReject('移行の未確認候補を拒否',fn()=>$migration->execute(7,$manifest,$review,'jmrckinki_auth_lab'),422);
licenseReject('対象DB相違を拒否',fn()=>$migration->execute(7,$manifest,$review,'other_database'),409);
licenseReject('旧管理者だけで移行を許可しない',fn()=>$migration->plan(1,[30]),403);
foreach($review['items'] as &$item){$item['decision']='approve';$item['kind']='jaf_competition';$item['expiry_reviewed']=true;}unset($item);
$bad=$review;$bad['items'][0]['kind']='driving';licenseReject('移行時も運転免許をJAFへ流用不可',fn()=>$migration->execute(7,$manifest,$bad,'jmrckinki_auth_lab'),422);
$db->exec("UPDATE entrants SET jaf_license_no='STALE' WHERE user_id=30");licenseReject('計画後の元データ変更を拒否',fn()=>$migration->execute(7,$manifest,$review,'jmrckinki_auth_lab'),409);$db->exec("UPDATE entrants SET jaf_license_no='JAFLEGACY30' WHERE user_id=30");
$checked=$migration->execute(7,$manifest,$review,'jmrckinki_auth_lab');check('確認済みcheckも書込ゼロ',$checked['approved']===2 && licenseCounts($db)===$before);
$base='/tmp/jmrc-license-'.bin2hex(random_bytes(8));$common=['--actor=7','--expected-database=jmrckinki_auth_lab'];
$cli=runLicenseCli(['--mode=plan',...$common,'--users=30,31','--out='.$base.'.json','--review-out='.$base.'-review.json']);
check('CLIで暗号計画と確認雛形を作成',$cli['exit']===0 && json_decode($cli['out'],true)['candidates']===2);
$cliManifest=json_decode(file_get_contents($base.'.json'),true);$cliReview=json_decode(file_get_contents($base.'-review.json'),true);
check('計画ファイルは非公開権限・番号なし',(fileperms($base.'.json')&0777)===0600 && !str_contains(file_get_contents($base.'.json'),'JAFLEGACY'));
foreach($cliReview['items'] as &$item){$item['decision']='approve';$item['kind']='jaf_competition';$item['expiry_reviewed']=true;}unset($item);file_put_contents($base.'-review.json',json_encode($cliReview));
$files=['--manifest='.$base.'.json','--review='.$base.'-review.json'];$cli=runLicenseCli(['--mode=apply',...$common,...$files]);check('CLI applyには明示フラグが必要',$cli['exit']===1 && json_decode($cli['err'],true)['code']===422 && licenseCounts($db)===$before);
$cli=runLicenseCli(['--mode=apply','--apply',...$common,...$files]);check('CLI確認後に2件を原子的に移行',$cli['exit']===0 && json_decode($cli['out'],true)['applied']===2);
$before=licenseCounts($db);$cli=runLicenseCli(['--mode=apply','--apply',...$common,...$files]);check('同じ移行計画の再実行は変更なし',$cli['exit']===0 && json_decode($cli['out'],true)['already_applied']===2 && licenseCounts($db)===$before);
$altered=$cliReview;$altered['items'][0]['kind']='jaf_official';licenseReject('適用済み計画のレビュー差し替えを拒否',fn()=>$migration->execute(7,$cliManifest,$altered,'jmrckinki_auth_lab',true),409);
$imported=$db->query("SELECT * FROM portal_identifiers WHERE user_id=30 AND kind='jaf_competition'")->fetch(PDO::FETCH_ASSOC);
check('移行後のライセンスを正式AADで復号',$box->open($imported['value_cipher'],'jmrc:identifier:v1:'.$imported['id'])==='JAFLEGACY30');
check('運転免許の期限をJAF期限へコピーしない',$imported['expires_on']===null);
check('移行処理で旧entrantsを変更しない',hash('sha256',json_encode($db->query('SELECT * FROM entrants ORDER BY user_id')->fetchAll()))===$sourceBefore);
$plan2=$migration->plan(7,[32,33]);$review2=$plan2['review'];foreach($review2['items'] as &$item){$item['decision']='approve';$item['kind']='jaf_competition';$item['expiry_reviewed']=true;}unset($item);
$review2['items'][1]['decision']='pending';$before=licenseCounts($db);licenseReject('移行途中の不備で全件をロールバック',fn()=>$migration->execute(7,$plan2['manifest'],$review2,'jmrckinki_auth_lab',true),422);check('移行途中失敗で識別情報を残さない',licenseCounts($db)===$before);
$db->exec("INSERT INTO portal_identifiers(user_id,kind,value_cipher,lookup_hmac,key_version) VALUES(32,'jaf_competition','old-opaque',UNHEX(SHA2('opaque32',256)),1)");$target=(int)$db->lastInsertId();
$review2['items'][0]['target_identifier_id']=$target;$review2['items'][0]['target_version']=1;$review2['items'][1]['decision']='skip';$done=$migration->execute(7,$plan2['manifest'],$review2,'jmrckinki_auth_lab',true);
check('未知形式の既存行も明示対象・版で移行',$done['applied']===1 && str_starts_with($db->query("SELECT value_cipher FROM portal_identifiers WHERE id=$target")->fetchColumn(),'gcm1:'));
check('明示skipを勝手に移行しない',(int)$db->query('SELECT COUNT(*) FROM portal_identifiers WHERE user_id=33')->fetchColumn()===0);
$q=$db->prepare("INSERT INTO portal_identifiers(user_id,kind,value_cipher,lookup_hmac,key_version,license_type) VALUES(33,'jaf_competition','pending',?,1,'国内B')");$q->execute([$box->digest('JAFLEGACY33','identifier:jaf_competition')]);$readyId=(int)$db->lastInsertId();
$q=$db->prepare('UPDATE portal_identifiers SET value_cipher=? WHERE id=?');$q->execute([$box->seal('JAFLEGACY33','jmrc:identifier:v1:'.$readyId),$readyId]);
$review2['items'][1]['decision']='approve';$review2['items'][1]['target_identifier_id']=$readyId;$review2['items'][1]['target_version']=1;
$migration->execute(7,$plan2['manifest'],$review2,'jmrckinki_auth_lab',true);
check('暗号化済み既存行でも重複確保を移行',(int)$db->query("SELECT COUNT(*) FROM portal_license_claims WHERE identifier_id=$readyId")->fetchColumn()===1);
// 独立接続の重複登録はglobal claimで、同一版の更新はrow_versionで拒否。
function licenseRace(array $tasks): array{$when=microtime(true)+0.5;$workers=[];$rows=[];foreach($tasks as $args){$pipes=[];$p=proc_open([PHP_BINARY,'/srv/tests/concurrent_license.php',(string)$when,...array_map('strval',$args)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check('ライセンス同時ワーカー正常終了 '.count($rows),proc_close($p)===0 && $error==='');$rows[]=json_decode($out,true);}return $rows;}
$race=licenseRace([[15,'create'],[16,'create']]);$statuses=array_column($race,'status');sort($statuses);check('別人の同じ番号を同時登録しても一件のみ',$statuses===[200,409]);
$version=(int)$db->query("SELECT row_version FROM portal_identifiers WHERE id=$id")->fetchColumn();$race=licenseRace([[15,$id,$version,'2098-01-01'],[15,$id,$version,'2097-01-01']]);$statuses=array_column($race,'status');sort($statuses);check('同じ版の競合更新は一件のみ成功',$statuses===[200,409]);
$audit=json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action LIKE 'LICENSE_%'")->fetchAll());check('監査へ番号・元データを漏らさない',!str_contains($audit,'JAFNEW15') && !str_contains($audit,'JAFLEGACY'));
