<?php
// Path: tests/membership/membership_http.php
// 機能: 共通HTTPS環境でGeneral条件・マスター・Temporary日付/承認/競合を検証する。
// tests/auth/run_http.phpからCLI includeする。Webへは配置しない。
declare(strict_types=1);

/** 本番用HTTP入口へ送る。申請者IDや参加日を渡してもサーバー側で上書き/算出する。 */
function submitMember(Browser $browser,array $fields): array
{
    $fields['_csrf']=identity($browser)['csrf'];
    $fields['request_key']=$fields['request_key']??bin2hex(random_bytes(24));
    return $browser->request('/manage/memberships.php?format=json',$fields);
}

/** 不正入力時は申請・会員・監査・outboxが一括で増えないことも確認する。 */
function memberCounts(PDO $db): array
{
    $out=[];
    foreach (['portal_applications','portal_memberships','portal_affiliations','portal_audit_events','portal_outbox'] as $table) {
        $out[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $out;
}

/** 隔離DBへ直接書いた場合もDBが不正を拒否することを、エラー番号で確認する。 */
function dbReject(PDO $db,string $name,string $sql,int $code): void
{
    $caught=false;
    try { $db->exec($sql); }
    catch (PDOException $failure) { $caught=(int)$failure->errorInfo[1]===$code; }
    check($name,$caught);
}

$year=(int)gmdate('Y')+1;
$member=new Browser(); $member->login(3);
$official=new Browser(); $official->login(9);
$host=new Browser(); $host->login(2);
$office=new Browser(); $office->login(7);
$admin=new Browser(); $admin->login(1);
$allCategories=$db->query('SELECT category_digit,name FROM portal_club_categories WHERE enabled=1 ORDER BY category_digit')->fetchAll(PDO::FETCH_KEY_PAIR);
check('クラブ区分4値を正式名で登録',$allCategories===[1=>'公認クラブ',2=>'加盟クラブ',3=>'準加盟クラブ',6=>'加盟団体']);
$result=$member->request('/manage/memberships.php?format=json');
check('マスターを認証済み本人に表示',$result['status']===200 && count(json_decode($result['body'],true)['categories'])===4);
dbReject($db,'未確定の区分4を拒否',"INSERT INTO portal_club_categories(category_digit,name) VALUES('4','未確定')",4025);
dbReject($db,'クラブ年度内コード変更拒否',"UPDATE portal_club_years SET category_digit='2',display_code='200001' WHERE id=101",1644);
$db->exec("UPDATE portal_club_years SET phone='111' WHERE id=101");
check('コード固定後も連絡先訂正可',$db->query('SELECT phone FROM portal_club_years WHERE id=101')->fetchColumn()==='111');
$base=['member_type'=>'general','year'=>$year,'club_year_id'=>101];
foreach ([0=>'未選択',103=>'運転免許証のみ',104=>'他人の番号',105=>'空の番号',106=>'期限切れ',107=>'分類不明の番号'] as $id=>$label) {
    $before=memberCounts($db);
    check('General '.$label.'を拒否',submitMember($member,$base+['identifier_id'=>$id])['status']===422);
    check('General '.$label.'失敗は原子的',memberCounts($db)===$before);
}
check('未承認クラブを拒否',submitMember($member,['member_type'=>'general','year'=>$year,'club_year_id'=>999,'identifier_id'=>101])['status']===422);
check('未設定年度を拒否',submitMember($member,['member_type'=>'general','year'=>2099,'club_year_id'=>101,'identifier_id'=>101])['status']===422);
$key=bin2hex(random_bytes(24));
$result=submitMember($member,$base+['identifier_id'=>101,'request_key'=>$key,'user_id'=>9]);
check('General競技ライセンスで受付',$result['status']===201);
$general=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
check('General申請者はPOSTではなくsession',(int)$db->query('SELECT user_id FROM portal_memberships WHERE id='.(int)$general['id'])->fetchColumn()===3);
check('Generalは入金前pending',$general['status']==='pending' && $general['application_status']==='submitted');
$before=memberCounts($db);
check('General再送は既存を返す',submitMember($member,$base+['identifier_id'=>101,'request_key'=>$key])['status']===200);
check('General再送で請求/通知予約を重複させない',memberCounts($db)===$before);
check('General同年度別キー申請を拒否',submitMember($member,$base+['identifier_id'=>101])['status']===409);
check('General同一キー内容変更拒否',submitMember($member,$base+['identifier_id'=>103,'request_key'=>$key])['status']===409);
check('別人の申請キー再利用拒否',submitMember($official,$base+['identifier_id'=>102,'request_key'=>$key])['status']===409);
check('General審判員ライセンスで受付',submitMember($official,$base+['identifier_id'=>102])['status']===201);
check('General翌年も別申請可',submitMember($member,['member_type'=>'general','year'=>$year+1,'club_year_id'=>103,'identifier_id'=>101])['status']===201);
$before=memberCounts($db);
check('会員申請CSRF欠落拒否',$member->request('/manage/memberships.php?format=json',$base+['identifier_id'=>101])['status']===400);
check('CSRF失敗は原子的',memberCounts($db)===$before);
check('申請キー配列を拒否',submitMember($member,$base+['identifier_id'=>101,'request_key'=>[]])['status']===422);
$temporaryKey=bin2hex(random_bytes(24));
$result=submitMember($member,['member_type'=>'temporary','event_id'=>101,'request_key'=>$temporaryKey,'participation_date'=>"$year-04-01"]);
check('TemporaryはGeneralと別の新規申請',$result['status']===201);
$temporary=json_decode($result['body'],true,512,JSON_THROW_ON_ERROR); $temporaryId=(int)$temporary['id'];
check('Temporary参加日はJSTで算出',$temporary['participation_date']==="$year-04-02");
check('Temporaryは主催者承認待ち',$temporary['application_status']==='submitted' && $temporary['status']==='pending');
$before=memberCounts($db);
$replay=submitMember($member,['member_type'=>'temporary','event_id'=>101,'request_key'=>$temporaryKey]);
check('Temporary同キー再送は既存結果',$replay['status']===200 && (int)json_decode($replay['body'],true)['id']===$temporaryId);
check('Temporary再送は記録を増やさない',memberCounts($db)===$before);
check('Temporary同日別イベント拒否',submitMember($member,['member_type'=>'temporary','event_id'=>102])['status']===409);
check('Temporary同日拒否は原子的',memberCounts($db)===$before);
check('Temporary翌日は別イベント可',submitMember($member,['member_type'=>'temporary','event_id'=>103])['status']===201);
check('Temporary複数日を保留して拒否',submitMember($member,['member_type'=>'temporary','event_id'=>104])['status']===422);
check('Temporary未公開イベント拒否',submitMember($member,['member_type'=>'temporary','event_id'=>105])['status']===422);
check('Temporary年境界のJST参加日',json_decode(submitMember($member,['member_type'=>'temporary','event_id'=>106])['body'],true)['participation_date']===($year+1).'-01-01');
check('終了がJST翌日0時のイベントを受付',submitMember($member,['member_type'=>'temporary','event_id'=>109])['status']===201);
// TemporaryからGeneralへの差額変更はなく、新しい申請を起こすだけ。自動の請求/返金はない。
check('未実装の会費請求/返金を勝手に起こさない',(int)$db->query('SELECT COUNT(*) FROM portal_invoices')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM portal_refunds')->fetchColumn()===0);
$approve=static fn(Browser $browser,int $id)=>$browser->request('/manage/temporary-approve.php?format=json',['_csrf'=>identity($browser)['csrf'],'membership_id'=>$id]);
check('旧JMRC_ADMINの暗黙承認拒否',$approve($admin,$temporaryId)['status']===403);
check('事務局global権限だけの承認拒否',$approve($office,$temporaryId)['status']===403);
$invalid=new Browser(); $invalid->login(8);
check('代表global権限だけの承認拒否',$approve($invalid,$temporaryId)['status']===403);
$hostList=json_decode($host->request('/manage/temporary-approve.php?format=json')['body'],true);
check('主催担当の一覧に申請表示',in_array($temporaryId,array_map('intval',array_column($hostList,'id')),true));
check('主催担当が申請者を識別できる',in_array('u3@example.invalid',array_column($hostList,'applicant'),true));
check('旧管理者の一覧は他クラブ情報を出さない',json_decode($admin->request('/manage/temporary-approve.php?format=json')['body'],true)===[]);
$before=memberCounts($db);
check('承認CSRF欠落拒否',$host->request('/manage/temporary-approve.php?format=json',['membership_id'=>$temporaryId])['status']===400);
check('承認CSRF欠落は原子的',memberCounts($db)===$before);
$db->exec("UPDATE portal_user_role_grants SET status='revoked' WHERE user_id=2");
check('主催者の権限取消が即時反映',$approve($host,$temporaryId)['status']===403);
$db->exec("UPDATE portal_user_role_grants SET status='active' WHERE user_id=2");
$result=$approve($host,$temporaryId);
check('主催クラブ担当者が承認',$result['status']===200 && json_decode($result['body'],true)['application_status']==='approved');
$memberRow=$db->query("SELECT * FROM portal_memberships WHERE id=$temporaryId")->fetch(PDO::FETCH_ASSOC);
check('承認後も入金前は資格pending',$memberRow['status']==='pending' && $memberRow['activated_at']===null);
check('当日資格期間をUTC排他的で保存',$memberRow['valid_from']==="$year-04-01 15:00:00.000000" && $memberRow['valid_until']==="$year-04-02 15:00:00.000000");
$before=memberCounts($db);
check('主催承認再送も成功',$approve($host,$temporaryId)['status']===200);
check('主催承認再送の通知/所属は一件',memberCounts($db)===$before);
$by=$db->query('SELECT decided_by FROM portal_applications WHERE id='.(int)$temporary['application_id'])->fetchColumn();
check('承認者を記録',(int)$by===2);
// 同日でも主催クラブが違えば、承認担当はそのイベントの主催者だけ。
$other=new Browser();$other->login(10);
$otherTemp=json_decode(submitMember($other,['member_type'=>'temporary','event_id'=>102])['body'],true);
check('別主催イベントへの承認拒否',$approve($host,(int)$otherTemp['id'])['status']===403);
check('別主催イベントの申請は一覧に出ない',!in_array((int)$otherTemp['id'],array_map('intval',array_column(json_decode($host->request('/manage/temporary-approve.php?format=json')['body'],true),'id')),true));
// DBも日付改竄・重複・免許のみ参照を拒否する。親申請はテスト用に一件作成。
$db->exec("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('temporary',3,$year,'submitted','direct-database-test')");
$app=(int)$db->lastInsertId();
dbReject($db,'DB参加日改竄を拒否',"INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id,participation_date) VALUES(3,$year,'temporary',102,$app,'$year-04-01')",1452);
dbReject($db,'DB同日別イベントを拒否',"INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id,participation_date) VALUES(3,$year,'temporary',102,$app,'$year-04-02')",1062);
dbReject($db,'DB Generalライセンス欠落拒否',"INSERT INTO portal_memberships(user_id,year,member_type,application_id) VALUES(14,$year,'general',$app)",4025);
dbReject($db,'DB運転免許証をJAFとして参照不可',"INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind) VALUES(14,$year,'general',$app,114,'jaf_competition')",1452);
dbReject($db,'申請済み開催日の無断移動拒否',"UPDATE portal_events SET starts_at=starts_at+INTERVAL 1 DAY,ends_at=ends_at+INTERVAL 1 DAY WHERE id=101",1451);
// 別PHPプロセス・別DB接続で同時申請し、実サービスのロックと一意制約を検証する。
$when=microtime(true)+1;
$workers=[];
foreach ([[107,'a'],[108,'b']] as [$event,$keyChar]) {
    $pipes=[];
    $process=proc_open([PHP_BINARY,'/srv/tests/concurrent_submit.php',(string)$when,(string)$event,$keyChar],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $workers[]=[$process,$pipes];
}
$statuses=[];
foreach ($workers as [$process,$pipes]) {
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check('同時申請ワーカーの正常終了 '.count($statuses),proc_close($process)===0 && $error==='');
    $statuses[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['status'];
}
sort($statuses);
check('同時の別イベント申請は一件のみ成功',$statuses===[201,409]);
check('同時申請の敗者に孤児申請を残さない',(int)$db->query("SELECT COUNT(*) FROM portal_applications WHERE applicant_user_id=11")->fetchColumn()===1);
check('同時申請の同日資格一件',(int)$db->query("SELECT COUNT(*) FROM portal_memberships WHERE user_id=11")->fetchColumn()===1);
