<?php
// Path: public/manage/accounting-review.php
// 機能: 事務局限定の会計・予算・受領資料の検証画面。操作はログイン中の確認データだけに反映する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
use Jmrc\Auth\HttpFailure;
use Jmrc\Operations\AccountingReview as Accounting;
use Jmrc\Operations\MaterialsReview as Materials;
$auth=jmrc_auth();$actor=$auth->requireIdentity()['id'];$auth->requirePermission('profiles.correct');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$text=static function(array $in,string $key,string $default=''):string{$v=$in[$key]??$default;if(!is_string($v)){throw new HttpFailure(422,'入力の形式を確認してください');}return $v;};
$sections=['budget'=>'年度予算','income'=>'収支報告・監査出力','balance'=>'貸借対照表','csv'=>'CSVの形式確認','club'=>'クラブ申請の入力項目','notice'=>'通知文面'];
$section=$text($_GET,'section','budget');if(!isset($sections[$section])){throw new HttpFailure(404,'確認画面がありません');}
$state=$_SESSION['jmrc_accounting_review']??null;
if(!is_array($state)||($state['schema']??null)!==1||($state['owner']??null)!==$actor||time()-($state['created']??0)>=7200){$state=Materials::initial($actor);}
$error='';$preview=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $auth->verifyCsrf($_POST['_csrf']??null);
    try{
        $action=$text($_POST,'action');$bytes=null;
        if(in_array($action,['csv_preview','budget_import'],true)){
            $file=$_FILES['csv_file']??null;
            if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||filesize($file['tmp_name'])>2097152){throw new HttpFailure(422,'CSVファイルは2MiB以内で指定してください');}$bytes=file_get_contents($file['tmp_name']);
            }else{$bytes=$text($_POST,'csv_text');}
        }
        if($action==='csv_preview'){
            $kind=$text($_POST,'csv_kind');$encoding=$text($_POST,'encoding','UTF-8');
            if($kind==='yucho'){$preview=(new \Jmrc\Bank\YuchoCsv())->parse($bytes,$encoding);}
            elseif($kind==='payment_core'){$year=$text($_POST,'payment_year');$preview=(new \Jmrc\Bank\PaymentCoreCsv())->parse($bytes,$encoding,$year===''?null:Accounting::year($year));}
            else{throw new HttpFailure(422,'CSVの種類を選択してください');}
            // アップロード内容や個人情報をセッション・業務表・ログに保存しない。再取込・照合・入金には接続しない。
        }else{
            $state=Materials::apply($state,$_POST,$bytes);$_SESSION['jmrc_accounting_review']=$state;
            header('Location: /manage/accounting-review.php?section='.$section.'&saved=1',true,303);exit;
        }
    }catch(HttpFailure $e){http_response_code($e->status);$error=$e->getMessage();}
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET, POST');http_response_code(405);exit('GETまたはPOSTを使用してください');}
$_SESSION['jmrc_accounting_review']=$state;
$year=Accounting::year($_GET['year']??$state['year']);$budget=$state['budgets'][$year]??Accounting::budget([]);
$start=$text($_GET,'start',$year.'-01-01');$end=$text($_GET,'end',$year.'-12-31');$report=Accounting::report($state['ledger'],$budget,$year,$start,$end);
$month=$text($_GET,'month',$state['month']);Accounting::date($month.'-01');
$balanceInput=$state['balances'][$month]??['current'=>[],'previous'=>[]];$balance=Accounting::balance($balanceInput['current'],$balanceInput['previous']);
$download=$text($_GET,'download');
if($download!==''){
    if($error!==''){throw new HttpFailure(422,'入力を確認してください');}
    $rows=[['操作確認専用・正式帳票ではありません']];
    if($download==='budget'){$rows=[['年度','科目コード','予算額（円）']];foreach($budget as $key=>$value){$rows[]=[$year,$key,$value];}}
    elseif($download==='income'){
        $rows[]=['年度',$year,'期間',$start,$end];$rows[]=['科目','区分','期間実績','年初からの累計','年度予算','残額','執行率（%）'];
        foreach($report['items'] as $item){$rows[]=[$item['label'],$item['kind']==='income'?'収入':'支出',$item['period'],$item['ytd'],$item['budget'],$item['remaining'],$item['rate']];}$rows[]=['収入合計',$report['income']];$rows[]=['支出合計',$report['expense']];$rows[]=['収支差額',$report['net']];
    }elseif($download==='audit'){
        $rows[]=['期間',$start,$end];$rows[]=['確認用明細ID','連携元','登録日','適用日','項目','費目明細','備考','金額'];
        foreach($report['audit'] as $r){$rows[]=[$r['key'],$r['source'],$r['registered_at'],$r['date'],Accounting::ITEMS[$r['item']][0],$r['detail'],$r['memo'],$r['amount']];}
    }elseif($download==='balance'){
        $rows[]=['対象月',$month,'状態',isset($state['closed'][$month])?'確認用に確定':'未確定'];$rows[]=['科目','区分','比較残高','現在額','差額'];foreach($balance['items'] as $r){$rows[]=[$r['label'],$r['kind'],$r['previous'],$r['current'],$r['difference']];}$rows[]=['貸借の差額',$balance['difference']];
    }elseif(in_array($download,['yucho','payment_core'],true)){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jmrc-fictional-'.$download.'.csv"');echo Materials::sampleCsv($download);exit;
    }else{throw new HttpFailure(422,'出力を選択してください');}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jmrc-draft-'.$download.'-'.$year.'.csv"');echo Accounting::csv($rows);exit;
}
if($text($_GET,'format')==='json'){
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['revision'=>$state['revision'],'instance'=>$state['instance'],'year'=>$year,'month'=>$month,'budget'=>$budget,'report'=>$report,'balance'=>$balance,'preview'=>$preview,'business_writes'=>false,'mail_sent'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$yen=static fn($v)=>$v===null?'未入力':number_format($v).'円';
$hidden=static function(string $action)use($auth,$state,$e):void{echo '<input type="hidden" name="_csrf" value="'.$e($auth->csrfToken()).'"><input type="hidden" name="instance" value="'.$e($state['instance']).'"><input type="hidden" name="revision" value="'.$state['revision'].'"><input type="hidden" name="action" value="'.$e($action).'">';};
require_once __DIR__.'/_office-layout.php';jmrc_office_header('予算・会計と追加資料の確認');
?>
<link rel="stylesheet" href="/manage/assets/accounting-review.css?v=20261113">
<aside class="office-readonly"><strong>事務局専用の検証画面です</strong><p>最初に表示する会費は架空の例です。操作した内容は本番の会費・入金・資格に反映されません。正式な会計科目・計上時点・承認方法を確認中です。会計・クラブ入力は架空データで試してください。</p><p>入力結果はこのサインイン中だけ保持します。2時間経過後の再表示、サインアウト、初期化で利用できなくなります。予算は終了前に「予算をCSVに保存」で手元に保存すると、次回読み込めます。</p></aside>
<nav class="office-actions" aria-label="追加資料の確認項目"><?php foreach($sections as $key=>$label):?><a class="office-button <?=$key===$section?'':'secondary'?>" href="?section=<?=$e($key)?>"><?=$e($label)?></a><?php endforeach;?></nav>
<?php if($error!==''):?><p role="alert" class="accounting-error"><?=$e($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p role="status" class="office-success">確認用データに反映しました。正式登録・入金・送信はしていません。</p><?php endif;?>
<?php if($section==='budget'):?>
<section class="office-panel"><h2>年度・科目ごとの予算</h2><ol><li>年度と予算額を入力します。空欄は未入力、0は予算0円です。</li><li>「確認用予算に反映する」を押すと、下の表に残額と執行率が表示されます。</li><li>終了前に予算をCSVへ保存してください。正式な予算承認や補正手続きはまだ行えません。</li></ol>
<form method="get" class="office-form"><input type="hidden" name="section" value="budget"><div class="office-field"><label for="view-year">表示する年度</label><input id="view-year" name="year" type="number" min="2000" max="2099" value="<?=$year?>"></div><div class="office-submit"><button class="office-button secondary">年度を表示する</button></div></form>
<form method="post" class="office-form"><?php $hidden('budget');?><div class="office-field"><label for="budget-year">入力する年度</label><input id="budget-year" name="year" type="number" min="2000" max="2099" required value="<?=$year?>"></div><p class="office-wide">科目と初期値は確認用です。年度・科目は正式資料を受け取ってから確定します。</p>
<?php foreach(Accounting::ITEMS as $key=>[$label,$kind]):?><div class="office-field"><label for="budget-<?=$key?>"><?=$e($label)?>（<?=$kind==='income'?'収入':'支出'?>予算・円）</label><input id="budget-<?=$key?>" name="amounts[<?=$key?>]" type="number" min="0" max="1000000000" step="1" value="<?=$e($budget[$key])?>"></div><?php endforeach;?><div class="office-submit"><button class="office-button">確認用予算に反映する</button></div></form>
<p><a class="office-button secondary" href="?download=budget&amp;year=<?=$year?>">予算をCSVに保存</a></p>
<form method="post" enctype="multipart/form-data" class="office-form"><?php $hidden('budget_import');?><div class="office-field"><label for="budget-file">保存した予算CSV</label><input id="budget-file" name="csv_file" type="file" accept=".csv,text/csv" required></div><div class="office-submit"><button class="office-button secondary">予算CSVを読み込む</button></div></form>
</section>
<?php endif;?>
<?php if(in_array($section,['budget','income'],true)):?>
<section class="office-panel"><h2>予算と収支の比較</h2><p>対象：<?=$year?>年 <?=$e($start)?>～<?=$e($end)?>。残額は年度予算から終了日までの累計を引いた金額です。予算が0円・未入力の場合、執行率は計算しません。</p>
<dl class="office-definition"><dt>期間の収入</dt><dd><?=$yen($report['income'])?></dd><dt>期間の支出</dt><dd><?=$yen($report['expense'])?></dd><dt>収支差額</dt><dd><?=$yen($report['net'])?></dd></dl>
<div class="accounting-table" tabindex="0" role="region" aria-label="予算と実績の表。狭い画面は横にスクロールできます"><table><thead><tr><th>科目</th><th>期間実績</th><th>年初からの累計</th><th>年度予算</th><th>残額</th><th>執行率</th></tr></thead><tbody><?php foreach($report['items'] as $r):?><tr><th scope="row"><?=$e($r['label'])?></th><td><?=$yen($r['period'])?></td><td><?=$yen($r['ytd'])?></td><td><?=$yen($r['budget'])?></td><td><?=$yen($r['remaining'])?></td><td><?=$r['rate']===null?'対象外':$e($r['rate']).'%'?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php if($section==='income'):?>
<section class="office-panel"><h2>集計期間と出力</h2><form method="get" class="office-form"><input type="hidden" name="section" value="income"><div class="office-field"><label for="report-year">集計年度</label><input id="report-year" type="number" name="year" min="2000" max="2099" value="<?=$year?>" required></div><div class="office-field"><label for="start">開始日</label><input id="start" type="date" name="start" value="<?=$e($start)?>" required></div><div class="office-field"><label for="end">終了日</label><input id="end" type="date" name="end" value="<?=$e($end)?>" required></div><div class="office-submit"><button class="office-button">収支を集計する</button></div></form>
<div class="office-actions"><?php foreach(['income'=>'収支報告をCSVに保存','audit'=>'期間内の明細を監査用CSVに保存'] as $kind=>$label):?><a class="office-button secondary" href="?download=<?=$kind?>&amp;year=<?=$year?>&amp;start=<?=$e($start)?>&amp;end=<?=$e($end)?>"><?=$label?></a><?php endforeach;?></div><p>帳票は確認用です。財務データの保存例：<?=$year?>年分は<?=$e($report['retained_through'])?>まで。範囲と削除方法は回答待ちで、自動削除はしません。この画面の一時保存とは別の要件です。</p></section>
<section class="office-panel"><h2>その他の収入・支出を入力</h2><form method="post" class="office-form"><?php $hidden('manual');?><div class="office-field"><label for="date">適用日（金銭授受があった日）</label><input id="date" type="date" name="date" required value="<?=$e($state['month'])?>-15"></div><div class="office-field"><label for="item">項目</label><select id="item" name="item"><?php foreach(Accounting::ITEMS as $key=>[$label,$kind]):?><option value="<?=$key?>"><?=$e($label)?>（<?=$kind==='income'?'収入':'支出'?>）</option><?php endforeach;?></select></div><div class="office-field"><label for="amount">金額（円）</label><input id="amount" name="amount" type="number" min="0" max="1000000000" required></div><div class="office-field"><label for="detail">費目明細</label><input id="detail" name="detail" maxlength="200"></div><div class="office-field office-wide"><label for="memo">備考</label><textarea id="memo" name="memo" rows="3" maxlength="1000"></textarea></div><div class="office-submit"><button class="office-button">確認用明細を追加する</button></div></form>
<h3>会費の自動連携を試す</h3><p>架空のクラブ会費30,000円と個人会費2,000円を読み直します。同じ連携元を繰り返しても明細は増えません。本番会費の計上日は未確定です。</p><form method="post"><?php $hidden('sync');?><button class="office-button secondary">架空の会費を再連携する</button></form></section>
<?php endif;?>
<?php if($section==='balance'):?>
<section class="office-panel"><h2>月末残高と差額</h2><p>各科目に現在額を入力します。資産の差額は比較残高から計算します。貸借が合わない場合は差額を表示し、自動で埋めません。</p><form method="get" class="office-form"><input type="hidden" name="section" value="balance"><div class="office-field"><label for="view-month">表示する月</label><input id="view-month" type="month" name="month" value="<?=$e($month)?>" required></div><div class="office-submit"><button class="office-button secondary">月を表示する</button></div></form>
<form method="post" class="office-form"><?php $hidden('balance');?><input type="hidden" name="month" value="<?=$e($month)?>"><?php foreach(Accounting::BALANCE as $key=>[$label,$kind]):?><div class="office-field"><label for="current-<?=$key?>"><?=$e($label)?>・現在額（円）</label><input id="current-<?=$key?>" type="number" name="current[<?=$key?>]" min="<?=$kind==='equity'?'-1000000000':'0'?>" max="1000000000" value="<?=$e($balance['items'][$key]['current'])?>"></div><div class="office-field"><label for="previous-<?=$key?>"><?=$e($label)?>・比較残高（円）</label><input id="previous-<?=$key?>" type="number" name="previous[<?=$key?>]" min="<?=$kind==='equity'?'-1000000000':'0'?>" max="1000000000" value="<?=$e($balance['items'][$key]['previous'])?>"></div><?php endforeach;?><div class="office-submit"><button class="office-button">確認用残高に反映する</button></div></form>
<dl class="office-definition"><?php foreach($balance['items'] as $r):?><dt><?=$e($r['label'])?></dt><dd>現在額 <?=$yen($r['current'])?> / 差額 <?=$yen($r['difference'])?></dd><?php endforeach;?><dt>資産と負債・剰余金の差額</dt><dd><?=$yen($balance['difference'])?>（<?=$balance['balanced']?'一致':'未入力または不一致'?>）</dd></dl>
<p>月末確定の操作は前月以前で試せます。正式な承認・締め解除は未接続です。確認用に確定した月の再入力も止めます。</p><form method="post"><?php $hidden('close');?><input type="hidden" name="month" value="<?=$e($month)?>"><button class="office-button secondary">この月を確認用に確定する</button></form><p>状態：<?=isset($state['closed'][$month])?'確認用に確定済み':'未確定'?></p><a class="office-button secondary" href="?download=balance&amp;month=<?=$e($month)?>">貸借対照表をCSVに保存</a></section>
<?php endif;?>
<?php if($section==='csv'):?>
<section class="office-panel"><h2>受領したCSVの形式確認</h2><p>ファイルはこの応答の解析にだけ使い、明細や口座・連絡先を保存しません。解析結果は正式な入金ではありません。ペイメントコアは年・取引ID・決済状態の確認が必要で、銀行へのまとめ振込とも自動照合しません。</p><div class="office-actions"><a class="office-button secondary" href="?download=yucho">ゆうちょの架空CSV</a><a class="office-button secondary" href="?download=payment_core">ペイメントコアの架空CSV</a></div>
<form method="post" enctype="multipart/form-data" class="office-form"><?php $hidden('csv_preview');?><div class="office-field"><label for="csv-kind">CSVの種類</label><select id="csv-kind" name="csv_kind"><option value="yucho">ゆうちょ</option><option value="payment_core">ペイメントコア</option></select></div><div class="office-field"><label for="encoding">文字コード</label><select id="encoding" name="encoding"><option value="UTF-8">UTF-8（架空CSV・ペイメントコア）</option><option value="CP932">CP932（受領したゆうちょ）</option></select></div><div class="office-field"><label for="payment-year">ペイメントコアの決済年（証跡で確認できた場合だけ）</label><input id="payment-year" type="number" name="payment_year" min="2000" max="2099"></div><div class="office-field"><label for="csv-file">CSVファイル（2MiB以内）</label><input id="csv-file" type="file" name="csv_file" accept=".csv,text/csv" required></div><div class="office-submit"><button class="office-button">CSVの内容を確認する</button></div></form>
<?php if($preview):?><h3>解析結果（保存していません）</h3><p><?=count($preview['rows'])?>明細。手動確認が必要です。</p><?php if(isset($preview['ignored'])):?><p>支出等で入金対象外：<?=$preview['ignored']?>件。</p><?php endif;?><?php foreach(array_slice($preview['rows'],0,50) as $r):?><article class="office-history"><p>行 <?=$r['record_no']?> / 日付 <?=$e($r['date']??'年または日付を確認してください')?></p><?php if(isset($r['gross_yen'])):?><p>指定名義：<?=$e($r['specified_name'])?> / 支払額 <?=$yen($r['gross_yen'])?> / 手数料 <?=$yen($r['fee_yen'])?> / 差引額 <?=$yen($r['net_yen'])?></p><p><?=$r['duplicate_candidate']?'同じ内容の行があります。別取引か確認してください。':'取引ID・決済状態がないため自動確定しません。'?></p><?php else:?><p>名義：<?=$e($r['name'])?> / 受入額 <?=$yen($r['amount'])?></p><?php endif;?></article><?php endforeach;?><?php if(count($preview['rows'])>50):?><p>画面表示は先頭50件です。全件の形式検査は完了しています。</p><?php endif;?><?php endif;?></section>
<?php endif;?>
<?php if($section==='club'):?>
<section class="office-panel"><h2>クラブ加盟登録申請の項目</h2><p>提供された申請書1ページ目の項目を確認できます。代表者・連絡担当者・送付先を別に持ちます。必須項目、添付、ハンドブック掲載の選択肢は確認待ちです。2ページ目の提出機能は作りません。実際のクラブ登録へは接続していません。</p><form method="post" class="office-form"><?php $hidden('club');?><?php foreach(Materials::CLUB_FIELDS as $key=>$label):?><div class="office-field"><label for="club-<?=$key?>"><?=$e($label)?></label><input id="club-<?=$key?>" name="club[<?=$key?>]" maxlength="255" value="<?=$e($state['club'][$key]??'')?>"></div><?php endforeach;?><div class="office-submit"><button class="office-button">架空の入力例を反映する</button></div></form></section>
<?php endif;?>
<?php if($section==='notice'):?>
<section class="office-panel"><h2>クラブ宛ての通知文面</h2><p>スポーツ安全保険の完了通知は正式文面の提供待ちです。2028年度以降のクラブ更新案内も下書きを準備できます。加入証明書の発行は対象外です。通知先・完了判定・承認方法を確認してから送信機能へ接続します。</p><?php foreach(['insurance_completed'=>'スポーツ安全保険の手続き完了','club_renewal_2028'=>'2028年度以降のクラブ更新'] as $kind=>$label):?><h3><?=$label?></h3><pre class="accounting-notice"><?=$e(Materials::notice($kind))?></pre><?php endforeach;?><a class="office-button secondary" href="/manage/editor.php?kind=mail_template">通知文面を下書きに保存する</a></section>
<?php endif;?>
<section class="office-panel"><h2>確認用データを最初の状態に戻す</h2><p>この画面で試した予算・明細・残高・クラブ入力だけを初期化します。必要な予算は先にCSVへ保存してください。</p><form method="post"><?php $hidden('reset');?><button class="office-button secondary">確認用データを初期化する</button></form></section>
<?php jmrc_office_footer(); ?>
