<?php
// Path: tests/bank/bank_http.php
// 機能: 架空CSVで文字コード・形式・再取込・曖昧照合・既存入金・逆仕訳・同時実行をHTTPS検証。
declare(strict_types=1);
require_once '/srv/auth/src/Bank/YuchoCsv.php';require_once '/srv/auth/src/Bank/BankService.php';
require_once '/srv/auth/src/Billing/BillingService.php';require_once '/srv/auth/src/Billing/Eligibility.php';
require_once '/srv/auth/src/Club/ClubService.php';require_once '/srv/auth/src/Club/ClubEligibility.php';
function bankPost(Browser $b,array $in): array {return $b->request('/manage/bank.php?format=json',$in+['_csrf'=>identity($b)['csrf']]);}
function bankDetail(Browser $b,int $id): array {$r=$b->request('/manage/bank.php?format=json&row='.$id);if($r['status']!==200){throw new RuntimeException('bank detail '.$r['status']);}return json_decode($r['body'],true);}
function bankCsv(string $name,string $amount='2000',string $date='2026/08/15'): string {return "取扱日,受入金額,詳細２\r\n$date,$amount,$name\r\n";}
function bankImport(Browser $b,string $csv,int $source=1,string $encoding='UTF-8'): array{return bankPost($b,['action'=>'import','source'=>$source,'encoding'=>$encoding,'csv_text'=>$csv]);}
function bankCounts(PDO $db): array {$r=[];foreach(['portal_bank_imports','portal_yucho_rows','portal_yucho_import_rows','portal_payments','portal_allocations','portal_audit_events','portal_outbox'] as $t){$r[]=(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn();}return $r;}
function bankResolve(Browser $b,int $row,int $invoice,array $extra=[]): array{return bankPost($b,$extra+['action'=>'resolve','row'=>$row,'version'=>1,'mode'=>'new','invoice'=>$invoice,'payment'=>0,'date'=>'2026-08-15','reason'=>'架空銀行資料と照合','legacy_checked'=>'1']);}
function bankRace(string $mode,array $args=[]): array {
    $start=(string)(microtime(true)+0.4);$jobs=[];
    foreach([0,1] as $i){$pipes=[];$p=proc_open(['php','/srv/tests/concurrent_bank.php',$start,$mode,...($args[$i]??[])],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$jobs[]=[$p,$pipes];}
    $r=[];foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException('bank worker failure');}$r[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}return $r;
}
$memberAccount=(int)$db->query("SELECT id FROM portal_bank_accounts WHERE purpose_code='membership_fee' ORDER BY id LIMIT 1")->fetchColumn();
$db->exec("INSERT INTO portal_bank_sources(id,code,display_name,enabled) VALUES(1,'lab-yucho-one','架空ゆうちょ実口座1',1),(2,'lab-yucho-two','架空ゆうちょ実口座2',1);
 INSERT INTO portal_bank_source_accounts(account_id,source_id) VALUES($memberAccount,1)");
$clubAccount=(int)$db->query("SELECT id FROM portal_bank_accounts WHERE purpose_code='club_fee' ORDER BY id LIMIT 1")->fetchColumn();if($clubAccount && $clubAccount!==$memberAccount){$db->exec("INSERT INTO portal_bank_source_accounts(account_id,source_id) VALUES($clubAccount,1)");}
$db->exec("INSERT INTO portal_bank_accounts(purpose_code,display_name,details_cipher,enabled,updated_by) VALUES('lab_only','架空別実口座','synthetic',1,1)");$secondAccount=(int)$db->lastInsertId();$db->exec("INSERT INTO portal_bank_source_accounts(account_id,source_id) VALUES($secondAccount,2)");
$db->exec("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by) VALUES(76,1,'global','active',UTC_TIMESTAMP()-INTERVAL 1 DAY,1,1)");
$invoices=[];$billing=new \Jmrc\Billing\BillingService($db);
foreach(range(63,75) as $uid){$m=(int)$db->query('SELECT id FROM portal_memberships WHERE user_id='.$uid)->fetchColumn();$invoices[$uid]=(int)$billing->issue(7,$m)['id'];}
$db->exec("UPDATE portal_profiles SET last_kana='ギンコウ',first_kana='イチ' WHERE user_id=63;
 UPDATE portal_profiles SET last_kana='ギンコウ',first_kana='ニ' WHERE user_id=64;
 UPDATE portal_profiles SET last_kana='ドウメイ',first_kana='カナ' WHERE user_id IN (65,66)");
$officeBank=new Browser();$officeBank->login(76);$financeBank=new Browser();$financeBank->login(62);$repBank=new Browser();$repBank->login(3);$legacyBank=new Browser();$legacyBank->login(1);$anonBank=new Browser();
check('銀行匿名は401',$anonBank->request('/manage/bank.php?format=json')['status']===401);
check('銀行旧roleだけでは403',$legacyBank->request('/manage/bank.php?format=json')['status']===403);
check('銀行クラブ代表は403',$repBank->request('/manage/bank.php?format=json')['status']===403);
check('銀行事務局と財務の双方が利用可能',$officeBank->request('/manage/bank.php')['status']===200 && $financeBank->request('/manage/bank.php')['status']===200);
$csv=bankCsv('ｷﾞﾝｺｳ ｲﾁ');$before=bankCounts($db);
check('銀行取込CSRF必須',$officeBank->request('/manage/bank.php?format=json',['action'=>'import','source'=>1,'encoding'=>'UTF-8','csv_text'=>$csv])['status']===400);
check('銀行未知口座は拒否',bankImport($officeBank,$csv,99)['status']===422 && bankCounts($db)===$before);
$first=bankImport($officeBank,"\xEF\xBB\xBF".$csv);$import=json_decode($first['body'],true);$row=(int)$db->query('SELECT row_id FROM portal_yucho_import_rows WHERE import_id='.(int)$import['id'])->fetchColumn();
check('銀行UTF8 BOM取込',$first['status']===200 && $import['new_rows']===1);
check('銀行取込では入金・充当を作らない',array_slice(bankCounts($db),3,2)===array_slice($before,3,2));
$before=bankCounts($db);$again=json_decode(bankImport($financeBank,"\xEF\xBB\xBF".$csv)['body'],true);
check('銀行同じファイル再取込は同じID',$again['id']===$import['id'] && $again['replayed'] && bankCounts($db)===$before);
$recoded=mb_convert_encoding("詳細２,受入金額,取扱日\nギンコウ　イチ,\"2,000\",2026-08-15\n",'CP932','UTF-8');$rec=json_decode(bankImport($financeBank,$recoded,1,'CP932')['body'],true);
check('銀行CP932・列順・文字幅変更も二重明細なし',$rec['new_rows']===0 && $rec['seen_rows']===1);
$detail=bankDetail($financeBank,$row);
check('銀行一意名義金額は候補のみ',$detail['match_state']==='unique_candidate' && $detail['automatic_confirmation']===false && $detail['candidates'][0]['invoice_id']===$invoices[63]);
check('銀行暗号文・HMACを返さない',!isset($detail['payer_name_cipher']) && !isset($detail['payer_hmac']) && !isset($detail['fingerprint']));
$stored=$db->query('SELECT payer_name_cipher FROM portal_yucho_rows WHERE id='.$row)->fetchColumn();check('銀行名義は暗号保管',str_starts_with($stored,'gcm1:') && !str_contains($stored,'ｷﾞﾝｺｳ'));
$before=bankCounts($db);bankDetail($financeBank,$row);check('銀行照会だけでは書込まない',bankCounts($db)===$before);
check('銀行未確認チェックでは確定不可',bankResolve($financeBank,$row,$invoices[63],['legacy_checked'=>'0'])['status']===422);
check('銀行理由なしでは確定不可',bankResolve($financeBank,$row,$invoices[63],['reason'=>''])['status']===422);
check('銀行CSV日付の変更拒否',bankResolve($financeBank,$row,$invoices[63],['date'=>'2026-08-16'])['status']===422);
check('銀行古い版で確定不可',bankResolve($financeBank,$row,$invoices[63],['version'=>99])['status']===409);
$confirmed=bankResolve($financeBank,$row,$invoices[63]);$done=json_decode($confirmed['body'],true);
check('銀行財務が手動確定・請求充当',$confirmed['status']===200 && $done['status']==='confirmed' && $done['payment_id']>0 && billState($financeBank,$invoices[63])['eligibility']['paid']);
check('銀行入金だけでは所属未承認を有効化しない',!billState($financeBank,$invoices[63])['eligibility']['active']);
$before=bankCounts($db);$repeat=json_decode(bankResolve($officeBank,$row,$invoices[63])['body'],true);
check('銀行確認再送は同じ入金・充当',$repeat['replayed'] && $repeat['payment_id']===$done['payment_id'] && bankCounts($db)===$before);
check('銀行処理済みを別請求へ付替えない',bankResolve($financeBank,$row,$invoices[64])['status']===409);
bankImport($financeBank,$csv);check('銀行確定後の別CSV再取込も入金増加なし',(int)$db->query("SELECT COUNT(*) FROM portal_payments WHERE source_type='bank_csv'")->fetchColumn()===1);
$allocation=(int)$db->query('SELECT id FROM portal_allocations WHERE payment_id='.(int)$done['payment_id'])->fetchColumn();
check('銀行入金も逆仕訳で資格再判定',billPost($financeBank,'reverse',$allocation,['reason'=>'架空銀行訂正'])['status']===200 && !billState($financeBank,$invoices[63])['eligibility']['paid']);
$before=bankCounts($db);bankImport($financeBank,$csv);check('銀行逆仕訳後の再取込は復活・再計上しない',bankCounts($db)===$before);
check('銀行元の入金へ再充当可能',billPost($financeBank,'reallocate',$invoices[63],['payment'=>$done['payment_id'],'amount'=>2000])['status']===200);
$cyCharge=$db->query('SELECT * FROM portal_club_charges WHERE club_year_id=101')->fetch(PDO::FETCH_ASSOC);
$clubAllocation=(int)$db->query('SELECT a.id FROM portal_allocations a WHERE a.invoice_line_id='.(int)$cyCharge['invoice_line_id'].' AND a.amount_yen>0 AND NOT EXISTS(SELECT 1 FROM portal_allocations rev WHERE rev.reverses_id=a.id) ORDER BY a.id DESC LIMIT 1')->fetchColumn();
check('銀行クラブ会費検証の逆仕訳',billPost($financeBank,'reverse',$clubAllocation,['reason'=>'架空クラブ銀行照合'])['status']===200);
bankImport($officeBank,bankCsv('クラブ会費確認',(string)$cyCharge['amount_yen'],'2026-08-26'));$clubBankRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();
check('銀行同じ実口座のクラブ会費へ充当・資格再判定',bankResolve($officeBank,$clubBankRow,(int)$cyCharge['invoice_id'],['date'=>'2026-08-26'])['status']===200 && billState($officeBank,(int)$cyCharge['invoice_id'])['eligibility']['paid']);
$unknown=bankCsv('ダレカ');bankImport($officeBank,$unknown);$unknownRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();
check('銀行名義不一致は手動確認',bankDetail($officeBank,$unknownRow)['match_state']==='manual_review');
$ambiguous=bankCsv('ドウメイカナ');bankImport($officeBank,$ambiguous);$ambiguousRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$d=bankDetail($officeBank,$ambiguousRow);
check('銀行同名同額複数請求を先頭採用しない',$d['match_state']==='manual_review' && count($d['candidates'])===2);
bankImport($officeBank,bankCsv('ギンコウニ999'));$numeric=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();check('銀行名義末尾数字をIDや免許番号として除去しない',bankDetail($officeBank,$numeric)['candidates']===[]);
$before=bankCounts($db);
foreach(["取扱日,受入金額,詳細２\n2026/8/15,12abc,ダレカ", "取扱日,受入金額,詳細２\n2026/2/30,2000,ダレカ", "取扱日,受入金額,詳細２\n2026/8/15,2000,\"閉じない", "受入金額,詳細２\n-2000,ダレカ", "支払金額,指定名義人名\n2000,ダレカ", "受入金額,詳細２\n2000,ダレカ,余分"] as $index=>$bad){check('銀行不正CSV拒否 '.$index,bankImport($financeBank,$bad)['status']===422 && bankCounts($db)===$before);}
check('銀行不明文字コードを拒否',bankImport($financeBank,$csv,1,'AUTO')['status']===422);
check('銀行壊れたUTF8を拒否',bankImport($financeBank,"\xFF".$csv)['status']===422);
$limitCsv="受入金額,詳細２\n".str_repeat("1,架空\n",2000);check('銀行2000明細の解析上限',count((new \Jmrc\Bank\YuchoCsv())->parse($limitCsv,'UTF-8')['rows'])===2000);
$blockedLimit=false;try{(new \Jmrc\Bank\YuchoCsv())->parse($limitCsv."1,架空\n",'UTF-8');}catch(\Jmrc\Auth\HttpFailure $e){$blockedLimit=$e->status===422;}check('銀行2001明細を拒否',$blockedLimit);
check('銀行途中の不正明細で全体を拒否',bankImport($financeBank,bankCsv('全体拒否')."2026/08/15,不正額,架空\n")['status']===422 && bankCounts($db)===$before);
check('銀行サイズ超過拒否',in_array(bankImport($financeBank,str_repeat('x',2097153))['status'],[413,422],true));
$multi="取扱日,受入金額,詳細２\n2026/8/16,2000,\"ギンコウ\nニ\"\n2026/8/16,0,出金\n";$m=json_decode(bankImport($financeBank,$multi)['body'],true);check('銀行引用内改行とゼロ受入の件数報告',$m['new_rows']===1 && $m['ignored']===1);
bankImport($financeBank,"受入金額,詳細２\n2000,日付なし架空\n");$undated=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$undatedDetail=bankDetail($financeBank,$undated);
check('銀行日付なしは取込日補完せず手動確認',$undatedDetail['receipt_date']===null && $undatedDetail['match_state']==='manual_review');
check('銀行日付なし明細は証跡日を明示してのみ確定',bankResolve($financeBank,$undated,$invoices[75],['date'=>'2026-08-27'])['status']===200);
$undatedChild=json_decode(bankPost($officeBank,['action'=>'split','row'=>$undated,'reference'=>'LAB-UNDATED-SECOND','reason'=>'別日付の架空振込証跡','separate_transfer'=>'1'])['body'],true);
check('銀行日付なしの別実取引へ前の確認日をコピーしない',$undatedChild['receipt_date']===null);
check('銀行日付なしの分離取引も個別証跡日で照合',bankResolve($officeBank,$undatedChild['id'],$invoices[68],['date'=>'2026-08-29'])['status']===200);
$same="取扱日,受入金額,詳細２\n2026/8/17,2000,ギンコウニ\n2026/8/17,2000,ギンコウニ\n";bankImport($financeBank,$same);$sameRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$d=bankDetail($financeBank,$sameRow);
check('銀行同一条件複数行を自動で複数入金にしない',$d['same_file_occurrences']===2 && $d['match_state']==='manual_review');
$new=bankResolve($officeBank,$sameRow,$invoices[64],['date'=>'2026-08-17']);check('銀行重複候補も担当者確認後のみ確定',$new['status']===200);
$split=['action'=>'split','row'=>$sameRow,'reference'=>'LAB-BANK-TXN-002','reason'=>'別の架空実振込明細を確認','separate_transfer'=>'1'];
$child=json_decode(bankPost($officeBank,$split)['body'],true);check('銀行別実取引は証跡付き確認待ちへ分離',$child['split_from_id']===$sameRow && $child['status']==='review');
$before=bankCounts($db);check('銀行別取引の証跡識別子再送は同一ID',json_decode(bankPost($officeBank,$split)['body'],true)['id']===$child['id'] && bankCounts($db)===$before);
check('銀行同日同額の別取引確認を省略不可',bankResolve($officeBank,$child['id'],$invoices[67],['date'=>'2026-08-17'])['status']===409);
check('銀行証跡分離後に別実取引を確認充当',bankResolve($officeBank,$child['id'],$invoices[67],['date'=>'2026-08-17','separate_transfer'=>'1'])['status']===200);
$before=bankCounts($db);bankImport($officeBank,$same);check('銀行別取引分離後の元CSV再取込は追加なし',bankCounts($db)===$before);
$r2=json_decode(bankImport($officeBank,$csv,2)['body'],true);check('銀行別実口座は独立した明細',$r2['new_rows']===1);$wrongSource=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();
check('銀行別実口座の請求への誤充当拒否',bankResolve($officeBank,$wrongSource,$invoices[68])['status']===422);
// 既存手動入金を再作成せず紐付ける。別のCSV名義表現で来た場合は重複確認で閉じる。
$manual=billPost($financeBank,'confirm',$invoices[69],['amount'=>2000,'reference'=>'YUCHO-LEGACY-LAB']);$manualPayment=(int)json_decode($manual['body'],true)['payment_id'];
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');bankImport($officeBank,bankCsv('旧手動名義','2000',$today));$linkRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();
$before=bankCounts($db);check('銀行既登録手動入金の無確認新規登録を拒否',bankResolve($officeBank,$linkRow,$invoices[70],['date'=>$today])['status']===409);
check('銀行既存手動入金へ紐付け可能',bankResolve($officeBank,$linkRow,$invoices[69],['mode'=>'link','payment'=>$manualPayment,'date'=>$today])['status']===200 && array_slice(bankCounts($db),3,2)===array_slice($before,3,2));
bankImport($officeBank,bankCsv('異なる名義表現','2000',$today));$duplicate=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$before=bankCounts($db);
check('銀行既存入金と重複として記録・金額追加なし',bankResolve($officeBank,$duplicate,0,['mode'=>'duplicate','payment'=>$manualPayment,'date'=>$today])['status']===200 && array_slice(bankCounts($db),3,2)===array_slice($before,3,2));
bankImport($officeBank,bankCsv('旧手動の確認日差異','2000','2026-08-28'));$differentDate=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$before=bankCounts($db);
check('銀行旧手動入金の日付差異は確認なしで許容しない',bankResolve($officeBank,$differentDate,0,['mode'=>'duplicate','payment'=>$manualPayment,'date'=>'2026-08-28'])['status']===422);
check('銀行旧手動記録日と実入金日の差を証跡確認で重複解決',bankResolve($officeBank,$differentDate,0,['mode'=>'duplicate','payment'=>$manualPayment,'date'=>'2026-08-28','manual_date_checked'=>'1'])['status']===200 && array_slice(bankCounts($db),3,2)===array_slice($before,3,2));
bankImport($officeBank,bankCsv('不足入金','700','2026-08-18'));$partialRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();
check('銀行不足入金は請求残額を残す',bankResolve($officeBank,$partialRow,$invoices[71],['date'=>'2026-08-18'])['status']===200 && billState($officeBank,$invoices[71])['balance_yen']===1300);
bankImport($officeBank,bankCsv('過入金','2500','2026-08-19'));$overRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$over=json_decode(bankResolve($officeBank,$overRow,$invoices[72],['date'=>'2026-08-19'])['body'],true);
check('銀行過入金を他請求へ自動振替しない',(int)$db->query('SELECT amount_yen FROM portal_payments WHERE id='.$over['payment_id'])->fetchColumn()===2500 && (int)$db->query('SELECT SUM(amount_yen) FROM portal_allocations WHERE payment_id='.$over['payment_id'])->fetchColumn()===2000);
$before=bankCounts($db);$db->exec('RENAME TABLE portal_audit_events TO bank_unavailable_audit');$failed=bankImport($officeBank,bankCsv('障害確認','1234','2026-08-22'));$db->exec('RENAME TABLE bank_unavailable_audit TO portal_audit_events');check('銀行取込監査失敗は全体ロールバック',$failed['status']===503 && bankCounts($db)===$before);
bankImport($officeBank,bankCsv('照合障害','2000','2026-08-23'));$failRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$before=bankCounts($db);$db->exec('RENAME TABLE portal_outbox TO bank_unavailable_outbox');$failed=bankResolve($officeBank,$failRow,$invoices[73],['date'=>'2026-08-23']);$db->exec('RENAME TABLE bank_unavailable_outbox TO portal_outbox');check('銀行充当通知失敗は入金資格ごとロールバック',$failed['status']===503 && bankCounts($db)===$before);
file_put_contents('/tmp/yucho-race.csv',bankCsv('同時銀行','2000','2026-08-20'));$before=bankCounts($db);$race=bankRace('import');
check('銀行同時再取込は両方成功・同一取込ID',array_column($race,'status')===[200,200] && $race[0]['id']===$race[1]['id'] && bankCounts($db)[0]===$before[0]+1 && bankCounts($db)[1]===$before[1]+1);
$raceRow=(int)$db->query('SELECT MAX(id) FROM portal_yucho_rows')->fetchColumn();$before=bankCounts($db);$race=bankRace('resolve',[[(string)$raceRow,(string)$invoices[74],'2026-08-20'],[(string)$raceRow,(string)$invoices[74],'2026-08-20']]);
check('銀行同時確定も一つの入金と充当',array_column($race,'status')===[200,200] && bankCounts($db)[3]===$before[3]+1 && bankCounts($db)[4]===$before[4]+1);
$db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=76 AND role_id=1");check('銀行権限取消を即反映',$officeBank->request('/manage/bank.php?format=json')['status']===403);$db->exec("UPDATE portal_user_role_grants SET revoked_at=NULL WHERE user_id=76 AND role_id=1");
$wrong=new \Jmrc\Bank\BankService($db,['yucho_import_enabled'=>true,'data_key'=>base64_encode(str_repeat('X',32))]);$blocked=false;try{$wrong->import(7,1,$csv,'UTF-8');}catch(\Jmrc\Auth\HttpFailure $e){$blocked=$e->status===503;}check('銀行鍵の無断変更で重複識別を再生成しない',$blocked);
$disabled=new \Jmrc\Bank\BankService($db,[]);$blocked=false;try{$disabled->access(7);}catch(\Jmrc\Auth\HttpFailure $e){$blocked=$e->status===503;}check('銀行取込は既定無効',$blocked);
$audit=json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action LIKE 'YUCHO_%'")->fetchAll());check('銀行監査に名義・証跡本文を保存しない',!str_contains($audit,'ギンコウ') && !str_contains($audit,'LAB-BANK-TXN'));
