<?php
// Path: tests/auth/materials_http.php
// 機能: 架空データで予算計算、CSV境界、二重計上防止、認可、競合、月締め、業務表不変性を検証する。
declare(strict_types=1);
require_once '/srv/auth/src/Bank/PaymentCoreCsv.php';
require_once '/srv/auth/src/Operations/AccountingReview.php';
require_once '/srv/auth/src/Operations/MaterialsReview.php';
use Jmrc\Operations\AccountingReview as AC;
use Jmrc\Operations\MaterialsReview as MR;
use Jmrc\Bank\PaymentCoreCsv as PC;
$reject=static function(string $label,callable $fn,int $status=422):void{try{$fn();check($label,false);}catch(\Jmrc\Auth\HttpFailure $e){check($label,$e->status===$status);}};
$state=MR::initial(7);$year=$state['year'];$month=$state['month'];
$r=AC::report($state['ledger'],$state['budgets'][$year],$year,$year.'-01-01',$year.'-12-31');
check('架空会費は32000円で予算残額と執行率を計算',$r['income']===32000&&$r['items']['club_fee']['remaining']===30000&&$r['items']['club_fee']['rate']===50.0);
$manual=AC::entry(['item'=>'meeting','date'=>$month.'-15','amount'=>'12000','detail'=>'=1+1','memo'=>'架空'],gmdate('c'),'manual:test');
$ledger=[...$state['ledger'],$manual];$r=AC::report($ledger,$state['budgets'][$year],$year,$month.'-12',(new DateTimeImmutable($month.'-01'))->format('Y-m-t'));
check('期間・年累計を区別し予算超過を負数表示',$r['income']===2000&&$r['income_ytd']===32000&&$r['net']===-10000&&$r['items']['meeting']['remaining']===-2000&&$r['items']['meeting']['rate']===120.0);
$bud=AC::budget(['club_fee'=>'0']);$r=AC::report($ledger,$bud,$year,$year.'-01-01',$year.'-12-31');
check('未設定と0円を区別しゼロ除算しない',$r['items']['club_fee']['rate']===null&&$r['items']['club_fee']['budget']===0&&$r['items']['member_fee']['budget']===null);
check('会費連携の再実行で二重計上しない',AC::synchronize(AC::synchronize($ledger,$state['fees']),$state['fees'])===AC::synchronize($ledger,$state['fees'])&&count(AC::synchronize($ledger,$state['fees']))===3);
$fees=$state['fees'];$fees[0]['amount']=25000;$fees[1]['status']='cancelled';$new=AC::synchronize($ledger,$fees);
check('訂正・取消を最新値へ置換し手入力を保持',count($new)===2&&$new[0]===$manual&&$new[1]['amount']===25000);
check('同じ連携元の重複スナップショットを一度だけ計上',count(AC::synchronize([],[$fees[0],$fees[0]]))===1);
$conflict=$fees[0];$conflict['amount']++;
$reject('同じ連携元の不一致は中断',fn()=>AC::synchronize([],[$fees[0],$conflict]),409);
foreach(['-1','1.5','01','1000000001',[]] as $amount){$reject('不正な予算額を拒否',fn()=>AC::budget(['club_fee'=>$amount]));}
$reject('未登録科目の予算を拒否',fn()=>AC::budget(['unknown'=>1]));
$reject('年度をまたぐ期間を拒否',fn()=>AC::report([] ,[],2027,'2026-12-01','2027-01-31'));
$reject('逆転した期間を拒否',fn()=>AC::report([],[],2027,'2027-02-01','2027-01-31'));
$reject('不正日を拒否',fn()=>AC::date('2027-02-29'));
$rows=[['年度','科目コード','予算額（円）'],[2027,'club_fee',0],[2027,'member_fee',null],[2027,'meeting',8000]];
$csv=AC::csv($rows);$round=AC::importBudget($csv);
check('予算CSV往復で年度・0円・空欄を保持',$round['year']===2027&&$round['amounts']['club_fee']===0&&$round['amounts']['member_fee']===null&&$round['amounts']['meeting']===8000);
foreach([AC::csv([...$rows,[2028,'supplies',1]]),AC::csv([...$rows,[2027,'club_fee',1]]),'年度,科目コード,金額',AC::csv([['年度','科目コード','予算額（円）'],[2027,'meeting','=1+1']])] as $bytes){$reject('別年・重複・別帳票・式を予算へ取り込まない',fn()=>AC::importBudget($bytes));}
check('CSVの数式を抑止し負数の計算値は数値で保存',str_contains(AC::csv([['=1+1',' @SUM(A1)',-2]]),"'=1+1")&&AC::csv([[-2]])==="\xEF\xBB\xBF-2\n");
$b=array_fill_keys(array_keys(AC::BALANCE),0);$b['cash']=100;$b['surplus']=100;
check('貸借一致と資産増減',AC::balance($b,['cash'=>30])['balanced']&&AC::balance($b,['cash'=>30])['items']['cash']['difference']===70);
check('未入力を一致と扱わない',!AC::balance([],[])['balanced']);$b['surplus']=90;
check('貸借不一致を自動補完しない',AC::balance($b,[])['difference']===10&&!AC::balance($b,[])['balanced']);
check('日本時間の月境界で前月のみ締め可能',AC::mayClose('2027-02',new DateTimeImmutable('2027-02-28T15:00:00Z'))&&!AC::mayClose('2027-03',new DateTimeImmutable('2027-02-28T15:00:00Z')));
$pc=new PC();$bytes=MR::sampleCsv('payment_core');$p=$pc->parse($bytes,'UTF-8');
check('ペイメントコアの総額・手数料・差引額と年保留',$p['rows'][0]['gross_yen']===2000&&$p['rows'][0]['fee_yen']===100&&$p['rows'][0]['net_yen']===1900&&$p['rows'][0]['date']===null&&!$p['auto_confirm_allowed']);
check('指定名義の末尾数字を残し電話メールは返さない',$p['rows'][0]['specified_name']==='カクウ タロウ123'&&!str_contains(json_encode($p),'example.invalid')&&!isset($p['rows'][0]['email']));
check('指定年を明示した場合だけ日付を確定',$pc->parse($bytes,'UTF-8',2027)['rows'][0]['date']==='2027-10-10');
$dup=AC::csv([PC::HEADERS,['架空','','','カード',2000,100,'02/29','カクウ'],['架空','','','カード',2000,100,'02/29','カクウ']]);
$p=$pc->parse($dup,'UTF-8',2028);check('同内容の決済も消さず重複候補で手動確認',count($p['rows'])===2&&$p['rows'][1]['duplicate_candidate']);
$reject('非うるう年の日付を拒否',fn()=>$pc->parse($dup,'UTF-8',2027));
foreach([str_replace(',100,',',2001,',$bytes),str_replace(',2000,',',-1,',$bytes),str_replace('支払金額','金額',$bytes),str_replace('10/10','2027/10/10',$bytes),$bytes.'"broken'] as $invalid){$reject('手数料超過・負額・別形式・引用符破損は拒否',fn()=>$pc->parse($invalid,'UTF-8'));
}
$yc=new \Jmrc\Bank\YuchoCsv();$y=$yc->parse(MR::sampleCsv('yucho'),'UTF-8');
check('受領ゆうちょ構造を解析し支出を除外',count($y['rows'])===1&&$y['ignored']===1&&$y['rows'][0]['amount']===2000&&$y['rows'][0]['date']==='2026-10-10');
check('ゆうちょCP932とUTF8の解析が同じ',$yc->parse(mb_convert_encoding(MR::sampleCsv('yucho'),'CP932','UTF-8'),'CP932')===$y);
$reject('単位付きと単位なし金額欄の重複拒否',fn()=>$yc->parse("受入金額,受入金額（円）,詳細２\n1,1,カクウ\n",'UTF-8'));
// 全業務表を比較し、確認画面が会費・入金・資格・受付設定を変更しないことを検出する。
$tables=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('audit_logs','portal_audit_events') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$fingerprint=static fn()=>$db->query('CHECKSUM TABLE '.implode(',',array_map(fn($t)=>'`'.$t.'`',$tables)))->fetchAll(PDO::FETCH_ASSOC);$before=$fingerprint();
$office=new Browser();$office->login(7);$entrant=new Browser();$entrant->login(1);$anon=new Browser();$base='/manage/accounting-review.php';
foreach(['','?format=json','?download=budget','?download=payment_core'] as $q){check('未認証は会計画面不可 '.$q,$anon->request($base.$q)['status']===401);check('一般会員は会計画面不可 '.$q,$entrant->request($base.$q)['status']===403);}
check('一般会員の書込不可',$entrant->request($base,['action'=>'reset'])['status']===403);
check('CSRFなしは会計操作不可',$office->request($base,['action'=>'reset'])['status']===400);
foreach(['budget','income','balance','csv','club','notice'] as $section){check('事務局は6画面を表示 '.$section,$office->request($base.'?section='.$section)['status']===200);}
$get=static fn()=>json_decode($office->request($base.'?format=json')['body'],true,512,JSON_THROW_ON_ERROR);
$initial=$get();$csrf=$office->token($base);
$post=static function(array $form)use($office,$base,$get,$csrf):array{$s=$get();return $office->request($base,array_merge(['_csrf'=>$csrf,'instance'=>$s['instance'],'revision'=>(string)$s['revision']],$form));};
check('確認画面は実処理・送信禁止',$initial['business_writes']===false&&$initial['mail_sent']===false);
check('予算入力をPRGで保持',$post(['action'=>'budget','year'=>$initial['year'],'amounts'=>['club_fee'=>70000]])['status']===303&&$get()['budget']['club_fee']===70000);
check('古い版の再送を拒否',$office->request($base,['_csrf'=>$csrf,'action'=>'reset','instance'=>$initial['instance'],'revision'=>(string)$initial['revision']])['status']===409);
check('不正入力は状態を部分更新しない',$post(['action'=>'budget','year'=>2027,'amounts'=>['club_fee'=>'abc']])['status']===422&&$get()['budget']['club_fee']===70000);
check('手入力の支出を追加',$post(['action'=>'manual','date'=>$initial['month'].'-15','item'=>'supplies','amount'=>'500','detail'=>'<script>alert(1)</script>'])['status']===303&&$get()['report']['expense']===500);
check('会費再連携しても実績は増えない',$post(['action'=>'sync'])['status']===303&&$get()['report']['income']===32000&&count($get()['report']['audit'])===3);
$saved=$office->request($base.'?download=budget')['body'];check('HTTPで予算保存・再読込',$post(['action'=>'budget_import','csv_text'=>$saved])['status']===303&&$get()['budget']['club_fee']===70000);
check('別サインインへ確認データを共有しない',(function()use($base){$other=new Browser();$other->login(7);$s=json_decode($other->request($base.'?format=json')['body'],true);return $s['budget']['club_fee']===60000;})());
check('貸借不一致の月を確定しない',$post(['action'=>'close','month'=>$initial['month']])['status']===422);
$b['surplus']=100;
check('残高入力',$post(['action'=>'balance','month'=>$initial['month'],'current'=>$b,'previous'=>['cash'=>'']])['status']===303);
check('前月の貸借一致で確認用締め',$post(['action'=>'close','month'=>$initial['month']])['status']===303);
check('締め月の追加・再連携・残高修正を停止',$post(['action'=>'manual','date'=>$initial['month'].'-15','item'=>'supplies','amount'=>'1'])['status']===409&&$post(['action'=>'sync'])['status']===409&&$post(['action'=>'balance','month'=>$initial['month'],'current'=>$b,'previous'=>[]])['status']===409);
check('クラブ入力はHTMLエスケープ',$post(['action'=>'club','club'=>['club_name'=>'<script>bad</script>']])['status']===303&&str_contains($office->request($base.'?section=club')['body'],'&lt;script&gt;bad&lt;/script&gt;'));
$p=$office->request($base.'?section=csv&format=json',['_csrf'=>$csrf,'action'=>'csv_preview','csv_kind'=>'payment_core','encoding'=>'UTF-8','csv_text'=>$bytes]);
check('HTTPで決済CSV解析だけ行う',$p['status']===200&&json_decode($p['body'],true)['preview']['auto_confirm_allowed']===false&&$get()['preview']===null);
foreach(['income','audit','balance','budget','yucho','payment_core'] as $kind){$out=$office->request($base.'?download='.$kind);check('認可済みCSV出力 '.$kind,$out['status']===200&&str_contains(implode('\n',$office->lastHeaders),'attachment; filename='));}
check('初期化で古い操作インスタンスを失効',$post(['action'=>'reset'])['status']===303&&$get()['instance']!==$initial['instance']);
check('確認機能で既存業務・受付・会費・下書きDB不変',$before===$fingerprint());
// 通知種別追加は既存の暗号化下書き機能にだけ接続し、送信は無効のまま。
foreach(['insurance_completed','club_renewal_2028'] as $kind){$notice=$editor->save(7,'mail_template','lab-'.$kind,0,['title'=>'架空通知','purpose'=>$kind,'body'=>MR::notice($kind)]);check('追加通知は未送信の暗号化下書き '.$kind,$editor->read(7,$notice['id'])['data']['send_enabled']===false);}
