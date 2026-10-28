<?php
// Path: tests/billing/billing_http.php
// 機能: 架空データだけで会費・承認・入金訂正・期間境界・旧entry拒否をHTTPS検証する。
declare(strict_types=1);

/** 本番用のPOST入口を使い、本人や権限をHTTPセッションから解決させる。 */
function billPost(Browser $browser,string $action,int $id,array $values=[]): array
{
    return $browser->request('/manage/billing.php?format=json',$values+['action'=>$action,'id'=>$id,'_csrf'=>identity($browser)['csrf'],'operation'=>bin2hex(random_bytes(24))]);
}
function billState(Browser $browser,int $invoice): array
{
    $r=$browser->request('/manage/billing.php?format=json&invoice='.$invoice);
    if ($r['status']!==200) { throw new RuntimeException('invoice read failed: '.$r['body']); }
    return json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
}
require_once '/srv/tests/club_fixture.php';

$nowYear=(int)gmdate('Y');$future=$nowYear+1;
$db->exec("INSERT INTO portal_years(year,valid_from,valid_until,source_mode,policy_version,member_self_open_at,club_open_at,club_close_at,staff_open_at)
    VALUES($nowYear,UTC_TIMESTAMP()-INTERVAL 30 DAY,UTC_TIMESTAMP()+INTERVAL 30 DAY,'portal','billing-lab',UTC_TIMESTAMP()-INTERVAL 30 DAY,UTC_TIMESTAMP()-INTERVAL 30 DAY,UTC_TIMESTAMP()+INTERVAL 30 DAY,UTC_TIMESTAMP()-INTERVAL 30 DAY)");
$db->exec("INSERT INTO portal_applications(id,kind,applicant_user_id,year,status,request_key) VALUES(900,'club',2,$nowYear,'approved','billing-club');
    INSERT INTO portal_club_years(id,club_id,year,category_digit,display_code,application_id,status,rep_name,contact_name,postcode,address,phone,email)
    VALUES(900,1,$nowYear,'1','100001',900,'approved','架空','架空','0000000','架空','000','club@example.invalid')");
seedClubPaid($db,900);
$member=new Browser();$member->login(3);$host=new Browser();$host->login(2);$finance=new Browser();$finance->login(7);$admin=new Browser();$admin->login(1);
$s=submitMember($member,['member_type'=>'general','year'=>$nowYear,'club_year_id'=>900,'identifier_id'=>101]);
check('請求用General申請',$s['status']===201);
$mid=(int)json_decode($s['body'],true)['id'];
check('会費マスター未設定は請求停止',billPost($member,'issue',$mid)['status']===422);
$db->exec("INSERT INTO portal_fee_rules(year,purpose_code,member_type,amount_yen,charge_unit,valid_from,valid_until,enabled)
    VALUES($nowYear,'membership_fee','general',2000,'year',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 60 DAY,1),
    ($future,'membership_fee','general',2000,'year',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 900 DAY,1),
    ($future,'membership_fee','temporary',500,'event',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 900 DAY,1)");
check('口座未設定は請求停止',billPost($member,'issue',$mid)['status']===422);
$db->exec("INSERT INTO portal_bank_accounts(purpose_code,display_name,details_cipher,payment_url,enabled,updated_by)
    VALUES('membership_fee','架空ゆうちょ銀行','synthetic-account','https://example.invalid/pay',1,1)");
check('旧管理者だけでは他人へ請求不可',billPost($admin,'issue',$mid)['status']===403);
$r=billPost($member,'issue',$mid);$i=json_decode($r['body'],true);$iid=(int)($i['id']??0);
check('本人の年度会費2000円請求',$r['status']===200 && (int)$i['amount_yen']===2000);
check('請求レスポンスに暗号文なし',!str_contains($r['body'],'synthetic-account') && !array_key_exists('account_snapshot_cipher',$i));
check('請求再送は同一ID',(int)json_decode(billPost($member,'issue',$mid)['body'],true)['id']===$iid);
$db->exec("UPDATE portal_fee_rules SET amount_yen=2500 WHERE year=$nowYear");
check('発行済み会費はマスター変更でも2000円',(int)billState($member,$iid)['amount_yen']===2000);
check('本人による入金自己承認を拒否',billPost($member,'confirm',$iid,['amount'=>2000,'reference'=>'SELF'])['status']===403);
check('CSRFなし入金拒否',$finance->request('/manage/billing.php?format=json',['action'=>'confirm','id'=>$iid,'amount'=>2000,'reference'=>'CSRF'])['status']===400);
$key=bin2hex(random_bytes(24));
$partial=billPost($finance,'confirm',$iid,['amount'=>700,'reference'=>'BANK-1','operation'=>$key]);
check('一部入金を確認',$partial['status']===200 && (int)json_decode($partial['body'],true)['balance_yen']===1300);
check('一部入金の再送は重複なし',billPost($finance,'confirm',$iid,['amount'=>700,'reference'=>'BANK-1','operation'=>$key])['status']===200 && (int)$db->query("SELECT COUNT(*) FROM portal_payments WHERE source_key='BANK-1'")->fetchColumn()===1);
check('同じ銀行取引の二重登録拒否',billPost($finance,'confirm',$iid,['amount'=>700,'reference'=>'BANK-1'])['status']===409);
check('不足入金では未払い・無効',!billState($member,$iid)['eligibility']['paid'] && !billState($member,$iid)['eligibility']['active']);
check('他クラブ承認権限のない本人を拒否',billPost($admin,'approve',$mid)['status']===403);
check('所属クラブ代表がGeneral承認',billPost($host,'approve',$mid)['status']===200);
check('承認だけでは有効にならない',!billState($member,$iid)['eligibility']['active']);
$full=billPost($finance,'confirm',$iid,['amount'=>1500,'reference'=>'BANK-2']);$f=json_decode($full['body'],true);
check('過入金は未充当200円',$full['status']===200 && (int)$f['unallocated_yen']===200);
check('承認と満額入金で有効',billState($member,$iid)['eligibility']['active']);
check('有効状態を同じ取引で保存',$db->query("SELECT status FROM portal_memberships WHERE id=$mid")->fetchColumn()==='active');
check('他人の請求参照拒否',$admin->request('/manage/billing.php?format=json&invoice='.$iid)['status']===403);
$allocation=(int)$db->query("SELECT id FROM portal_allocations WHERE payment_id=".(int)$f['payment_id'])->fetchColumn();
check('本人による入金訂正拒否',billPost($member,'reverse',$allocation,['reason'=>'訂正'])['status']===403);
check('理由なしの訂正拒否',billPost($finance,'reverse',$allocation)['status']===422);
$reverseKey=bin2hex(random_bytes(24));
check('財務が入金確認後に訂正',billPost($finance,'reverse',$allocation,['reason'=>'架空照合の修正','operation'=>$reverseKey])['status']===200);
check('訂正で資格を無効に戻す',!billState($member,$iid)['eligibility']['active'] && $db->query("SELECT status FROM portal_memberships WHERE id=$mid")->fetchColumn()==='pending');
check('訂正再送は同じ結果',billPost($finance,'reverse',$allocation,['reason'=>'架空照合の修正','operation'=>$reverseKey])['status']===200);
check('同じ元行の重複逆仕訳拒否',billPost($finance,'reverse',$allocation,['reason'=>'重複'])['status']===409);
check('未充当を超える再充当拒否',billPost($finance,'reallocate',$iid,['payment'=>$f['payment_id'],'amount'=>1501])['status']===422);
check('訂正後に同じ入金を再充当',billPost($finance,'reallocate',$iid,['payment'=>$f['payment_id'],'amount'=>1300])['status']===200 && billState($member,$iid)['eligibility']['active']);
// 支払済みとは別に、期限・資格停止・本人/クラブの最新状態を参照時に確認。
$db->exec("UPDATE portal_account_controls SET status='blocked' WHERE user_id=3");
check('会員アカウント停止で資格無効',!billState($finance,$iid)['eligibility']['active']);
$db->exec("UPDATE portal_account_controls SET status='active' WHERE user_id=3; UPDATE portal_identifiers SET expires_on=CURRENT_DATE()-INTERVAL 1 DAY WHERE id=101");
check('ライセンス期限切れで資格無効',!billState($finance,$iid)['eligibility']['active']);
$db->exec("UPDATE portal_identifiers SET expires_on=NULL WHERE id=101; UPDATE portal_club_years SET status='returned' WHERE id=900");
check('クラブ承認取消で資格無効',!billState($finance,$iid)['eligibility']['active']);
$db->exec("UPDATE portal_club_years SET status='approved' WHERE id=900");
require_once '/srv/auth/src/Billing/Eligibility.php';require_once '/srv/auth/src/Auth/HttpFailure.php';
require_once '/srv/auth/src/Club/ClubEligibility.php';
$elig=new \Jmrc\Billing\Eligibility($db);$period=$elig->evaluate($mid);
check('期間開始の直前は無効',!$elig->evaluate($mid,new DateTimeImmutable($period['valid_from'].' UTC -1 microsecond'))['active']);
check('期間開始の瞬間は有効',$elig->evaluate($mid,new DateTimeImmutable($period['valid_from'].' UTC'))['active']);
check('期間終了の瞬間は無効',!$elig->evaluate($mid,new DateTimeImmutable($period['valid_until'].' UTC'))['active']);
$futureId=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=3 AND year=$future AND member_type='general'")->fetchColumn();
$fi=json_decode(billPost($finance,'issue',$futureId)['body'],true);$fid=(int)$fi['id'];
check('先に満額入金可能',billPost($finance,'confirm',$fid,['amount'=>2000,'reference'=>'FUTURE'])['status']===200 && !billState($finance,$fid)['eligibility']['active']);
check('事務局の所属承認',billPost($finance,'approve',$futureId)['status']===200);
check('将来年度は承認入金済みでも開始前は無効',billState($finance,$fid)['eligibility']['ready'] && !billState($finance,$fid)['eligibility']['active']);
// Temporaryは既存主催承認済みの申請を利用し、開催日の半開区間だけ有効。
$temp=(int)$db->query("SELECT m.id FROM portal_memberships m JOIN portal_applications a ON a.id=m.application_id WHERE m.member_type='temporary' AND m.user_id=3 AND a.status='approved' LIMIT 1")->fetchColumn();
$ti=json_decode(billPost($finance,'issue',$temp)['body'],true);$tid=(int)$ti['id'];
check('Temporaryイベント会費500円',(int)$ti['amount_yen']===500);
check('Temporary満額入金',billPost($finance,'confirm',$tid,['amount'=>500,'reference'=>'TEMP'])['status']===200);
$ts=billState($finance,$tid)['eligibility'];
check('将来Temporaryは開始前に無効',!$ts['active'] && $ts['ready']);
check('Temporary開催日開始で有効',$elig->evaluate($temp,new DateTimeImmutable($ts['valid_from'].' UTC'))['active']);
check('Temporary開催日終了で無効',!$elig->evaluate($temp,new DateTimeImmutable($ts['valid_until'].' UTC'))['active']);
// 同じPHPセッションの直列化に依存せず、独立した接続で一意請求と入金充当を保証。
$raceMember=(int)$db->query("SELECT id FROM portal_memberships WHERE user_id=9 AND year=$future AND member_type='general'")->fetchColumn();
function billingRace(string $action,int $id): array
{
    $when=microtime(true)+0.5;$workers=[];$rows=[];
    foreach(['A','B'] as $suffix) {
        $pipes=[];$p=proc_open([PHP_BINARY,'/srv/tests/concurrent_billing.php',(string)$when,$action,(string)$id,'RACE-'.$suffix,bin2hex(random_bytes(24))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];
    }
    foreach($workers as [$p,$pipes]) {
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check('同時会計ワーカー正常終了 '.$action.count($rows),proc_close($p)===0 && $error==='');$rows[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    return $rows;
}
$race=billingRace('issue',$raceMember);
check('同時請求は両方同じ一件を返す',$race[0]['status']===200 && $race[1]['status']===200 && $race[0]['id']===$race[1]['id']);
check('会費一意claimは一件',(int)$db->query("SELECT COUNT(*) FROM portal_membership_charges WHERE membership_id=$raceMember")->fetchColumn()===1);
$race=billingRace('confirm',(int)$race[0]['id']);$statuses=array_column($race,'status');sort($statuses);
check('同時満額入金でも二重充当しない',$statuses===[200,409]);
// 通知予約の障害時にも請求だけを残さない。
$unbilled=(int)$db->query("SELECT m.id FROM portal_memberships m LEFT JOIN portal_membership_charges ch ON ch.membership_id=m.id WHERE m.user_id=3 AND m.member_type='temporary' AND ch.membership_id IS NULL LIMIT 1")->fetchColumn();
$beforeInvoice=(int)$db->query('SELECT COUNT(*) FROM portal_invoices')->fetchColumn();
$db->exec('RENAME TABLE portal_outbox TO billing_unavailable_outbox');
check('会計通知予約の障害は503',billPost($finance,'issue',$unbilled)['status']===503);
$db->exec('RENAME TABLE billing_unavailable_outbox TO portal_outbox');
check('会計通知障害でも請求を残さない',(int)$db->query('SELECT COUNT(*) FROM portal_invoices')->fetchColumn()===$beforeInvoice);
$badRep=new Browser();$badRep->login(8);
check('代表global grantで所属承認不可',billPost($badRep,'approve',$raceMember)['status']===403);
check('本人画面に請求導線',$member->request('/manage/billing.php')['status']===200);
check('財務画面に入金確認導線',str_contains($finance->request('/manage/billing.php')['body'],'入金確認'));
// 事務局単独と財務単独のgrantで双方の入金確認・訂正を検証する。
$db->exec("UPDATE portal_user_role_grants SET status='revoked' WHERE user_id=7 AND role_id=3;
    INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by)
    VALUES(13,3,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1)");
$onlyFinance=new Browser();$onlyFinance->login(13);
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$from=(new DateTimeImmutable($today.' 00:30:00',new DateTimeZone('Asia/Tokyo')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$until=(new DateTimeImmutable($today.' 23:59:00',new DateTimeZone('Asia/Tokyo')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$db->exec("INSERT INTO portal_events(id,organizer_club_year_id,year,title,starts_at,ends_at,status) VALUES(900,900,$nowYear,'架空当日','$from','$until','published');
    INSERT INTO portal_fee_rules(year,purpose_code,member_type,amount_yen,charge_unit,valid_from,valid_until,enabled)
    VALUES($nowYear,'membership_fee','temporary',500,'event',UTC_TIMESTAMP()-INTERVAL 1 DAY,UTC_TIMESTAMP()+INTERVAL 30 DAY,1)");
$entrant=new Browser();$entrant->login(10);
$t=json_decode(submitMember($entrant,['member_type'=>'temporary','event_id'=>900])['body'],true);$todayMember=(int)$t['id'];
$t=json_decode(billPost($entrant,'issue',$todayMember)['body'],true);$todayInvoice=(int)$t['id'];
check('財務単独grantで入金確認',billPost($onlyFinance,'confirm',$todayInvoice,['amount'=>500,'reference'=>'TODAY-TEMP'])['status']===200);
check('当日でも主催承認前は資格無効',!billState($entrant,$todayInvoice)['eligibility']['active']);
$approve=$host->request('/manage/temporary-approve.php?format=json',['_csrf'=>identity($host)['csrf'],'membership_id'=>$todayMember]);
check('満額入金後の主催承認で当日Temporary有効',$approve['status']===200 && billState($entrant,$todayInvoice)['eligibility']['active']);
$al=$db->query('SELECT id,payment_id FROM portal_allocations WHERE invoice_line_id='.(int)$t['invoice_line_id'])->fetch(PDO::FETCH_ASSOC);
check('事務局単独grantで確定後の訂正',billPost($finance,'reverse',(int)$al['id'],['reason'=>'架空入金の会計訂正'])['status']===200 && !billState($entrant,$todayInvoice)['eligibility']['active']);
check('事務局単独grantで再充当',billPost($finance,'reallocate',$todayInvoice,['amount'=>500,'payment'=>$al['payment_id']])['status']===200);
check('財務単独grantでも確定後訂正',billPost($onlyFinance,'reverse',(int)$db->query('SELECT MAX(id) FROM portal_allocations WHERE invoice_line_id='.(int)$t['invoice_line_id'])->fetchColumn(),['reason'=>'財務の架空訂正'])['status']===200);
check('事務局単独grantで入金確認',billPost($finance,'confirm',$todayInvoice,['amount'=>500,'reference'=>'OFFICE-TEMP'])['status']===200);
$db->exec("UPDATE portal_user_role_grants SET status='revoked' WHERE user_id=13");
check('会計grant取消を即時反映',billPost($onlyFinance,'confirm',$todayInvoice,['amount'=>500,'reference'=>'REVOKED'])['status']===403);
check('数字以外を含む金額を拒否',billPost($finance,'confirm',$todayInvoice,['amount'=>'500abc','reference'=>'INVALID'])['status']===422);
check('配列の取引番号を拒否',billPost($finance,'confirm',$todayInvoice,['amount'=>500,'reference'=>[]])['status']===422);
check('入金額の超過充当なし',(int)$db->query('SELECT COUNT(*) FROM (SELECT p.id FROM portal_payments p JOIN portal_allocations a ON a.payment_id=p.id GROUP BY p.id,p.amount_yen HAVING SUM(a.amount_yen)>p.amount_yen OR SUM(a.amount_yen)<0) invalid')->fetchColumn()===0);
// 旧会費の直接INSERT/UPDATEと実際のentry POST入口を検証。
$db->exec("INSERT INTO jmrc_events(id,event_type,event_format,event_date,venue,entry_start_date,entry_end_date,max_entrants,organizer_code,organizer_short)
    VALUES(900,'gymkhana','lab','$future-04-02','lab','$nowYear-01-01','$future-04-01',100,'100001','LAB')");
// 必須の既存エントリー項目をスキーマから架空値で満たす。実データは使わない。
$columns=$db->query('SHOW COLUMNS FROM jmrc_entries')->fetchAll(PDO::FETCH_ASSOC);$names=[];$values=[];
foreach($columns as $c) {
    if ($c['Extra']==='auto_increment' || $c['Default']!==null || $c['Null']==='YES') { continue; }
    $names[]='`'.$c['Field'].'`';
    $values[]=match($c['Field']) {'event_id'=>900,'user_id'=>14,default=>str_starts_with($c['Type'],'enum(')?explode("'",$c['Type'])[1]:(str_contains($c['Type'],'int')?1:'lab')};
}
$q=$db->prepare('INSERT INTO jmrc_entries('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');$q->execute($values);$entry=(int)$db->lastInsertId();
dbReject($db,'旧DB直接会費追加を拒否',"INSERT INTO jmrc_entry_fees(entry_id,item_name,amount) VALUES($entry,'JMRC個人入会金',2000)",1644);
$db->exec("INSERT INTO jmrc_entry_fees(entry_id,item_name,amount) VALUES($entry,'JMRC共済',1000)");$legacyLine=(int)$db->lastInsertId();
dbReject($db,'旧明細名変更による会費追加拒否',"UPDATE jmrc_entry_fees SET item_name='JMRC個人入会金' WHERE id=$legacyLine",1644);
check('旧共済明細は維持',(int)$db->query("SELECT amount FROM jmrc_entry_fees WHERE id=$legacyLine")->fetchColumn()===1000);
check('旧entrant実POSTで二重会費拒否',$member->request('/entry/entrant/entries.php?action=create&event_id=900',['jmrc_join_member'=>'1','_csrf'=>identity($member)['csrf']])['status']===409);
check('旧管理実POSTは対象entry年度で拒否',$admin->request('/entry/admin/entry_payment_check.php?id=1',['action'=>'add_admission_fee','entry_id'=>$entry,'_csrf'=>identity($admin)['csrf']])['status']===409);
$db->exec("UPDATE portal_years SET source_mode='legacy' WHERE year=$future; INSERT INTO jmrc_entry_fees(entry_id,item_name,amount) VALUES($entry,'JMRC個人入会金',2000); UPDATE portal_years SET source_mode='portal' WHERE year=$future");
$other=new Browser();$other->login(14);
$tr=submitMember($other,['member_type'=>'temporary','event_id'=>101]);$otherMember=(int)json_decode($tr['body'],true)['id'];
check('既存entry会費があればポータル追加請求停止',billPost($other,'issue',$otherMember)['status']===409);
require_once '/srv/web/entry/common/portal_billing.php';
$legacy=['is_member_paid'=>0,'is_mutual_aid_paid'=>1];
check('年度支払判定はポータル正本・共済は維持',portal_annual_payment_status($db,3,$future,$legacy)===['is_member_paid'=>1,'is_mutual_aid_paid'=>1]);
check('旧年度の判定を維持',portal_annual_payment_status($db,3,$nowYear-1,$legacy)===$legacy);
check('請求・入金・承認の監査記録',(int)$db->query("SELECT COUNT(DISTINCT action) FROM portal_audit_events WHERE action IN ('MEMBERSHIP_INVOICED','MEMBERSHIP_PAYMENT_CONFIRMED','MEMBERSHIP_PAYMENT_REVERSED','MEMBERSHIP_PAYMENT_REALLOCATED','GENERAL_AFFILIATION_APPROVED')")->fetchColumn()===5);
