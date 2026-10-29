<?php
// Path: tests/insurance/insurance_http.php
// 機能: 架空会員の加入意思・クラブ範囲・Excel原本保持・未確定資格の保留をHTTPS検証する。
declare(strict_types=1);
require_once '/srv/auth/src/Insurance/InsuranceWorkbook.php';require_once '/srv/auth/src/Insurance/InsuranceService.php';
$insuranceYear=(int)gmdate('Y')+1;$insuranceMembers=[];$insuranceRequests=[];
$st=$db->prepare('INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(?,3,?,1,?)');
foreach(range(60,85) as $uid){
    $st->execute([$uid,"u$uid@example.invalid",password_hash('lab-password-only',PASSWORD_DEFAULT)]);
    $db->exec("INSERT INTO portal_account_controls(user_id) VALUES($uid)");
    $db->exec("INSERT INTO portal_profiles(user_id,last_name,first_name,last_kana,first_kana,birthday,gender) VALUES($uid,'架空','保険$uid','カクウ','ホケン','1990-04-01','male')");
    $db->exec("INSERT INTO portal_identifiers(user_id,kind,value_cipher,lookup_hmac,key_version) VALUES($uid,'jaf_competition','synthetic',UNHEX(SHA2('insurance-$uid',256)),1)");$identifier=(int)$db->lastInsertId();
    $db->exec("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('general',$uid,$insuranceYear,'submitted','insurance-app-$uid')");$app=(int)$db->lastInsertId();
    $db->exec("INSERT INTO portal_memberships(user_id,year,member_type,application_id,general_license_identifier_id,general_license_kind) VALUES($uid,$insuranceYear,'general',$app,$identifier,'jaf_competition')");$m=(int)$db->lastInsertId();$insuranceMembers[$uid]=$m;
    $cy=$uid===61?102:101;
    $db->exec("INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at) VALUES($m,$insuranceYear,$cy,'pending',UTC_TIMESTAMP(6))");
    if($uid>=63){$db->exec("INSERT INTO portal_insurance_requests(membership_id,year,club_year_id,consented_at) VALUES($m,$insuranceYear,101,UTC_TIMESTAMP(6))");$insuranceRequests[]=(int)$db->lastInsertId();}
}
function insurancePost(Browser $b,array $in): array {return $b->request('/manage/insurance.php?format=json',$in+['_csrf'=>identity($b)['csrf']]);}
function insuranceCounts(PDO $db): array {$out=[];foreach(['portal_insurance_requests','portal_insurance_exports','portal_insurance_export_items','portal_audit_events'] as $t){$out[]=(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}return $out;}
$insOwner=new Browser();$insOwner->login(60);$insOther=new Browser();$insOther->login(61);$insRep=new Browser();$insRep->login(3);$insOffice=new Browser();$insOffice->login(7);$insAnonymous=new Browser();$insLegacy=new Browser();$insLegacy->login(1);$insGlobal=new Browser();$insGlobal->login(8);
$insData=['action'=>'request','membership_id'=>$insuranceMembers[60],'consent'=>'1'];
check('保険匿名アクセス不可',$insAnonymous->request('/manage/insurance.php?format=json')['status']===401);
check('保険POSTのCSRF必須',$insOwner->request('/manage/insurance.php?format=json',$insData)['status']===400);
check('保険は他人の会員登録を使用不可',insurancePost($insOther,$insData)['status']===404);
check('保険意思同意必須',insurancePost($insOwner,array_replace($insData,['consent'=>'0']))['status']===422);
check('未確定保険料の入力を拒否',insurancePost($insOwner,$insData+['amount_yen'=>2000])['status']===422);
check('保険加入確定アクションは未提供',insurancePost($insOwner,['action'=>'activate'])['status']===422);
$before=insuranceCounts($db);$insOwner->request('/manage/insurance.php?format=json');check('保険GETは書込まない',insuranceCounts($db)===$before);
$money=[];foreach(['portal_invoices','portal_payments','portal_scheme_enrollments'] as $t){$money[$t]=(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}
$r=insurancePost($insOwner,$insData);$intent=json_decode($r['body'],true);$insuranceRequests[]=$intent['id'];
check('申請中会員も加入意思だけ受付',$r['status']===200 && $intent['policy_hold']===true && $intent['insurance_active']===false);
// Temporaryの可否は未確定。年度資格を付与せず、意思の記録に限る。
$st->execute([86,'u86@example.invalid',password_hash('lab-password-only',PASSWORD_DEFAULT)]);$db->exec('INSERT INTO portal_account_controls(user_id) VALUES(86)');
$db->exec("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('temporary',86,$insuranceYear,'submitted','insurance-temporary-86')");$tempApp=(int)$db->lastInsertId();
$db->exec("INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id,participation_date) SELECT 86,year,'temporary',id,$tempApp,participation_date FROM portal_events WHERE id=101");$tempMember=(int)$db->lastInsertId();
$tempBrowser=new Browser();$tempBrowser->login(86);$tempIntent=json_decode(insurancePost($tempBrowser,['action'=>'request','membership_id'=>$tempMember,'consent'=>'1'])['body'],true);
check('Temporaryも加入意思記録だけで可否保留',$tempIntent['policy_hold']===true && $tempIntent['insurance_active']===false);
$before=insuranceCounts($db);$replayed=json_decode(insurancePost($insOwner,$insData)['body'],true);
check('加入意思再送は重複しない',$replayed['id']===$intent['id'] && $replayed['replayed'] && insuranceCounts($db)===$before);
check('保険本人一覧は他人の申請なし',count(json_decode($insOwner->request('/manage/insurance.php?format=json')['body'],true)['requests'])===1);
$route='/manage/insurance.php?format=json&club_year_id=101';
check('保険代表は対象クラブ一覧を参照',$insRep->request($route)['status']===200);
check('保険事務局は対象一覧を参照',$insOffice->request($route)['status']===200);
check('保険代表は他クラブを参照不可',$insRep->request('/manage/insurance.php?format=json&club_year_id=102')['status']===403);
check('保険旧管理者roleだけでは出力不可',$insLegacy->request($route)['status']===403);
check('保険global代表grantは使用不可',$insGlobal->request($route)['status']===403);
check('保険本人に他会員の一覧を返さない',$insOwner->request($route)['status']===403);
$db->exec("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by) VALUES(62,3,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1)");$insFinance=new Browser();$insFinance->login(62);
check('保険財務専用権限では一覧不可',$insFinance->request($route)['status']===403);
$r=insurancePost($insOther,['action'=>'request','membership_id'=>$insuranceMembers[61],'consent'=>'1']);$otherIntent=json_decode($r['body'],true);
$export=['action'=>'export','club_year_id'=>101,'request_ids'=>[$intent['id']]];
check('保険出力もCSRF必須',$insRep->request('/manage/insurance.php',$export)['status']===400);
$get=$insRep->request($route);check('保険GETでファイルを取得しない',$get['status']===200 && !str_starts_with($get['body'],'PK') && !str_contains(implode(' ',$insRep->lastHeaders),'spreadsheetml'));
check('他クラブ申請ID混入を拒否',insurancePost($insRep,array_replace($export,['request_ids'=>[$intent['id'],$otherIntent['id']]]))['status']===404);
check('保険出力重複選択を拒否',insurancePost($insRep,array_replace($export,['request_ids'=>[$intent['id'],$intent['id']]]))['status']===422);
check('保険出力空選択を拒否',insurancePost($insRep,array_replace($export,['request_ids'=>[]]))['status']===422);
check('保険25行以上を黙って切捨てない',insurancePost($insRep,array_replace($export,['request_ids'=>range(1,25)]))['status']===422);
$file=insurancePost($insRep,$export);$headers=implode('\n',$insRep->lastHeaders);
check('保険指定形式を1名でも確認出力',$file['status']===200 && str_starts_with($file['body'],'PK'));
check('保険出力の形式とキャッシュ禁止',str_contains($headers,'spreadsheetml.sheet') && stripos($headers,'no-store')!==false && str_contains($headers,'attachment'));
file_put_contents('/tmp/insurance-preview.xlsx',$file['body']);
$tmp=tempnam('/tmp','insurance-test-');file_put_contents($tmp,$file['body']);$zip=new ZipArchive();$zip->open($tmp);$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
check('保険Excelに氏名ふりがな生年月日',$sheet!==false && str_contains($sheet,'保険60') && str_contains($sheet,'カクウ') && str_contains($sheet,'1990.04.01'));
check('保険Excelに確認用と条件保留',$sheet!==false && str_contains($sheet,'提出不可') && str_contains($sheet,'加入条件未確定') && !str_contains($sheet,'<f>'));
check('保険Excelは年齢区分を判定しない',str_contains($sheet,'r="B11" s=') && !str_contains($sheet,'<t xml:space="preserve">Ｃ</t>'));
check('保険Excelの計算チェーンを削除',$zip->getFromName('xl/calcChain.xml')===false && !str_contains($zip->getFromName('xl/_rels/workbook.xml.rels'),'/calcChain'));
$source=new ZipArchive();$source->open('/srv/auth/templates/insurance/sports.security.insurance_form_club.xlsx');
foreach(['xl/styles.xml','xl/workbook.xml','xl/theme/theme1.xml'] as $part){check('保険指定様式パーツ保持 '.$part,$source->getFromName($part)===$zip->getFromName($part));}
$source->close();$zip->close();unlink($tmp);
$stored=$db->query('SELECT * FROM portal_insurance_exports ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('保険出力ハッシュと対象履歴',(int)$stored['member_count']===1 && $stored['output_sha256']===hash('sha256',$file['body']) && (int)$db->query('SELECT COUNT(*) FROM portal_insurance_export_items WHERE export_id='.(int)$stored['id'])->fetchColumn()===1);
$all=array_merge([$intent['id']],array_slice($insuranceRequests,0,23));
$file24=insurancePost($insOffice,array_replace($export,['request_ids'=>$all]));check('保険24名を事務局が出力',$file24['status']===200 && str_starts_with($file24['body'],'PK'));file_put_contents('/tmp/insurance-preview-24.xlsx',$file24['body']);
// 文字列を式として解釈しないことはXML型も確認する。
$db->exec("UPDATE portal_profiles SET last_name='=1+1',first_name='<架空&氏名>' WHERE user_id=60");
$formula=insurancePost($insRep,$export);check('保険Excel特殊文字・式風氏名も出力可',$formula['status']===200);file_put_contents('/tmp/insurance-formula.xlsx',$formula['body']);
$db->exec("UPDATE portal_profiles SET last_name='架空',first_name='保険60' WHERE user_id=60");
$db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=3 AND role_id=2 AND scope_club_id=1");
check('保険出力は権限取消を即反映',insurancePost($insRep,$export)['status']===403);$db->exec("UPDATE portal_user_role_grants SET revoked_at=NULL WHERE user_id=3 AND role_id=2 AND scope_club_id=1");
$db->exec('UPDATE portal_affiliations SET club_year_id=102 WHERE membership_id='.$insuranceMembers[60]);
check('保険所属変更後は旧クラブ出力不可',insurancePost($insRep,$export)['status']===409);
$moved=json_decode(insurancePost($insOwner,$insData)['body'],true);check('本人再申請で所属を更新',$moved['club_year_id']===102 && $moved['row_version']===2);
check('旧クラブ一覧から移動済み申請を除外',!in_array($intent['id'],array_column(json_decode($insRep->request($route)['body'],true)['requests'],'id')));
$before=insuranceCounts($db);$db->exec('RENAME TABLE portal_audit_events TO insurance_audit_unavailable');
check('保険監査失敗で出力を返さない',insurancePost($insOffice,['action'=>'export','club_year_id'=>102,'request_ids'=>[$intent['id']]])['status']===503);
$db->exec('RENAME TABLE insurance_audit_unavailable TO portal_audit_events');check('保険監査失敗で出力履歴ロールバック',insuranceCounts($db)===$before);
foreach($money as $t=>$n){check('保険意思受付・出力は課金加入を変更しない '.$t,(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn()===$n);}
$service=new \Jmrc\Insurance\InsuranceService($db,[]);$disabled=false;try{$service->request(60,$insuranceMembers[60],true);}catch(\Jmrc\Auth\HttpFailure $e){$disabled=$e->status===503;}check('保険受付は既定で無効',$disabled);
check('保険原本・内部テンプレートはWeb非公開',$insAnonymous->request('/templates/insurance/sports.security.insurance_form_club.xlsx')['status']===404);
$db->exec('UPDATE portal_memberships SET status=\'suspended\' WHERE id='.$insuranceMembers[60]);check('保険停止会員の再申請は確認保留',insurancePost($insOwner,$insData)['status']===409);
