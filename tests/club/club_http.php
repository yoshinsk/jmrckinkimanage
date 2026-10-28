<?php
// Path: tests/club/club_http.php
// 機能: 年度クラブ登録・事務局承認・会費・訂正と個人資格の連動を架空データでHTTPS検証。
declare(strict_types=1);
/** 専用HTTP入口でセッション権限/CSRF/入力検証を含めて呼ぶ。 */
function clubPost(Browser $browser,string $action,array $data): array
{
    return $browser->request('/manage/clubs.php?format=json',$data+['action'=>$action,'_csrf'=>identity($browser)['csrf']]);
}
function clubRead(Browser $browser,int $id): array
{
    $r=$browser->request('/manage/clubs.php?format=json&id='.$id);
    if ($r['status']!==200) { throw new RuntimeException('club read HTTP '.$r['status']); }return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
}
$nowYear=(int)gmdate('Y');$next=$nowYear+1;
$db->exec("INSERT INTO portal_clubs(id,fixed_code,name_full,name_short) VALUES(3,'00003','架空継続クラブ','継続'),(4,'00004','架空別クラブ','別');
    INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,status,valid_from,granted_by,approved_by)
    VALUES(2,2,'club',3,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1);
    UPDATE portal_user_role_grants SET status='active' WHERE user_id=13 AND role_id=3;
    UPDATE portal_years SET club_open_at=UTC_TIMESTAMP()-INTERVAL 1 DAY,club_close_at=UTC_TIMESTAMP()+INTERVAL 30 DAY WHERE year IN ($nowYear,$next)");
