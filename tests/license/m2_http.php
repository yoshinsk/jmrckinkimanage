<?php
// Path: tests/license/m2_http.php
// 機能: 指定複数種別・発行日と参考期限の分離、確認移行、事務局CSVの権限と漏えい防止を隔離検証。
declare(strict_types=1);
$cfg=jmrc_auth_config();$owner=new Browser();$owner->login(15);$office=new Browser();$office->login(7);$other=new Browser();$other->login(16);
$fields=['kind'=>'jaf_competition','number'=>'Ｍ２ＪＡＦ１５','license_types'=>['国内Ｂ','国内Ａ'],'issued_on'=>'2024-02-29'];
foreach([null,[],['不明'],['コースB1'],['国内A','国内A'],[['国内B']],['国際Ⅲ']] as $invalid){
    check('M2 指定種別以外と不正配列を拒否 '.json_encode($invalid),licensePost($owner,array_replace($fields,['license_types'=>$invalid]))['status']===422);
}
check('M2 発行日の不正な暦日を拒否',licensePost($owner,array_replace($fields,['issued_on'=>'2023-02-29']))['status']===422);
check('M2 発行日の配列を拒否',licensePost($owner,array_replace($fields,['issued_on'=>['2024-02-29']]))['status']===422);
$r=licensePost($owner,$fields);$saved=json_decode($r['body'],true);$id=(int)($saved['id']??0);
check('M2 半角整形・複数種別・閏日を保存',$r['status']===200&&$saved['license_types']===['国内A','国内B']&&$saved['issued_on']==='2024-02-29'&&$saved['expires_on']===null);
check('M2 旧期限へ発行日をコピーしない',$db->query('SELECT expires_on FROM portal_identifiers WHERE id='.$id)->fetchColumn()===null);
$before=licenseCounts($db);$r=licensePost($owner,array_replace($fields,['license_types'=>['国内A','国内B']]));
check('M2 選択順だけの再送は更新しない',$r['status']===200&&licenseCounts($db)===$before);
$same=['kind'=>'jaf_official','number'=>'M2JAF15','license_types'=>['計時A2','技術B3','コースB1'],'issued_on'=>''];
$r=licensePost($owner,$same);$official=json_decode($r['body'],true);
check('M2 競技と審判員で同じ本人番号を保持',$r['status']===200&&$official['issued_on']===null&&$official['license_types']===['コースB1','計時A2','技術B3']);
check('M2 審判員の指定15種を保持',count(\Jmrc\License\LicenseTypes::options('jaf_official'))===15&&count(\Jmrc\License\LicenseTypes::validate('jaf_official',\Jmrc\License\LicenseTypes::options('jaf_official')))===15);
$declared=['competition_type'=>['国内B','国内A'],'official_type'=>['なし']];$key=bin2hex(random_bytes(24));$membershipService=new \Jmrc\Membership\MembershipService($db,$cfg);
$general=$membershipService->submitGeneral(15,(int)gmdate('Y'),900,$id,$key,$declared);
$replay=$membershipService->submitGeneral(15,(int)gmdate('Y'),900,$id,$key,['competition_type'=>['国内A','国内B'],'official_type'=>'なし']);
check('M2 Generalの複数選択・並び替え再送で二重申請しない',$general['id']===$replay['id']);
licenseReject('M2 Generalで自由記載の種別を拒否',fn()=>\Jmrc\Membership\LicenseDeclaration::validate(['competition_type'=>'自由記載','official_type'=>'なし'],'jaf_competition'),422);
licenseReject('M2 Generalでなしと保有種別の混在を拒否',fn()=>\Jmrc\Membership\LicenseDeclaration::validate(['competition_type'=>['なし','国内B'],'official_type'=>'なし'],'jaf_competition'),422);
$all=\Jmrc\Membership\LicenseDeclaration::validate(['competition_type'=>\Jmrc\License\LicenseTypes::options('jaf_competition'),'official_type'=>\Jmrc\License\LicenseTypes::options('jaf_official')],'jaf_official');
check('M2 全指定種別を旧80文字申告欄に欠落なく保持',mb_strlen($all['official_type'])<=80&&str_contains($all['official_type'],'技術B3')&&str_contains($all['competition_type'],'国際ソーラーカー'));
$p=(new \Jmrc\License\LicenseMigration($db,$cfg))->plan(7,[30]);$review=$p['review'];$review['items'][0]['decision']='approve';$review['items'][0]['kind']='jaf_competition';$review['items'][0]['expiry_reviewed']=true;
licenseReject('M2 移行の種別・発行日未確認を拒否',fn()=>(new \Jmrc\License\LicenseMigration($db,$cfg))->execute(7,$p['manifest'],$review,'jmrckinki_auth_lab'),422);
$oldPlan=$p['manifest'];$oldPlan['format']='jmrc-license-migration-v1';
licenseReject('M2 旧移行計画の再作成を要求',fn()=>(new \Jmrc\License\LicenseMigration($db,$cfg))->execute(7,$oldPlan,$review,'jmrckinki_auth_lab'),422);
foreach([15,16,1,2,13] as $actor){licenseReject('M2 全体CSVの権限を制限 '.$actor,fn()=>(new \Jmrc\License\LicenseExport($db,$cfg))->csv($actor,2027),403);}
$export=new \Jmrc\License\LicenseExport($db,$cfg);
check('M2 事務局の出力確認画面',$office->request('/manage/license-export.php')['status']===200);
check('M2 本人に出力確認画面を見せない',$owner->request('/manage/license-export.php')['status']===403);
check('M2 出力にCSRFが必要',$office->request('/manage/license-export.php',['year'=>2027,'confirmed'=>'1'])['status']===400);
check('M2 出力に明示確認が必要',$office->request('/manage/license-export.php',['year'=>2027,'_csrf'=>identity($office)['csrf']])['status']===422);
licenseReject('M2 CSV未指定設定は停止',fn()=>(new \Jmrc\License\LicenseExport($db,array_diff_key($cfg,['license_export_enabled'=>true])))->csv(7,2027),503);
licenseReject('M2 CSV不正年度を拒否',fn()=>$export->csv(7,['2027']),422);
licenseReject('M2 CSV未登録年度を拒否',fn()=>$export->csv(7,9999),422);
// 年度を分け、1件だけの架空Generalを作る。保存状態と現在の有効性判定を意図的に区別する。
$year=$db->query('SELECT * FROM portal_years WHERE year=2027')->fetch(PDO::FETCH_ASSOC);$year['year']=2098;
$s=$db->prepare('INSERT INTO portal_years (`'.implode('`,`',array_keys($year)).'`) VALUES('.implode(',',array_fill(0,count($year),'?')).')');$s->execute(array_values($year));
$db->exec("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('individual_general',9,2098,'approved','m2-csv-fixture')");
$app=(int)$db->lastInsertId();$db->exec("INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind,status) VALUES(9,2098,'general',$app,102,'jaf_official','active')");
$member=(int)$db->lastInsertId();$profile=$db->query('SELECT last_name FROM portal_profiles WHERE user_id=9')->fetchColumn();$cipher=$db->query('SELECT value_cipher FROM portal_identifiers WHERE id=102')->fetchColumn();
try{
    $s=$db->prepare('UPDATE portal_profiles SET last_name=? WHERE user_id=9');$s->execute(['=SUM(1,1)']);
    $csv=$export->csv(7,2098);$lines=explode("\n",trim(substr($csv,3)));$row=str_getcsv($lines[1],',','"','');
    check('M2 CSVはBOM・最小項目・確認済み本人番号',str_starts_with($csv,"\xEF\xBB\xBF")&&count($lines)===2&&count($row)===10&&$row[5]==='JAF000009');
    check('M2 CSVの数式実行を防止',str_starts_with($row[3],"'=SUM(1,1)"));
    check('M2 住所・免許証・受取人・暗号文は出力しない',!str_contains($csv,'gcm1:')&&!str_contains($csv,'見舞金')&&!str_contains($csv,'免許証')&&!str_contains($csv,'住所'));
    $download=$office->request('/manage/license-export.php',['year'=>2098,'confirmed'=>'1','_csrf'=>identity($office)['csrf']]);
    check('M2 HTTPでCSVをダウンロード',$download['status']===200&&str_contains($download['body'],'JAF000009'));
    $db->exec("UPDATE portal_identifiers SET value_cipher='opaque-m2' WHERE id=102");
    licenseReject('M2 一部復号不可ならCSV全体を拒否',fn()=>$export->csv(7,2098),503);
}finally{
    $s=$db->prepare('UPDATE portal_profiles SET last_name=? WHERE user_id=9');$s->execute([$profile]);$s=$db->prepare('UPDATE portal_identifiers SET value_cipher=? WHERE id=102');$s->execute([$cipher]);
    $db->exec('DELETE FROM portal_memberships WHERE id='.$member.';DELETE FROM portal_applications WHERE id='.$app.';DELETE FROM portal_years WHERE year=2098');
}
$audit=json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action='LICENSE_EXPORTED'")->fetchAll());
check('M2 出力履歴に番号・氏名を含めない',!str_contains($audit,'JAF000009')&&!str_contains($audit,'SUM'));
