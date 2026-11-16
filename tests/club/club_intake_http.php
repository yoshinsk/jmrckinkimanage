<?php
// Path: tests/club/club_intake_http.php
// 機能: 架空データだけでC1～C4、更新候補、共通整形、暗号保存、請求と既存entryの不変を検証する。
declare(strict_types=1);
use Jmrc\Club\ClubIntakeService as Intake;
use Jmrc\Club\ClubRegistrationData as Registration;
use Jmrc\Club\ClubIntakePolicy as IntakePolicy;
use Jmrc\Input\TextInput as TextInput;
(function()use($db):void{
    $cfg=require '/srv/private/auth.config.php';$service=new Intake($db,$cfg);$path='/manage/club-register.php';
    $savedYear=$db->query('SELECT * FROM portal_years WHERE year=2027')->fetch(PDO::FETCH_ASSOC);
    check('2027年度の隔離マスターあり',(bool)$savedYear);
    $db->exec("UPDATE portal_years SET source_mode='shadow',club_open_at=UTC_TIMESTAMP()-INTERVAL 1 DAY,club_close_at=UTC_TIMESTAMP()+INTERVAL 40 DAY,valid_until='2027-12-31 15:00:00' WHERE year=2027");
    if(!(int)$db->query('SELECT COUNT(*) FROM portal_club_fee_profiles')->fetchColumn()){$db->exec(file_get_contents('/srv/tests/013_club_fee_profiles_2027.sql'));}
    $legacy=$db->query('CHECKSUM TABLE jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships,users,jmrc_affiliated_clubs')->fetchAll(PDO::FETCH_ASSOC);
    check('半角整形でもローマ数字を保持',TextInput::normalize('Ａｚ０９　ⅠⅡⅢⅣⅤⅥⅦⅧⅨⅩ')==='Az09 ⅠⅡⅢⅣⅤⅥⅦⅧⅨⅩ');
    $normal=TextInput::http(['name'=>'　ＡＢＣⅢ１２３　','password'=>'ＰＡＳＳ　Ⅰ','_csrf'=>'ＴＯＫＥＮ','csv_text'=>'Ａ,　Ｂ','nested'=>['name'=>'Ｘ　Ⅲ']]);
    check('共通整形は配列へ適用し認証値とCSV原本を保持',$normal===['name'=>' ABCⅢ123 ','password'=>'ＰＡＳＳ　Ⅰ','_csrf'=>'ＴＯＫＥＮ','csv_text'=>'Ａ,　Ｂ','nested'=>['name'=>'X Ⅲ']]);
    try{TextInput::normalize("\xff");check('不正UTF8を拒否',false);}catch(\Jmrc\Auth\HttpFailure $e){check('不正UTF8を拒否',$e->status===422);}
    $window=['source_mode'=>'shadow','club_open_at'=>'2026-11-30 15:00:00.000000','club_close_at'=>'2027-02-07 15:00:00.000000','valid_until'=>'2027-12-31 15:00:00.000000'];
    check('クラブ開始境界は日本時間12月1日0時',!IntakePolicy::open($window,new DateTimeImmutable('2026-12-01T00:00:00+09:00'),[])&&!IntakePolicy::open($window,new DateTimeImmutable('2026-11-30T23:59:59+09:00'),$cfg)&&IntakePolicy::open($window,new DateTimeImmutable('2026-12-01T00:00:00+09:00'),$cfg));
    check('クラブ締切境界は2月7日終日',IntakePolicy::open($window,new DateTimeImmutable('2027-02-07T23:59:59+09:00'),$cfg)&&!IntakePolicy::open($window,new DateTimeImmutable('2027-02-08T00:00:00+09:00'),$cfg));
    foreach(['official'=>50000,'affiliated'=>30000,'associate'=>20000,'organization'=>20000] as $kind=>$annual){
        foreach(['regular','supporting'] as $member){foreach(['new','renewal','rejoin'] as $reg){$a=$kind==='organization'&&$member==='supporting'?10000:$annual;$q=Registration::quote($db,2027,$kind,$member,$reg);check('年度料金 '.$kind.' '.$member.' '.$reg,$q['annual_yen']===$a&&$q['joining_yen']===($reg==='new'?10000:0)&&$q['total_yen']===$a+($reg==='new'?10000:0));}}
    }
    check('関連団体企業は入会金なし',Registration::quote($db,2027,'business','supporting','new')['total_yen']===50000);
    $input=array_fill_keys(array_keys(Registration::LABELS),'架空');$input=array_replace($input,['club_name'=>'架空ＡＢＣ　Ⅲ','club_short'=>'ＡＢＣⅢ','region'=>'27','jaf_kind'=>'organization','membership_kind'=>'supporting','registration_kind'=>'new','same_contact'=>'1','company'=>'','club_postcode'=>'０００－００００','rep_postcode'=>'0000000','rep_email'=>'ｃｌｕｂ@example.invalid','rep_phone'=>'００００００００００','rep_mobile'=>'00000000000','contact_postcode'=>'','contact_email'=>'','candidate_ref'=>'']);
    // 英数字と空白だけを変換するため、区切り記号は半角で入力する。
    $input['club_postcode']='０００-００００';
    $input['jaf_number']='９９９９０';
    $owner=new Browser();$owner->login(1);$office=new Browser();$office->login(7);$other=new Browser();$other->login(2);$anon=new Browser();
    $post=static function(Browser $b,array $in)use($path):array{return $b->request($path.'?format=json',$in+['_csrf'=>identity($b)['csrf']]);};
    $get=static fn(Browser $b,int $id)=>$b->request($path.'?format=json&id='.$id);
    check('未認証はクラブ申請不可',$anon->request($path.'?format=json')['status']===401);
    check('一般本人も事前代表権限なしで入力可能',$owner->request($path)['status']===200);
    check('一般本人は事務局一覧不可',$owner->request($path.'?all=1')['status']===403);
    check('CSRFなしのクラブ申請を拒否',$owner->request($path,['action'=>'submit'])['status']===400);
    $before=(int)$db->query('SELECT COUNT(*) FROM portal_club_intakes')->fetchColumn();
    try{(new Intake($db,array_replace($cfg,['club_intake_enabled'=>false])))->submit(1,2027,$input,bin2hex(random_bytes(24)));check('受付保留は直接呼出しも拒否',false);}catch(\Jmrc\Auth\HttpFailure $e){check('受付保留は直接呼出しも拒否',$e->status===503&&(int)$db->query('SELECT COUNT(*) FROM portal_club_intakes')->fetchColumn()===$before);}
    $key=bin2hex(random_bytes(24));$form=$input+['action'=>'submit','year'=>'２０２７','request_key'=>$key];
    $response=$post($owner,$form);check('shadowでもクラブ申請を提出できる',$response['status']===200);
    $r=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);$id=(int)$r['id'];
    check('事前照合なしで正式入力を保存',$r['review_state']==='submitted'&&$r['club_year_id']===null&&$r['data']['club_name']==='架空ABC Ⅲ'&&$r['data']['club_short']==='ABCⅢ'&&$r['data']['company']===''&&$r['data']['contact_email']==='club@example.invalid');
    $raw=$db->query('SELECT payload_cipher,payload_hmac FROM portal_club_intakes WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    check('入力は暗号保存し暗号文とHMACは応答へ返さない',str_starts_with($raw['payload_cipher'],'gcm1:')&&!str_contains($raw['payload_cipher'],'架空ABC')&&!str_contains($response['body'],'payload_cipher')&&!str_contains($response['body'],'payload_hmac'));
    check('他人の申請参照を拒否',$get($other,$id)['status']===403&&$get($office,$id)['status']===200);
    $replay=$post($owner,$form);check('同じ申請の再送は一件のみ',json_decode($replay['body'],true)['replayed']&&(int)$db->query('SELECT COUNT(*) FROM portal_club_intakes')->fetchColumn()===$before+1);
    check('内容を変えた同キー再送を拒否',$post($owner,array_replace($form,['club_name'=>'別の申請']))['status']===409);
    check('不存在の更新候補を拒否',$post($owner,array_replace($form,['request_key'=>bin2hex(random_bytes(24)),'registration_kind'=>'renewal','candidate_ref'=>'legacy:99999999']))['status']===422);
    $edit=$post($owner,array_replace($input,['action'=>'update','id'=>$id,'row_version'=>$r['row_version'],'rep_name'=>'訂正ＡⅠ']));$r=json_decode($edit['body'],true);
    check('正式入力の訂正と暗号化履歴',$edit['status']===200&&$r['data']['rep_name']==='訂正AⅠ'&&(int)$db->query('SELECT COUNT(*) FROM portal_club_intake_revisions WHERE intake_id='.$id)->fetchColumn()===1);
    check('古い版の訂正を拒否',$post($owner,array_replace($input,['action'=>'update','id'=>$id,'row_version'=>1]))['status']===409);
    $returned=$post($office,['action'=>'return','id'=>$id,'row_version'=>$r['row_version'],'reason'=>'JAF番号・区分Ａを確認してくださいⅢ']);$rr=json_decode($returned['body'],true);
    check('事務局は理由付きで差し戻す',$returned['status']===200&&$rr['review_state']==='returned'&&$rr['data']['return_reason']==='JAF番号・区分Aを確認してくださいⅢ');
    check('差し戻し中は年度接続を拒否',$post($office,['action'=>'link','id'=>$id,'row_version'=>$rr['row_version'],'fixed_code'=>'99990','checked'=>'1'])['status']===409);
    check('本人は差し戻し操作不可',$post($owner,['action'=>'return','id'=>$id,'row_version'=>$rr['row_version'],'reason'=>'本人差し戻し'])['status']===403);
    $again=$post($owner,array_replace($input,['action'=>'update','id'=>$id,'row_version'=>$rr['row_version']]));$r=json_decode($again['body'],true);
    check('訂正して再申請し差し戻し理由を履歴へ保存',$again['status']===200&&$r['review_state']==='submitted'&&!isset($r['data']['return_reason'])&&(int)$db->query('SELECT COUNT(*) FROM portal_club_intake_revisions WHERE intake_id='.$id)->fetchColumn()===3);
    check('申請JAF番号と別の番号で接続しない',$post($office,['action'=>'link','id'=>$id,'row_version'=>$r['row_version'],'fixed_code'=>'12345','checked'=>'1'])['status']===409);
    check('申請者自身は照合接続できない',$post($owner,['action'=>'link','id'=>$id,'row_version'=>$r['row_version'],'fixed_code'=>'99990','checked'=>'1'])['status']===403);
    $linked=$post($office,['action'=>'link','id'=>$id,'row_version'=>$r['row_version'],'fixed_code'=>'９９９９０','checked'=>'1']);$linked=json_decode($linked['body'],true);$cy=(int)($linked['club_year_id']??0);
    check('事務局の申請後接続と先頭6のコード',$cy>0&&$db->query('SELECT display_code FROM portal_club_years WHERE id='.$cy)->fetchColumn()==='699990');
    check('照合前提のない申請へ既存権限を付与しない',(int)$db->query('SELECT COUNT(*) FROM portal_user_role_grants WHERE user_id=1 AND scope_club_id=(SELECT club_id FROM portal_club_years WHERE id='.$cy.')')->fetchColumn()===0);
    $savedBanks=$db->query("SELECT id,enabled FROM portal_bank_accounts WHERE purpose_code='club_fee'")->fetchAll(PDO::FETCH_ASSOC);$db->exec("UPDATE portal_bank_accounts SET enabled=0 WHERE purpose_code='club_fee'");
    $db->exec("INSERT INTO portal_bank_accounts(purpose_code,year,display_name,details_cipher,payment_url,enabled,updated_by) VALUES('club_fee',2027,'架空C1確認口座','fixture','https://example.invalid/ignored',1,7)");$bank=(int)$db->lastInsertId();
    $bill=$post($owner,['action'=>'issue','id'=>$id]);$invoice=json_decode($bill['body'],true);$inv=(int)($invoice['id']??0);
    check('賛助登録団体の新規は年会費と入会金の合計を一意請求',$bill['status']===200&&(int)$invoice['amount_yen']===20000&&$invoice['payment_url_snapshot']===null);
    check('クラブ請求の再送でも二重請求なし',(int)json_decode($post($owner,['action'=>'issue','id'=>$id])['body'],true)['id']===$inv);
    $billing=new \Jmrc\Billing\BillingService($db);$paymentOperation=bin2hex(random_bytes(24));$billing->confirm(7,$inv,20000,'fixture-intake-'.$id,$paymentOperation);
    $approved=(new \Jmrc\Club\ClubService($db,$cfg['data_key']))->approve(7,$cy);
    check('クラブの先行承認はできるがshadow資格は有効化しない',$approved['eligibility']['ready']&&!$approved['eligibility']['active']&&$db->query('SELECT source_mode FROM portal_years WHERE year=2027')->fetchColumn()==='shadow');
    $allocation=$db->prepare('SELECT id FROM portal_allocations WHERE operation_key=?');$allocation->execute([$paymentOperation]);$billing->reverse(7,(int)$allocation->fetchColumn(),bin2hex(random_bytes(24)),'架空訂正');
    check('先行承認後も入金訂正でreadyを取り消す',!(new \Jmrc\Club\ClubEligibility($db))->evaluate($cy)['ready']);
    $db->exec("INSERT INTO jmrc_affiliated_clubs(club_code,name,short_name,branch_name,format_status,join_date) VALUES('199991','既存ＡＢＣ　Ⅲ','既存Ⅲ','架空','架空','2020-01-01')");$legacyId=(int)$db->lastInsertId();
    $candidates=$service->candidates(1,'ＡＢＣ　Ⅲ');check('旧台帳の全角名も更新候補に表示',in_array('legacy:'.$legacyId,array_column($candidates,'ref'),true));
    check('候補検索でもローマ数字を英字に変換しない',!in_array('legacy:'.$legacyId,array_column($service->candidates(1,'ABC III'),'ref'),true));
    $renew=$post($owner,array_replace($form,['jaf_number'=>'99991','request_key'=>bin2hex(random_bytes(24)),'registration_kind'=>'renewal','candidate_ref'=>'legacy:'.$legacyId]));$renew=json_decode($renew['body'],true);$rid=(int)$renew['id'];
    check('更新選択は事務局確認待ちで保存し権限を自動付与しない',$renew['review_state']==='renewal_review'&&$renew['club_year_id']===null&&(int)$renew['joining_yen']===0);
    check('更新の確認チェックなしは接続できない',$post($office,['action'=>'link','id'=>$rid,'row_version'=>1,'target_ref'=>'legacy:'.$legacyId,'fixed_code'=>'99991'])['status']===409);
    $linked=$post($office,['action'=>'link','id'=>$rid,'row_version'=>1,'target_ref'=>'legacy:'.$legacyId,'fixed_code'=>'99991','checked'=>'1']);
    check('旧クラブは照合後にだけ新台帳へ接続',$linked['status']===200&&(int)$db->query('SELECT COUNT(*) FROM portal_clubs WHERE legacy_affiliated_id='.$legacyId)->fetchColumn()===1);
    $db->exec('UPDATE portal_bank_accounts SET enabled=0 WHERE id='.$bank);foreach($savedBanks as $b){$s=$db->prepare('UPDATE portal_bank_accounts SET enabled=? WHERE id=?');$s->execute([$b['enabled'],$b['id']]);}
    $s=$db->prepare('UPDATE portal_years SET source_mode=?,club_open_at=?,club_close_at=?,valid_until=? WHERE year=2027');$s->execute([$savedYear['source_mode'],$savedYear['club_open_at'],$savedYear['club_close_at'],$savedYear['valid_until']]);
    // 旧クラブへの追加は架空候補1件だけ。それ以外のentry・料金・イベント・ユーザーは不変。
    $after=$db->query('CHECKSUM TABLE jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships,users')->fetchAll(PDO::FETCH_ASSOC);
    check('新しい申請・請求でも既存エントリーとユーザーを変更しない',$after===array_slice($legacy,0,5));
})();
