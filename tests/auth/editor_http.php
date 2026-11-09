<?php
// Path: tests/auth/editor_http.php
// 機能: 新回答の境界条件、暗号化下書きの更新競合・権限・期限・改ざん・業務不変性を架空DBで確認する。
declare(strict_types=1);
require_once '/srv/auth/src/Operations/AnswerRules.php';require_once '/srv/auth/src/Content/EditorService.php';
use Jmrc\Operations\AnswerRules as AR;
use Jmrc\Series\SeriesRules as SR;
$bad=static function(string $label,callable $f,int $status=422):void{try{$f();check($label,false);}catch(\Jmrc\Auth\HttpFailure $e){check($label,$status===$e->status);}catch(InvalidArgumentException){check($label,$status===422);}};
foreach([['1962-04-10','2027-04-09',64,2000],['1962-04-10','2027-04-10',65,1200],['1990-01-01','2027-12-31',37,2000]] as [$birth,$day,$age,$fee]){
 $quote=AR::insurance($birth,$day);check('保険の誕生日・年度途中 '.$day,$quote['age']===$age&&$quote['amount_yen']===$fee&&!$quote['can_enroll']&&!$quote['coverage_period_managed']);
}
foreach([['2027-02-30','2027-03-01'],['1962-04-10','2028-04-10'],["1962-04-10\0",'2027-04-10'],['2030-01-01','2027-04-10']] as $args){$bad('不正日付・翌年の料率を補完しない',fn()=>AR::insurance(...$args));}
foreach(['2027-01-31T12:34:56+09:00'=>'2027-04-30T12:34:56+09:00','2027-11-30T01:02:03+09:00'=>'2028-02-29T01:02:03+09:00'] as $from=>$to){check('最終編集から3か月の月末 '.$from,AR::draftExpires(new DateTimeImmutable($from))->format(DATE_ATOM)===$to);}
check('翌年イベントの前年受付を認めない',!AR::entryYearAllowed('2027-02-01',new DateTimeImmutable('2026-12-31T23:59:59+09:00')));
check('日本時間の年境界で当年受付可能',AR::entryYearAllowed('2027-02-01',new DateTimeImmutable('2026-12-31T15:00:00Z')));
foreach(['official','affiliated','associate','organization','business'] as $category){check('賛助すべての先頭は6 '.$category,\Jmrc\Club\ClubFeePolicy::quote(2027,$category,'supporting','new')['code_prefix']==='6');}
check('正会員登録団体も6だが賛助と異なる料金',\Jmrc\Club\ClubFeePolicy::quote(2027,'organization','regular','new')['annual_yen']===20000);
check('平均を先に四捨五入してから半数',SR::awardLimit(11,3)===2&&SR::awardLimit(10,3)===1&&SR::awardLimit(100,3)===6);
$rows=static fn(array $scores,string $date='2027-04-01')=>array_map(fn($i,$p)=>['event'=>(string)($i+1),'date'=>$date,'points'=>$p],array_keys($scores),$scores);
check('有効合計を優先',SR::compare($rows([20,10]),$rows([15,14]),3)<0);
check('同合計なら有効高得点回数',SR::compare($rows([20,10]),$rows([15,15]),3)<0);
check('有効同じなら無効戦も含む高得点回数',SR::compare($rows([20,20,10,10,5,3]),$rows([20,20,10,10,4,4]),6)<0);
check('全得点も同じなら獲得時期が遅い方',SR::compare($rows([20,10],'2027-06-01'),$rows([20,10],'2027-04-01'),3)<0);
check('完全同順位を氏名やIDで解消しない',SR::compare($rows([20,10]),$rows([20,10]),3)===0);
$bad('不正結果の日付拒否',fn()=>SR::compare($rows([1],"2027-04-01\0"),[],3));
check('申込時権利があっても停止でポイント無効',SR::afterEntry('eligible',true)==='ineligible');
check('入金訂正だけの扱いは確認待ち',SR::afterEntry('eligible',false,true)==='unconfirmed');
check('申込時に無資格なら後日の情報で救済しない',SR::afterEntry('ineligible',false)==='ineligible');
$csv="event_id,class_code,entrant_id,position,points,starters\n99001,PN1,3,1,20,4\n99001,PN1,9,2,15,4\n";
check('CSVは同一イベントの置換キーと構造化結果',AR::results($csv)['replacement_key']==='event-99001'&&count(AR::results($csv)['rows'])===2);
foreach([str_replace('99001,PN1,9','99002,PN1,9',$csv),str_replace('PN1,9','PN1,3',$csv),str_replace(',15,4',',=1+1,4',$csv),str_replace(',2,15,4',',5,15,4',$csv),'%PDF-invalid'] as $invalid){$bad('複数イベント・重複・式・台数超過・PDFの採点拒否',fn()=>AR::results($invalid));}
$editor=new \Jmrc\Content\EditorService($runtime,['data_key'=>base64_encode(str_repeat('L',32))]);
$protected=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('portal_editor_drafts','portal_editor_revisions','audit_logs','portal_audit_events') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$fp=static fn()=>$db->query('CHECKSUM TABLE '.implode(',',array_map(fn($t)=>'`'.$t.'`',$protected)))->fetchAll(PDO::FETCH_ASSOC);$before=$fp();
$office=new Browser();$office->login(7);$entrant=new Browser();$entrant->login(3);$anonymous=new Browser();
check('下書き未認証拒否',$anonymous->request('/manage/editor.php')['status']===401);
foreach(['/manage/editor.php','/manage/editor.php?id=1&download=1'] as $url){check('一般会員の下書き参照拒否 '.$url,$entrant->request($url)['status']===403);}
check('一般会員の下書き書込拒否',$entrant->request('/manage/editor.php',['kind'=>'calendar'])['status']===403);
check('事務局でもCSRFなしは拒否',$office->request('/manage/editor.php',['kind'=>'calendar'])['status']===400);
foreach(array_keys(\Jmrc\Content\EditorService::KINDS) as $kind){check('4種類の非公開編集画面 '.$kind,$office->request('/manage/editor.php?kind='.$kind)['status']===200);}
$form=['_csrf'=>$office->token('/manage/editor.php'),'kind'=>'calendar','item_key'=>'lab-calendar','version'=>0,'title'=>'下書き架空','date'=>'2027-04-10','category'=>'gymkhana','description'=>'<script>alert(1)</script>'];
$post=$office->request('/manage/editor.php',$form);check('HTTPで下書き保存後のPRG',$post['status']===303);$id=(int)$db->query("SELECT id FROM portal_editor_drafts WHERE item_key='lab-calendar'")->fetchColumn();
$saved=$editor->read(7,$id);check('未公開・未送信で保存',$saved['row_version']==1&&!$saved['published']&&!$saved['sent']);
$html=$office->request('/manage/editor.php?id='.$id)['body'];check('保存文字列をHTMLとして実行しない',str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>alert'));
$cipher=$db->query('SELECT payload_cipher FROM portal_editor_revisions WHERE draft_id='.$id)->fetchColumn();check('DBは平文でなく暗号文',str_starts_with($cipher,'gcm1:')&&!str_contains($cipher,'下書き'));
$bad('旧更新版の上書き拒否',fn()=>$editor->save(7,'calendar','lab-calendar',0,$form),409);
$replay=$editor->save(7,'calendar','lab-calendar',1,$form);check('同内容は期限も版も変えない',$replay['replayed']&&$editor->read(7,$id)['edited_at']===$saved['edited_at']);
$form['description']='訂正版';$changed=$editor->save(7,'calendar','lab-calendar',1,$form);check('変更時は版を増やして前版も残す',$changed['row_version']===2&&(int)$db->query('SELECT COUNT(*) FROM portal_editor_revisions WHERE draft_id='.$id)->fetchColumn()===2);
foreach(array_keys(AR::CALENDAR) as $category){$f=$form;$f['category']=$category;$editor->save(7,'calendar','category-'.$category,0,$f);check('7分類の予定保存 '.$category,true);}
$bad('未定義の分類を拒否',fn()=>$editor->save(7,'calendar','bad-kind',0,array_merge($form,['category'=>'unknown'])));
$mail=$editor->save(7,'mail_template','lab-mail',0,['title'=>'案内','purpose'=>'award','body'=>'確認用']);check('メール文案保存は未送信',$editor->read(7,$mail['id'])['data']['send_enabled']===false);
$result=$editor->save(7,'result_csv','event-99001',0,['title'=>'確認結果','bytes'=>$csv]);$again=$editor->save(7,'result_csv','event-99001',1,['title'=>'確認結果訂正','bytes'=>str_replace(',20,4',',19,4',$csv)]);
check('訂正結果は二重加算せず同じ記録に差替え',$result['id']===$again['id']&&count($editor->read(7,$result['id'])['data']['result']['rows'])===2);
$bad('別イベントキーの登録拒否',fn()=>$editor->save(7,'result_csv','event-2',0,['title'=>'誤り','bytes'=>$csv]));
$bad('PDFを結果CSVとして採点しない',fn()=>$editor->save(7,'result_csv','event-2',0,['title'=>'誤り','bytes'=>'%PDF-1.4']));
$pdf="%PDF-1.4\n% fixture\n%%EOF\n";$file=$editor->save(7,'document_pdf','lab-pdf',0,['title'=>'架空PDF','bytes'=>$pdf]);$r=$office->request('/manage/editor.php?id='.$file['id'].'&download=1');
check('PDFは認可した原本の添付ダウンロード',$r['status']===200&&$r['body']===$pdf&&str_contains(implode('\n',$office->lastHeaders),'attachment; filename=')&&str_contains(implode('\n',$office->lastHeaders),'application/octet-stream'));
$bad('巨大PDF拒否',fn()=>$editor->save(7,'document_pdf','big',0,['title'=>'巨大','bytes'=>'%PDF-'.str_repeat('A',2097152).'%%EOF']));
$bad('非PDF拒否',fn()=>$editor->save(7,'document_pdf','wrong',0,['title'=>'不正','bytes'=>'<html>悪意</html>']));
$db->exec("UPDATE portal_editor_drafts SET edited_at=UTC_TIMESTAMP()-INTERVAL 4 MONTH,expires_at=UTC_TIMESTAMP()-INTERVAL 1 MONTH WHERE id=$id");
$bad('期限切れは参照不可',fn()=>$editor->read(7,$id),404);$bad('期限切れ同内容の再送でも延長しない',fn()=>$editor->save(7,'calendar','lab-calendar',2,$form),409);
check('期限切れは一覧から除く',!in_array($id,array_column($editor->listing(7),'id')));
$db->exec('RENAME TABLE portal_audit_events TO lab_editor_audit_unavailable');
try{try{$editor->save(7,'mail_template','audit-fail',0,['title'=>'失敗','purpose'=>'award']);check('監査故障時の中断',false);}catch(PDOException){check('監査故障時に下書きもロールバック',(int)$db->query("SELECT COUNT(*) FROM portal_editor_drafts WHERE item_key='audit-fail'")->fetchColumn()===0);}}finally{$db->exec('RENAME TABLE lab_editor_audit_unavailable TO portal_audit_events');}
check('下書き保存で既存業務・送信待ち・会費を変えない',$before===$fp());