$rep=new Browser();$rep->login(2);$office=new Browser();$office->login(7);$finance=new Browser();$finance->login(13);$admin=new Browser();$admin->login(1);
$fields=['name_full'=>'架空継続クラブ年度名称','name_short'=>'継続27','rep_name'=>'架空代表','contact_name'=>'架空連絡','postcode'=>'0000000','address'=>'架空住所','phone'=>'000-0000','email'=>'club3@example.invalid','bank_details'=>'架空金融機関 口座0123456'];
$data=$fields+['club_id'=>3,'year'=>$nowYear,'category_digit'=>'1','request_key'=>bin2hex(random_bytes(24))];
check('旧管理者だけではクラブ登録できない',clubPost($admin,'submit',$data)['status']===403);
check('代表の担当外クラブ登録拒否',clubPost($rep,'submit',array_replace($data,['club_id'=>4]))['status']===403);
check('クラブ登録CSRF欠落拒否',$rep->request('/manage/clubs.php?format=json',$data+['action'=>'submit'])['status']===400);
$before=memberCounts($db);
check('無効区分の年度登録拒否',clubPost($rep,'submit',array_replace($data,['category_digit'=>'4']))['status']===422);
check('必須連絡先の欠落拒否',clubPost($rep,'submit',array_replace($data,['phone'=>'']))['status']===422);
check('区分不正等は申請を残さない',memberCounts($db)===$before);
require_once '/srv/auth/src/Club/ClubService.php';
$missingKey=false;
try {(new \Jmrc\Club\ClubService($db))->submit(2,3,$nowYear,'1',$fields,bin2hex(random_bytes(24)));}catch(\Jmrc\Auth\HttpFailure $e){$missingKey=$e->status===503;}
check('暗号鍵未設定時に口座平文を保存しない',$missingKey && memberCounts($db)===$before);
$db->exec("UPDATE portal_years SET club_open_at=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE year=$nowYear");
check('受付開始前を拒否',clubPost($rep,'submit',$data)['status']===422);
$db->exec("UPDATE portal_years SET club_open_at=UTC_TIMESTAMP()-INTERVAL 2 DAY,club_close_at=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE year=$nowYear");
check('締切後の年度登録を拒否',clubPost($rep,'submit',$data)['status']===422);
$db->exec("UPDATE portal_years SET club_close_at=UTC_TIMESTAMP()+INTERVAL 30 DAY WHERE year=$nowYear");
$r=clubPost($rep,'submit',$data);$cy=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);$cyId=(int)$cy['id'];
check('代表が自クラブ年度登録',$r['status']===201 && $cy['display_code']==='100003' && $cy['status']==='pending');
check('口座の平文・暗号文をHTTPへ返さない',!str_contains($r['body'],'0123456') && !array_key_exists('bank_details_cipher',$cy));
$cipher=$db->query("SELECT bank_details_cipher FROM portal_club_years WHERE id=$cyId")->fetchColumn();
check('口座を認証付き暗号で保管',str_starts_with($cipher,'gcm1:') && !str_contains($cipher,'0123456'));
$replay=clubPost($rep,'submit',$data);
check('年度登録再送は同じ一件',$replay['status']===200 && (int)json_decode($replay['body'],true)['id']===$cyId);
check('同一キーの口座改変を拒否',clubPost($rep,'submit',array_replace($data,['bank_details'=>'別の架空口座']))['status']===409);
check('年度内二重登録拒否',clubPost($rep,'submit',array_replace($data,['request_key'=>bin2hex(random_bytes(24))]))['status']===409);
check('財務単独はクラブ年度承認不可',clubPost($finance,'approve',['id'=>$cyId])['status']===403);
check('代表は自己クラブ年度承認不可',clubPost($rep,'approve',['id'=>$cyId])['status']===403);
check('未入金の事務局承認を拒否',clubPost($office,'approve',['id'=>$cyId])['status']===409);
check('年会費マスター未設定で請求停止',clubPost($rep,'issue',['id'=>$cyId])['status']===422);
// 12,000/8,000円は検証専用の架空料金で、運営決定金額ではない。
$db->exec("INSERT INTO portal_fee_rules(year,purpose_code,member_type,amount_yen,charge_unit,valid_from,valid_until,enabled)
    VALUES($nowYear,'club_fee','club_1',12000,'year',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 30 DAY,1),
    ($next,'club_fee','club_2',8000,'year',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 900 DAY,1);
    UPDATE portal_bank_accounts SET enabled=0 WHERE purpose_code='club_fee'");
check('年会費口座未設定で請求停止',clubPost($rep,'issue',['id'=>$cyId])['status']===422);
$db->exec("INSERT INTO portal_bank_accounts(purpose_code,display_name,details_cipher,payment_url,enabled,updated_by)
    VALUES('club_fee','架空クラブ入金口座','club-invoice-fixture','https://example.invalid/club-pay',1,1)");
$r=clubPost($rep,'issue',['id'=>$cyId]);$i=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);$invoice=(int)$i['id'];
check('年度区分マスターからクラブ年会費を発行',$r['status']===200 && (int)$i['amount_yen']===12000 && (int)$i['payer_club_year_id']===$cyId);
check('クラブ請求再送は同じ一件',(int)json_decode(clubPost($rep,'issue',['id'=>$cyId])['body'],true)['id']===$invoice);
$db->exec("UPDATE portal_fee_rules SET amount_yen=13000 WHERE purpose_code='club_fee' AND year=$nowYear");
check('発行後の年会費変更は既存請求に影響しない',(int)billState($rep,$invoice)['amount_yen']===12000);
check('個人会費の支払でクラブを有効扱いしない',!clubRead($rep,$cyId)['eligibility']['paid']);
check('代表の自己入金確認を拒否',billPost($rep,'confirm',$invoice,['amount'=>12000,'reference'=>'CLUB-SELF'])['status']===403);
check('クラブ年会費一部入金',billPost($finance,'confirm',$invoice,['amount'=>3000,'reference'=>'CLUB-PART'])['status']===200);
check('不足入金では年度承認不可',clubPost($office,'approve',['id'=>$cyId])['status']===409);
$r=billPost($finance,'confirm',$invoice,['amount'=>9000,'reference'=>'CLUB-FULL']);$p=json_decode($r['body'],true);$payment=(int)$p['payment_id'];
check('満額入金だけではクラブ未承認',$r['status']===200 && clubRead($rep,$cyId)['eligibility']['paid'] && !clubRead($rep,$cyId)['eligibility']['active']);
check('事務局が入金確認後に承認',clubPost($office,'approve',['id'=>$cyId])['status']===200 && clubRead($rep,$cyId)['eligibility']['active']);
$audit=(int)$db->query("SELECT COUNT(*) FROM portal_audit_events WHERE action='CLUB_APPROVED'")->fetchColumn();
check('承認再送で監査・通知を重複しない',clubPost($office,'approve',['id'=>$cyId])['status']===200 && (int)$db->query("SELECT COUNT(*) FROM portal_audit_events WHERE action='CLUB_APPROVED'")->fetchColumn()===$audit);
// 個人会費は個人の請求として別管理し、クラブの入金だけでは本人資格は付与しない。
$entrant=new Browser();$entrant->login(9);
$m=json_decode(submitMember($entrant,['member_type'=>'general','year'=>$nowYear,'club_year_id'=>$cyId,'identifier_id'=>102])['body'],true);$member=(int)$m['id'];
$mi=json_decode(billPost($entrant,'issue',$member)['body'],true);$memberInvoice=(int)$mi['id'];
check('クラブ入金だけで個人会費は完了しない',!billState($entrant,$memberInvoice)['eligibility']['paid']);
check('自クラブ代表が所属承認',billPost($rep,'approve',$member)['status']===200);
check('個人満額入金でクラブと合わせて資格有効',billPost($office,'confirm',$memberInvoice,['amount'=>2500,'reference'=>'CLUB-MEMBER'])['status']===200 && billState($entrant,$memberInvoice)['eligibility']['active']);
$allocation=(int)$db->query("SELECT id FROM portal_allocations WHERE payment_id=$payment AND amount_yen>0")->fetchColumn();
check('クラブ入金訂正が成功',billPost($finance,'reverse',$allocation,['reason'=>'架空クラブ入金の確認訂正'])['status']===200);
check('クラブ不足で個人資格も直ちに無効',!clubRead($rep,$cyId)['eligibility']['active'] && !billState($entrant,$memberInvoice)['eligibility']['active']);
check('個人会員資格の保存状態も無効へ更新',$db->query("SELECT status FROM portal_memberships WHERE id=$member")->fetchColumn()==='pending');
check('個人の支払済み記録は維持',billState($entrant,$memberInvoice)['eligibility']['paid']);
check('クラブ再充当でクラブ・個人資格を復旧',billPost($office,'reallocate',$invoice,['payment'=>$payment,'amount'=>9000])['status']===200 && clubRead($rep,$cyId)['eligibility']['active'] && billState($entrant,$memberInvoice)['eligibility']['active']);
$fresh=clubRead($rep,$cyId);$update=$fields+['id'=>$cyId,'row_version'=>$fresh['row_version']];$update['bank_details']='';$update['phone']='000-1111';
check('承認後も連絡先訂正可能',clubPost($rep,'update',$update)['status']===200 && clubRead($rep,$cyId)['phone']==='000-1111');
check('競合した連絡先訂正を拒否',clubPost($rep,'update',$update)['status']===409);
$update['row_version']=clubRead($rep,$cyId)['row_version'];$update['category_digit']='2';
check('年度内区分変更を拒否',clubPost($rep,'update',$update)['status']===409);
dbReject($db,'DB経由の年度内クラブコード変更拒否',"UPDATE portal_club_years SET category_digit='2',display_code='200003' WHERE id=$cyId",1644);
$r=$rep->request('/manage/clubs.php?format=json&club_id=3&year='.$next);$previous=json_decode($r['body'],true)['previous'];
check('翌年受付へ連絡先を継続',$previous['phone']==='000-1111' && $previous['has_bank_details']);
$renew=$fields+['club_id'=>3,'year'=>$next,'category_digit'=>'2','request_key'=>bin2hex(random_bytes(24)),'carry_bank'=>'1'];$renew['bank_details']='';
$r=clubPost($rep,'submit',$renew);$new=json_decode($r['body'],true);$newId=(int)$new['id'];
check('次年度のみ区分を変更して登録',$r['status']===201 && $new['display_code']==='200003');
check('継続口座を暗号文のまま保持',$db->query("SELECT bank_details_cipher FROM portal_club_years WHERE id=$newId")->fetchColumn()===$cipher);
$ni=json_decode(clubPost($rep,'issue',['id'=>$newId])['body'],true);$nextInvoice=(int)$ni['id'];
check('年度・区分ごとの料金を採用',(int)$ni['amount_yen']===8000);
check('次年度年会費を入金確認',billPost($office,'confirm',$nextInvoice,['amount'=>8000,'reference'=>'CLUB-NEXT'])['status']===200);
check('次年度年度登録を先行承認',clubPost($office,'approve',['id'=>$newId])['status']===200);
$ns=clubRead($rep,$newId)['eligibility'];
check('先行承認はreadyだが年度前は無効',$ns['ready'] && !$ns['active']);
$ce=new \Jmrc\Club\ClubEligibility($db);
check('クラブ年度開始で有効',$ce->evaluate($newId,new DateTimeImmutable($ns['valid_from'].' UTC'))['active']);
check('クラブ年度終了で無効',!$ce->evaluate($newId,new DateTimeImmutable($ns['valid_until'].' UTC'))['active']);
check('他クラブ代表から年度情報を保護',$admin->request('/manage/clubs.php?format=json&id='.$cyId)['status']===403);
check('クラブ請求も他人から保護',$admin->request('/manage/billing.php?format=json&invoice='.$invoice)['status']===403);
$db->exec("UPDATE portal_user_role_grants SET status='revoked' WHERE user_id=2 AND scope_club_id=3");
check('代表grant取消を即時反映',$rep->request('/manage/clubs.php?format=json&id='.$cyId)['status']===403);
$db->exec("UPDATE portal_user_role_grants SET status='active' WHERE user_id=2 AND scope_club_id=3");
check('財務画面にクラブ会費も表示',str_contains($finance->request('/manage/billing.php')['body'],$ni['invoice_no']));
check('登録・承認・入金を監査',(int)$db->query("SELECT COUNT(DISTINCT action) FROM portal_audit_events WHERE entity_type='club_year' AND action IN ('CLUB_REGISTERED','CLUB_INVOICED','CLUB_PAYMENT_CONFIRMED','CLUB_APPROVED','CLUB_CONTACT_UPDATED','CLUB_PAYMENT_REVERSED','CLUB_PAYMENT_REALLOCATED')")->fetchColumn()===7);
// statusが古い場合でも、実際の入金を正本として読み直す。
$db->exec("UPDATE portal_payments SET status='void' WHERE id=$payment");
check('クラブstatusが承認済みでも入金失効で個人資格無効',!billState($entrant,$memberInvoice)['eligibility']['active']);
$db->exec("UPDATE portal_payments SET status='confirmed' WHERE id=$payment; UPDATE portal_club_categories SET enabled=0 WHERE category_digit='1'");
check('クラブ区分停止が個人資格へ即時反映',!billState($entrant,$memberInvoice)['eligibility']['active']);
$db->exec("UPDATE portal_club_categories SET enabled=1 WHERE category_digit='1';
    INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,status,valid_from,granted_by,approved_by)
    VALUES(2,2,'club',4,'active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1)");
// 監査/通知の失敗時に年度登録だけを残さない。
$failureData=array_replace($data,['club_id'=>4,'request_key'=>bin2hex(random_bytes(24))]);$before=memberCounts($db);
$count=(int)$db->query('SELECT COUNT(*) FROM portal_club_years')->fetchColumn();
$db->exec('RENAME TABLE portal_outbox TO club_unavailable_outbox');
check('クラブ通知予約障害は503',clubPost($rep,'submit',$failureData)['status']===503);
$db->exec('RENAME TABLE club_unavailable_outbox TO portal_outbox');
check('年度登録・監査は一括ロールバック',memberCounts($db)===$before && (int)$db->query('SELECT COUNT(*) FROM portal_club_years')->fetchColumn()===$count);
/** 独立プロセスを同時開始し、PHPセッションの偶然の直列化を排除する。 */
function clubRace(array $tasks): array
{
    $when=microtime(true)+0.5;$workers=[];$rows=[];
    foreach($tasks as $task) {
        $pipes=[];$p=proc_open([PHP_BINARY,'/srv/tests/concurrent_club.php',(string)$when,...array_map('strval',$task)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];
    }
    foreach($workers as [$p,$pipes]) {
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check('クラブ同時処理ワーカー正常終了 '.count($rows),proc_close($p)===0 && $error==='');$rows[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }return $rows;
}
$race=clubRace([['submit',4,$nowYear],['submit',4,$nowYear]]);$statuses=array_column($race,'status');sort($statuses);
check('クラブ同年度の同時登録は一件のみ',$statuses===[200,409]);
$raceId=(int)$db->query("SELECT id FROM portal_club_years WHERE club_id=4 AND year=$nowYear")->fetchColumn();
check('同時登録の敗者に孤児申請なし',(int)$db->query("SELECT COUNT(*) FROM portal_applications a LEFT JOIN portal_club_years cy ON cy.application_id=a.id WHERE a.kind='club' AND a.applicant_user_id=2 AND cy.id IS NULL")->fetchColumn()===0);
// 移行時にapprovedだけをコピーしても入金証跡なしでは有効としない。
$raceApp=(int)$db->query("SELECT application_id FROM portal_club_years WHERE id=$raceId")->fetchColumn();
$db->exec("UPDATE portal_club_years SET status='approved' WHERE id=$raceId; UPDATE portal_applications SET status='approved' WHERE id=$raceApp");
check('入金証跡のない旧承認だけでは有効化しない',!clubRead($rep,$raceId)['eligibility']['active']);
check('証跡のない既存年度登録へ自動再請求しない',clubPost($rep,'issue',['id'=>$raceId])['status']===409);
$db->exec("UPDATE portal_club_years SET status='pending' WHERE id=$raceId; UPDATE portal_applications SET status='submitted' WHERE id=$raceApp");
$race=clubRace([['issue',$raceId],['issue',$raceId]]);
check('クラブ年会費の同時発行は同じ請求',$race[0]['status']===200 && $race[1]['status']===200 && $race[0]['id']===$race[1]['id']);$raceInvoice=(int)$race[0]['id'];
check('別クラブの入金を再充当できない',billPost($office,'reallocate',$raceInvoice,['payment'=>$payment,'amount'=>100])['status']===409);
$r=billPost($finance,'confirm',$raceInvoice,['amount'=>13000,'reference'=>'CLUB-RACE']);$racePayment=(int)json_decode($r['body'],true)['payment_id'];
$raceAllocation=(int)$db->query("SELECT id FROM portal_allocations WHERE payment_id=$racePayment AND amount_yen>0")->fetchColumn();
$race=clubRace([['approve',$raceId],['reverse',$raceAllocation]]);
check('年度承認と入金訂正の競合は許容状態に完了',in_array($race[0]['status'],[200,409],true) && $race[1]['status']===200);
check('年度承認と訂正が競合しても不足資格を有効にしない',!clubRead($rep,$raceId)['eligibility']['paid'] && !clubRead($rep,$raceId)['eligibility']['active'] && $db->query("SELECT status FROM portal_club_years WHERE id=$raceId")->fetchColumn()==='pending');
