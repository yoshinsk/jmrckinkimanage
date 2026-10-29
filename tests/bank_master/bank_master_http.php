<?php
// Path: tests/bank_master/bank_master_http.php
// 機能: 架空口座の権限・履歴・URL・有効期間・実口座対応と発行済み請求の不変をHTTPS検証する。
declare(strict_types=1);
require_once '/srv/auth/src/Bank/BankMasterService.php';
function masterPost(Browser $b,array $in): array {return $b->request('/manage/bank-settings.php?format=json',$in+['_csrf'=>identity($b)['csrf']]);}
function masterRead(Browser $b,int $id): array {$r=$b->request('/manage/bank-settings.php?format=json&account='.$id);if($r['status']!==200){throw new RuntimeException('master read '.$r['status']);}return json_decode($r['body'],true);}
function masterSource(Browser $b,int $id): array {$r=$b->request('/manage/bank-settings.php?format=json&source='.$id);return json_decode($r['body'],true);}
function masterInput(array $r,array $replace=[]): array {
    $local=static fn($v)=>$v===null?'':(new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d\TH:i:s.u');
    return $replace+['action'=>'account','id'=>$r['id'],'row_version'=>$r['row_version'],'purpose_code'=>$r['purpose_code'],'year'=>$r['year']??'','display_name'=>$r['display_name'],'details'=>'','payment_url'=>$r['payment_url']??'','valid_from'=>$local($r['valid_from']),'valid_until'=>$local($r['valid_until']),'enabled'=>$r['enabled'],'source_id'=>$r['source_id']??0,'reason'=>'架空マスター設定の確認'];
}
function masterCount(PDO $db): int {return (int)$db->query('SELECT COUNT(*) FROM portal_bank_master_changes')->fetchColumn();}
function masterRace(array $inputs): array {
    $start=(string)(microtime(true)+0.4);$jobs=[];foreach($inputs as $in){$pipes=[];$p=proc_open(['php','/srv/tests/concurrent_bank_master.php',$start,base64_encode(json_encode($in,JSON_THROW_ON_ERROR))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$jobs[]=[$p,$pipes];}
    $out=[];foreach($jobs as [$p,$pipes]){$text=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException('master worker failed');}$out[]=json_decode($text,true,512,JSON_THROW_ON_ERROR);}return $out;
}
$mOffice=new Browser();$mOffice->login(76);$mFinance=new Browser();$mFinance->login(62);$mLegacy=new Browser();$mLegacy->login(1);$mRep=new Browser();$mRep->login(3);$mAnon=new Browser();
check('口座設定匿名401',$mAnon->request('/manage/bank-settings.php?format=json')['status']===401);
foreach(['財務専用'=>$mFinance,'旧管理者'=>$mLegacy,'クラブ代表'=>$mRep] as $name=>$b){check('口座設定の専用権限がない'.$name.'は403',$b->request('/manage/bank-settings.php?format=json')['status']===403);}
check('口座設定は事務局の専用権限で閲覧',$mOffice->request('/manage/bank-settings.php')['status']===200);
$before=masterCount($db);$mOffice->request('/manage/bank-settings.php?format=json');check('口座設定GETで履歴や設定を作らない',masterCount($db)===$before);
$newSource=['action'=>'source','code'=>'lab-master-source','display_name'=>'架空マスター実口座','enabled'=>'1','reason'=>'架空実口座の登録','physical_verified'=>'1'];
check('口座設定POSTにCSRF必須',$mOffice->request('/manage/bank-settings.php?format=json',$newSource)['status']===400);
check('取込元の新規登録は実口座の重複確認必須',masterPost($mOffice,array_replace($newSource,['physical_verified'=>'0']))['status']===422);
$r=masterPost($mOffice,$newSource);$ms=json_decode($r['body'],true);$sid=(int)($ms['id']??0);check('取込元を画面APIから新規作成',$r['status']===200 && $sid>0 && !isset($ms['dedup_key_check']));
check('取込元コードの重複登録を拒否',masterPost($mOffice,$newSource)['status']===409);
check('取込元のコードは変更不可',masterPost($mOffice,['action'=>'source','id'=>$sid,'row_version'=>1,'code'=>'other','display_name'=>'架空','enabled'=>1,'reason'=>'確認'])['status']===409);
$account=['action'=>'account','purpose_code'=>'sports_insurance','year'=>'','display_name'=>'架空保険入金先','details'=>"架空銀行 架空支店\n普通 0000000 架空名義",'payment_url'=>'https://example.invalid/master-core?token=synthetic-only','enabled'=>'0','source_id'=>$sid,'mapping_verified'=>'1','reason'=>'架空口座の登録'];
check('口座と実口座の対応確認必須',masterPost($mOffice,array_replace($account,['mapping_verified'=>'0']))['status']===422);
$r=masterPost($mOffice,$account);$a=json_decode($r['body'],true);$aid=(int)($a['id']??0);
check('用途別口座とURLと取込元を登録',$r['status']===200 && $a['details']===$account['details'] && (int)$a['source_id']===$sid && (int)$a['row_version']===1);
$cipher=$db->query('SELECT details_cipher FROM portal_bank_accounts WHERE id='.$aid)->fetchColumn();check('口座案内は平文DB保管しない',str_starts_with($cipher,'gcm1:') && !str_contains($cipher,'0000000') && !isset($a['details_cipher']));
$listing=$mOffice->request('/manage/bank-settings.php?format=json');check('口座一覧は番号・URLを返さない',!str_contains($listing['body'],'0000000') && !str_contains($listing['body'],'master-core'));
foreach(['http://example.invalid/','javascript:alert(1)','https://user:pass@example.invalid/','https://example.invalid/a'."\r\n".'b','https://example.invalid/\\evil'] as $index=>$bad){check('口座決済URLの不正形式拒否 '.$index,masterPost($mOffice,masterInput($a,['payment_url'=>$bad]))['status']===422);}
check('口座不明用途の新規登録を拒否',masterPost($mOffice,array_replace($account,['purpose_code'=>'unknown']))['status']===422);
check('口座未登録年度の設定拒否',masterPost($mOffice,array_replace($account,['year'=>2199]))['status']===422);
check('口座理由なし更新拒否',masterPost($mOffice,masterInput($a,['reason'=>'']))['status']===422);
check('口座更新者のHTTP指定を拒否',masterPost($mOffice,masterInput($a)+['updated_by'=>1])['status']===422);
check('口座不正日時を拒否',masterPost($mOffice,masterInput($a,['valid_from'=>'2026-02-30T00:00']))['status']===422);
check('口座終了だけの日時指定拒否',masterPost($mOffice,masterInput($a,['valid_until'=>'2026-12-31T00:00']))['status']===422);
$updated=masterPost($mOffice,masterInput($a,['display_name'=>'架空保険入金先 更新','payment_url'=>'https://example.invalid/master-new','valid_from'=>'2026-12-01T00:00:12.123456','valid_until'=>'2027-01-01T00:00','enabled'=>'1']));$a2=json_decode($updated['body'],true);
check('口座更新と日本時間UTC変換',$updated['status']===200 && $a2['valid_from']==='2026-11-30 15:00:12.123456' && (int)$a2['row_version']===2);
check('口座空欄案内は暗号文を保持',$db->query('SELECT details_cipher FROM portal_bank_accounts WHERE id='.$aid)->fetchColumn()===$cipher);
check('口座古い版を拒否',masterPost($mOffice,masterInput($a,['display_name'=>'競合']))['status']===409);
$before=masterCount($db);$noop=json_decode(masterPost($mOffice,masterInput($a2))['body'],true);check('口座変更なしでは版・履歴を増やさない',$noop['unchanged'] && masterCount($db)===$before);
check('口座有効期間の重複を拒否',masterPost($mOffice,array_replace($account,['enabled'=>'1','valid_from'=>'2026-12-01T00:00','valid_until'=>'2027-01-01T00:00']))['status']===409);
check('全年度設定と同年度設定の重複も拒否',masterPost($mOffice,array_replace($account,['year'=>(int)gmdate('Y')+1,'enabled'=>'1','valid_from'=>'2026-12-01T00:00','valid_until'=>'2027-01-01T00:00']))['status']===409);
$hist=$db->query("SELECT * FROM portal_bank_master_changes WHERE entity_type='account' AND entity_id=$aid ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);$historyId=(int)$hist['id'];
check('変更理由と前後値は暗号化履歴',str_starts_with($hist['snapshot_cipher'],'gcm1:') && !str_contains($hist['snapshot_cipher'],'master-new') && !str_contains($hist['reason_cipher'],'架空'));
$history=json_decode($mOffice->request('/manage/bank-settings.php?format=json&history='.$historyId)['body'],true);
check('口座変更前後と担当者理由を照会',$history['before']['payment_url']===$account['payment_url'] && $history['after']['payment_url']==='https://example.invalid/master-new' && $history['reason']==='架空マスター設定の確認' && $history['actor_user_id']===76 && !isset($history['after']['details_cipher_base64']));
check('履歴詳細も財務専用権限には非公開',$mFinance->request('/manage/bank-settings.php?format=json&history='.$historyId)['status']===403);
check('口座用途年度は登録後変更不可',masterPost($mOffice,masterInput($a2,['purpose_code'=>'club_fee']))['status']===409);
$a3=json_decode(masterPost($mOffice,masterInput($a2,['enabled'=>'0','payment_url'=>'']))['body'],true);check('口座無効化と決済URL解除',(int)$a3['enabled']===0 && $a3['payment_url']===null);
$alias=json_decode(masterPost($mOffice,array_replace($account,['display_name'=>'架空未使用別設定']))['body'],true);$alias2=json_decode(masterPost($mOffice,masterInput($alias,['source_id'=>2,'mapping_verified'=>'1']))['body'],true);
check('未使用設定の取込元変更は確認後に可能',(int)$alias2['source_id']===2);
// 無効な用途別口座でも実口座の取込元は履歴取込に利用できる。
$masterConfig=require '/srv/private/auth.config.php';$bank=new \Jmrc\Bank\BankService($db,$masterConfig);
$masterCsv=bankCsv('架空マスター取込','1111','2026-09-01');$import=$bank->import(76,$sid,$masterCsv,'UTF-8');check('口座無効化は実口座の履歴取込を妨げない',$import['new_rows']===1 && in_array($sid,array_map('intval',array_column($bank->sources(76),'id')),true));
$keyCheck=$db->query('SELECT dedup_key_check FROM portal_bank_sources WHERE id='.$sid)->fetchColumn();
check('取込済みの対応は付替え禁止',masterPost($mOffice,masterInput($a3,['source_id'=>2,'mapping_verified'=>'1']))['status']===409);
check('使用済み口座案内の上書き拒否',masterPost($mOffice,masterInput($a3,['details'=>'別の架空口座 9999999']))['status']===409);
$sourceInput=['action'=>'source','id'=>$sid,'row_version'=>1,'code'=>'lab-master-source','display_name'=>'架空マスター実口座 改名','enabled'=>'0','reason'=>'架空CSV一時停止'];
$stopped=json_decode(masterPost($mOffice,$sourceInput)['body'],true);check('取込元停止を保存',(int)$stopped['enabled']===0 && (int)$stopped['row_version']===2);
$blocked=false;try{$bank->import(76,$sid,$masterCsv,'UTF-8');}catch(\Jmrc\Auth\HttpFailure $e){$blocked=$e->status===422;}check('取込元停止でCSVを拒否',$blocked);
check('取込元の停止でも口座変更は独立して可能',masterPost($mOffice,masterInput($a3,['display_name'=>'架空保険 入金案内更新']))['status']===200);
$resumed=json_decode(masterPost($mOffice,array_replace($sourceInput,['row_version'=>2,'enabled'=>'1','reason'=>'架空CSV再開']))['body'],true);
check('取込元再開は重複鍵を保持',(int)$resumed['enabled']===1 && $db->query('SELECT dedup_key_check FROM portal_bank_sources WHERE id='.$sid)->fetchColumn()===$keyCheck && $bank->import(76,$sid,$masterCsv,'UTF-8')['replayed']);
check('取込元の鍵チェックをPOST指定不可',masterPost($mOffice,array_replace($sourceInput,['row_version'=>3])+['dedup_key_check'=>'reset'])['status']===422);
$used=masterRead($mOffice,$memberAccount);$snapshots=$db->query('SELECT id,account_snapshot_cipher,payment_url_snapshot FROM portal_invoices WHERE account_id='.$memberAccount.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check('使用済み口座の取込元付替えを拒否',masterPost($mOffice,masterInput($used,['source_id'=>2,'mapping_verified'=>'1']))['status']===409);
check('使用済み口座の案内を変更不可',masterPost($mOffice,masterInput($used,['details'=>'別口座案内']))['status']===409);
$usedUrl=json_decode(masterPost($mOffice,masterInput($used,['payment_url'=>'https://example.invalid/master-pay']))['body'],true);
check('使用済み口座でも新規請求用URLを変更可能',$usedUrl['payment_url']==='https://example.invalid/master-pay');
check('発行済み請求の口座とURLを維持',$db->query('SELECT id,account_snapshot_cipher,payment_url_snapshot FROM portal_invoices WHERE account_id='.$memberAccount.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$snapshots);
$unbilledTemp=(int)$db->query('SELECT id FROM portal_memberships WHERE user_id=86')->fetchColumn();$freshInvoice=json_decode(billPost($mOffice,'issue',$unbilledTemp)['body'],true);
check('新規請求だけ変更後のURLを使用',$freshInvoice['payment_url_snapshot']==='https://example.invalid/master-pay');
check('口座設定削除の入口はない',masterPost($mOffice,['action'=>'delete','id'=>$aid])['status']===422);
$before=masterCount($db);$oldAlias=masterRead($mOffice,(int)$alias['id']);
$db->exec('RENAME TABLE portal_audit_events TO master_unavailable_audit');$fail=masterPost($mOffice,masterInput($oldAlias,['display_name'=>'保存されない変更']));$db->exec('RENAME TABLE master_unavailable_audit TO portal_audit_events');
check('口座監査失敗は設定と履歴をロールバック',$fail['status']===503 && masterCount($db)===$before && masterRead($mOffice,(int)$alias['id'])['display_name']===$oldAlias['display_name']);
$db->exec('RENAME TABLE portal_bank_master_changes TO master_unavailable_history');$fail=masterPost($mOffice,masterInput($oldAlias,['display_name'=>'履歴なしでは保存しない']));$db->exec('RENAME TABLE master_unavailable_history TO portal_bank_master_changes');
check('口座履歴失敗は設定をロールバック',$fail['status']===503 && masterRead($mOffice,(int)$alias['id'])['display_name']===$oldAlias['display_name']);
$raceInput=masterInput($oldAlias);$before=masterCount($db);$race=masterRace([array_replace($raceInput,['display_name'=>'競合A']),array_replace($raceInput,['display_name'=>'競合B'])]);$codes=array_column($race,'status');sort($codes);
check('口座同じ版の同時更新は一件だけ成功',$codes===[200,409] && masterCount($db)===$before+1);
$overlapInput=array_replace($account,['purpose_code'=>'jmrc_condolence','source_id'=>0,'enabled'=>1,'payment_url'=>'','display_name'=>'同時有効口座A']);$race=masterRace([$overlapInput,array_replace($overlapInput,['display_name'=>'同時有効口座B'])]);$codes=array_column($race,'status');sort($codes);
check('口座同時新規登録でも有効期間を重複させない',$codes===[200,409]);
$runtime=new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','auth_runtime','lab_runtime_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$denied=false;
try{$runtime->exec("UPDATE portal_bank_master_changes SET reason_cipher='tamper' WHERE id=$historyId");}catch(PDOException $e){$denied=(int)($e->errorInfo[1]??0)===1142;}check('口座変更履歴はruntimeから更新不可',$denied);
$db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=76 AND role_id=1");check('口座専用権限の失効を即反映',$mOffice->request('/manage/bank-settings.php?format=json&account='.$aid)['status']===403);$db->exec("UPDATE portal_user_role_grants SET revoked_at=NULL WHERE user_id=76 AND role_id=1");
$disabled=new \Jmrc\Bank\BankMasterService($db,[]);$blocked=false;try{$disabled->access(76);}catch(\Jmrc\Auth\HttpFailure $e){$blocked=$e->status===503;}check('口座設定は既定無効',$blocked);
$audit=json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action='BANK_MASTER_UPDATED'")->fetchAll());check('口座監査に番号・URL・理由を平文記録しない',!str_contains($audit,'0000000') && !str_contains($audit,'master-core') && !str_contains($audit,'架空'));
