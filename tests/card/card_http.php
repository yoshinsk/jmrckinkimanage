<?php
// Path: tests/card/card_http.php
// 機能: General会員証の本人限定発行、匿名最小照会、入金/停止/期限/採番元の即時反映をHTTPS検証。
declare(strict_types=1);
require_once '/srv/auth/src/Card/SecretBox.php';require_once '/srv/auth/src/Card/CardService.php';
function cardPost(Browser $browser,int $id,string $action='issue'): array
{
    return $browser->request('/manage/card.php?format=json',['membership_id'=>$id,'action'=>$action,'_csrf'=>identity($browser)['csrf']]);
}
function cardRead(Browser $browser,int $id): array
{
    $r=$browser->request('/manage/card.php?format=json&id='.$id);if($r['status']!==200){throw new RuntimeException('card read HTTP '.$r['status']);}return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
}
function verifyQr(Browser $browser,mixed $token): array
{
    return $browser->request('/manage/card-verify.php',['token'=>$token]);
}
function qrActive(Browser $browser,string $token): bool
{
    $r=verifyQr($browser,$token);if($r['status']!==200){throw new RuntimeException('QR read HTTP '.$r['status']);}return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR)['active'];
}
function cardCounts(PDO $db): array
{
    $r=[];foreach(['portal_member_numbers','portal_card_tokens','portal_audit_events','portal_outbox'] as $table){$r[]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}return $r;
}
$nowYear=(int)gmdate('Y');$owner=new Browser();$owner->login(9);$other=new Browser();$other->login(3);$office=new Browser();$office->login(7);$anonymous=new Browser();
$member=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=9 AND year=$nowYear AND member_type='general'")->fetchColumn();
$future=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=3 AND year=".($nowYear+1)." AND member_type='general'")->fetchColumn();
$temporary=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=10 AND year=$nowYear AND member_type='temporary'")->fetchColumn();
$tempOwner=new Browser();$tempOwner->login(10);
check('匿名で本人会員証は参照不可',$anonymous->request('/manage/card.php?format=json&id='.$member)['status']===401);
check('他人の会員証を参照不可',$other->request('/manage/card.php?format=json&id='.$member)['status']===404);
check('事務局でも本人として発行できない',cardPost($office,$member)['status']===404);
check('会員証発行CSRF必須',$owner->request('/manage/card.php?format=json',['membership_id'=>$member,'action'=>'issue'])['status']===400);
check('Temporaryは会員証なし',cardPost($tempOwner,$temporary)['status']===422);
check('将来年度は会員証を発行しない',cardPost($other,$future)['status']===409);
$before=cardCounts($db);$pre=cardRead($owner,$member);
check('GETは番号やQRを自動発行しない',!$pre['available'] && $pre['can_issue'] && cardCounts($db)===$before);
check('未知の識別番号保管形式を平文扱いしない',cardPost($owner,$member)['status']===503 && cardCounts($db)===$before);
$box=new \Jmrc\Card\SecretBox(base64_encode(str_repeat('L',32)));$license='JAF000009';$cipher=$box->seal($license,'jmrc:identifier:v1:102');
$s=$db->prepare("UPDATE portal_identifiers SET value_cipher=?,license_type='国内B',key_version=1 WHERE id=102");$s->execute([$cipher]);
$missing=false;try{(new \Jmrc\Card\CardService($db,[]))->issue(9,$member);}catch(\Jmrc\Auth\HttpFailure $e){$missing=$e->status===503;}
check('暗号鍵なしで発行しない',$missing && cardCounts($db)===$before);
$badUrl=false;try{(new \Jmrc\Card\CardService($db,['data_key'=>base64_encode(str_repeat('L',32)),'card_public_url'=>'http://example.invalid/manage/card-check.php']))->issue(9,$member);}catch(\Jmrc\Auth\HttpFailure $e){$badUrl=$e->status===503;}
check('危険な照会URLでは番号・QR発行をロールバック',$badUrl && cardCounts($db)===$before);
$db->exec("INSERT INTO portal_profiles(user_id,last_name,first_name) VALUES(9,'検証<姓>','架空&名') ON DUPLICATE KEY UPDATE last_name=VALUES(last_name),first_name=VALUES(first_name)");
$r=cardPost($owner,$member);$card=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);$token=parse_url($card['qr_url'],PHP_URL_FRAGMENT);$cardQrFixtures=[$card['qr_url']];
check('本人の有効General会員証を発行',$r['status']===200 && $card['available']);
check('本人JSONに正本氏名を返す',$card['name']==='検証<姓> 架空&名');
$html=$owner->request('/manage/card.php?id='.$member)['body'];
check('本人HTML氏名をエスケープ',str_contains($html,'検証&lt;姓&gt; 架空&amp;名') && !str_contains($html,'検証<姓>'));
$db->exec("UPDATE portal_profiles SET first_name='更新名' WHERE user_id=9");
$renamed=cardRead($owner,$member);
check('氏名更新で番号とQRを維持',$renamed['name']==='検証<姓> 更新名' && $renamed['qr_url']===$card['qr_url'] && $renamed['member_number']===$card['member_number']);
check('確定した番号体系で表示',$card['member_number']===$license.'-G-100003');
check('確定した本人表示項目のみ',array_keys($card)===['available','can_issue','year','name','member_number','club_abbreviation','license_type','qr_url'] && $card['year']===$nowYear && $card['license_type']==='国内B');
check('QRは不透明トークンの断片URL',preg_match('~^https://web/manage/card-check\.php#[a-f0-9]{64}$~D',$card['qr_url'])===1 && !str_contains($card['qr_url'],$license));
$stored=$db->query("SELECT * FROM portal_card_tokens WHERE membership_id=$member AND revoked_at IS NULL")->fetch(PDO::FETCH_ASSOC);
check('トークン平文をDBへ保存しない',!str_contains($stored['token_cipher'],$token) && hash_equals($stored['token_hash'],hash('sha256',$token,true)));
$n=$db->query("SELECT * FROM portal_member_numbers WHERE membership_id=$member AND valid_until IS NULL")->fetch(PDO::FETCH_ASSOC);
check('会員番号も暗号化保管',!str_contains($n['number_cipher'],$license) && str_starts_with($n['number_cipher'],'gcm1:'));
$before=cardCounts($db);$again=json_decode(cardPost($owner,$member)['body'],true);
check('同一発行の再送は番号・QRを増やさない',$again['qr_url']===$card['qr_url'] && cardCounts($db)===$before);
check('本人HTMLにも会員証を表示',str_contains($owner->request('/manage/card.php?id='.$member)['body'],$license.'-G-100003'));
$public=verifyQr($anonymous,$token);$headers=implode("\n",$anonymous->lastHeaders);
check('匿名照会は有効の真偽だけ',$public['status']===200 && json_decode($public['body'],true)===['active'=>true]);
check('公開レスポンスはキャッシュ禁止・参照元送信禁止',stripos($headers,'Cache-Control: no-store')!==false && stripos($headers,'Referrer-Policy: no-referrer')!==false);
check('匿名照会でCookieを発行しない',stripos($headers,'Set-Cookie:')===false);
check('公開照会に個人情報なし',!str_contains($public['body'],$license) && !str_contains($public['body'],'club') && !str_contains($public['body'],(string)$nowYear));
check('公開照会GETは拒否',$anonymous->request('/manage/card-verify.php?token='.$token)['status']===405);
check('未知・改竄トークンは無効',!qrActive($anonymous,str_repeat('a',64)) && !qrActive($anonymous,substr($token,0,63).($token[63]==='a'?'b':'a')));
check('配列トークンも無効',json_decode(verifyQr($anonymous,['bad'])['body'],true)===['active'=>false]);
$shell=$anonymous->request('/manage/card-check.php');$shellHeaders=implode("\n",$anonymous->lastHeaders);
check('匿名QRページは個人情報・資格結果を埋め込まない',$shell['status']===200 && !str_contains($shell['body'],$license) && !str_contains($shell['body'],$token) && stripos($shellHeaders,'Set-Cookie:')===false);
check('匿名ページで外部スクリプトを許可しない',str_contains($shellHeaders,"script-src 'self'") && str_contains($shellHeaders,"connect-src 'self'"));
check('公開照会は台帳を変更しない',cardCounts($db)===$before);
// 入金訂正・資格停止・期限切れを、保存済みQRから直接確認する。
$db->exec("UPDATE portal_memberships SET status='suspended' WHERE id=$member");
check('資格停止を既存QRへ即時反映',!qrActive($anonymous,$token) && !cardRead($owner,$member)['available']);
check('停止中は追加発行も拒否',cardPost($owner,$member)['status']===409);
$db->exec("UPDATE portal_memberships SET status='active' WHERE id=$member");
check('停止解除後も資格を再評価',qrActive($anonymous,$token));
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');
$db->exec("UPDATE portal_identifiers SET expires_on='$today' WHERE id=102");
check('ライセンス期限切れでQR無効',!qrActive($anonymous,$token) && !cardRead($owner,$member)['available']);
$db->exec('UPDATE portal_identifiers SET expires_on=NULL WHERE id=102');
$until=$db->query("SELECT valid_until FROM portal_years WHERE year=$nowYear")->fetchColumn();
$db->exec("UPDATE portal_years SET valid_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE year=$nowYear");
check('年度期限切れで会員証とQRを無効化',!qrActive($anonymous,$token) && !cardRead($owner,$member)['available']);
$s=$db->prepare('UPDATE portal_years SET valid_until=? WHERE year=?');$s->execute([$until,$nowYear]);
$ch=$db->query("SELECT * FROM portal_membership_charges WHERE membership_id=$member")->fetch(PDO::FETCH_ASSOC);
$allocation=$db->query('SELECT * FROM portal_allocations WHERE invoice_line_id='.(int)$ch['invoice_line_id'].' AND amount_yen>0 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('個人入金訂正が成功',billPost($office,'reverse',(int)$allocation['id'],['reason'=>'会員証検証の架空訂正'])['status']===200);
check('個人会費不足は既存QRと本人表示へ反映',!qrActive($anonymous,$token) && !cardRead($owner,$member)['available']);
check('個人再充当で最新の資格へ復旧',billPost($office,'reallocate',(int)$ch['invoice_id'],['payment'=>$allocation['payment_id'],'amount'=>$allocation['amount_yen']])['status']===200 && qrActive($anonymous,$token));
$clubYear=(int)$n['club_year_id'];$clubCharge=$db->query("SELECT * FROM portal_club_charges WHERE club_year_id=$clubYear")->fetch(PDO::FETCH_ASSOC);
$clubAllocation=$db->query('SELECT a.* FROM portal_allocations a WHERE a.invoice_line_id='.(int)$clubCharge['invoice_line_id'].' AND a.amount_yen>0 AND NOT EXISTS(SELECT 1 FROM portal_allocations r WHERE r.reverses_id=a.id) ORDER BY a.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('クラブ入金訂正が成功',billPost($office,'reverse',(int)$clubAllocation['id'],['reason'=>'QR検証の架空クラブ訂正'])['status']===200);
check('クラブ会費不足も既存QRへ即時反映',!qrActive($anonymous,$token));
check('クラブ再充当でQR資格を復旧',billPost($office,'reallocate',(int)$clubCharge['invoice_id'],['payment'=>$clubAllocation['payment_id'],'amount'=>$clubAllocation['amount_yen']])['status']===200 && qrActive($anonymous,$token));
$db->exec("UPDATE portal_account_controls SET status='blocked' WHERE user_id=9");
check('本人アカウント停止でも公開QR無効',!qrActive($anonymous,$token));$db->exec("UPDATE portal_account_controls SET status='active' WHERE user_id=9");
// 採番元が変わった場合は保留し、自動の番号再発行や旧QRの継続を行わない。
$s=$db->prepare('UPDATE portal_identifiers SET value_cipher=? WHERE id=102');$s->execute([$box->seal('JAFCHANGED','jmrc:identifier:v1:102')]);
check('ライセンス採番元変更で旧QR無効',!qrActive($anonymous,$token) && !cardRead($owner,$member)['available']);
$before=cardCounts($db);check('未確定の番号再発行は実行しない',cardPost($owner,$member)['status']===409 && cardCounts($db)===$before);$s->execute([$cipher]);
$db->exec("UPDATE portal_affiliations SET club_year_id=900 WHERE membership_id=$member AND valid_until IS NULL");
check('所属変更で旧番号QRを継続しない',!qrActive($anonymous,$token) && cardPost($owner,$member)['status']===409);
$db->exec("UPDATE portal_affiliations SET club_year_id=$clubYear WHERE membership_id=$member AND valid_until IS NULL");
$db->exec('UPDATE portal_member_numbers SET valid_until=UTC_TIMESTAMP(6) WHERE id='.(int)$n['id']);
check('終了した番号に結び付くQRは無効',!qrActive($anonymous,$token));
check('番号履歴があれば自動再採番しない',cardPost($owner,$member)['status']===409);
$db->exec('UPDATE portal_member_numbers SET valid_until=NULL WHERE id='.(int)$n['id']);
$db->exec('RENAME TABLE portal_card_tokens TO card_unavailable_tokens');
check('照会DB障害は無効の偽装でなく503',verifyQr($anonymous,$token)['status']===503);$db->exec('RENAME TABLE card_unavailable_tokens TO portal_card_tokens');
check('本人がQRを明示的に失効',cardPost($owner,$member,'revoke')['status']===200 && !qrActive($anonymous,$token));
$before=cardCounts($db);check('失効の再送は監査を重複しない',cardPost($owner,$member,'revoke')['status']===200 && cardCounts($db)===$before);
$new=json_decode(cardPost($owner,$member)['body'],true);$newToken=parse_url($new['qr_url'],PHP_URL_FRAGMENT);$cardQrFixtures[]=$new['qr_url'];
check('QR再発行は会員番号を変えず旧QRを無効に保持',$new['member_number']===$card['member_number'] && $newToken!==$token && qrActive($anonymous,$newToken) && !qrActive($anonymous,$token));
check('他人のQR失効を拒否',cardPost($other,$member,'revoke')['status']===404);
check('監査に番号・トークン・ライセンスを記録しない',!str_contains(json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action LIKE 'GENERAL_%'")->fetchAll()),$license) && !str_contains(json_encode($db->query('SELECT payload_minimal FROM portal_outbox')->fetchAll()),$newToken));
$before=cardCounts($db);$db->exec('RENAME TABLE portal_outbox TO card_unavailable_outbox');
check('QR失効通知障害は503',cardPost($owner,$member,'revoke')['status']===503);$db->exec('RENAME TABLE card_unavailable_outbox TO portal_outbox');
check('QR失効の障害時は一括ロールバック',qrActive($anonymous,$newToken) && cardCounts($db)===$before);
$db->exec("UPDATE portal_card_tokens SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 100 MICROSECOND WHERE membership_id=$member AND revoked_at IS NULL");
check('トークン期限切れでも旧QR無効',!qrActive($anonymous,$newToken) && !cardRead($owner,$member)['available']);
$renewed=json_decode(cardPost($owner,$member)['body'],true);$renewedToken=parse_url($renewed['qr_url'],PHP_URL_FRAGMENT);$cardQrFixtures[]=$renewed['qr_url'];
check('期限切れQRの更新でも会員番号を維持',$renewed['member_number']===$card['member_number'] && $renewedToken!==$newToken && qrActive($anonymous,$renewedToken));
// 本人確認済みでも停止されたアカウント、未入金、未承認は同じ判定器で拒否する。
$app=(int)$db->query("SELECT application_id FROM portal_memberships WHERE id=$member")->fetchColumn();
$db->exec("UPDATE portal_applications SET status='submitted' WHERE id=$app");
check('承認取消も既存QRへ反映',!qrActive($anonymous,$renewedToken));$db->exec("UPDATE portal_applications SET status='approved' WHERE id=$app");
// 同時発行を別プロセスで行い、同じ番号・同じQRの一件に収束させる。
$raceMember=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=3 AND year=$nowYear AND member_type='general'")->fetchColumn();
$s=$db->prepare('UPDATE portal_identifiers SET value_cipher=? WHERE id=101');$s->execute([$box->seal('JAF000003','jmrc:identifier:v1:101')]);
$when=microtime(true)+0.5;$workers=[];$rows=[];
foreach([1,2] as $unused){$pipes=[];$p=proc_open([PHP_BINARY,'/srv/tests/concurrent_card.php',(string)$when,(string)$raceMember],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check('会員証同時発行ワーカー正常終了 '.count($rows),proc_close($p)===0 && $error==='');$rows[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
check('会員証同時発行は同じQRへ収束',$rows[0]['status']===200 && $rows[1]['status']===200 && $rows[0]['qr_url']===$rows[1]['qr_url']);
check('同時発行でも番号・有効QRが各一件',(int)$db->query("SELECT COUNT(*) FROM portal_member_numbers WHERE membership_id=$raceMember")->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM portal_card_tokens WHERE membership_id=$raceMember AND revoked_at IS NULL")->fetchColumn()===1);
$cardQrFixtures[]=$rows[0]['qr_url'];
$raceNumber=(int)$db->query("SELECT id FROM portal_member_numbers WHERE membership_id=$raceMember")->fetchColumn();
dbReject($db,'別人の番号へQRを結び付けられない',"UPDATE portal_card_tokens SET member_number_id=$raceNumber WHERE membership_id=$member AND revoked_at IS NULL",1452);
check('会員証CSSとQRライブラリはローカル配信',$owner->request('/manage/assets/cards.css')['status']===200 && $owner->request('/manage/assets/qrcodegen.js')['status']===200);
