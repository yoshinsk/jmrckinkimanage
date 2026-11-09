<?php
// Path: public/manage/editor.php
// 機能: 事務局の非公開下書き編集。CSRF・更新版・権限を確認し、PDFは認可済みの添付ダウンロードだけにする。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
use Jmrc\Auth\HttpFailure;
use Jmrc\Content\EditorService;
use Jmrc\Operations\AnswerRules;
$auth=jmrc_auth();$actor=$auth->requireIdentity()['id'];$service=new EditorService(jmrc_db(),jmrc_auth_config());$service->access($actor);
$string=static function(array $input,string $key,string $default=''):string{$v=$input[$key]??$default;if(!is_string($v)){throw new HttpFailure(422,'入力の形式を確認してください');}return $v;};
$integer=static function(array $input,string $key,int $minimum):int{$v=filter_var($input[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum,'max_range'=>2147483647]]);if($v===false||$v===null){throw new HttpFailure(422,'番号・更新版を確認してください');}return $v;};
if($_SERVER['REQUEST_METHOD']==='POST'){
    $auth->verifyCsrf($_POST['_csrf']??null);$kind=$string($_POST,'kind');$input=$_POST;
    if(in_array($kind,['document_pdf','result_csv'],true)){
        // クライアントのパスや元ファイル名は使わず、PHPが受領した一時ファイルだけ読む。
        $file=$_FILES['attachment']??null;
        if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            $limit=$kind==='document_pdf'?2097152:524288;
            if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||filesize($file['tmp_name'])>$limit){throw new HttpFailure(422,'ファイルの種類・大きさを確認してください');}
            $input['bytes']=file_get_contents($file['tmp_name']);
        }elseif($kind==='document_pdf'){throw new HttpFailure(422,'PDFを選択してください。訂正時も原本が必要です');}
        else{$input['bytes']=$string($_POST,'csv');}
    }
    $saved=$service->save($actor,$kind,$string($_POST,'item_key'),$integer($_POST,'version',0),$input);
    header('Location: /manage/editor.php?id='.$saved['id'].'&saved=1',true,303);exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET, POST');http_response_code(405);exit('GETまたはPOSTを使用してください');}
$record=isset($_GET['id'])?$service->read($actor,$integer($_GET,'id',1)):null;
if(isset($_GET['download'])){
    if(!$record||$record['kind']!=='document_pdf'){throw new HttpFailure(404,'PDFがありません');}
    header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="draft-'.$record['id'].'-v'.$record['row_version'].'.pdf"');
    echo base64_decode($record['data']['file_base64'],true);exit;
}
$kind=$record['kind']??$string($_GET,'kind','calendar');if(!isset(EditorService::KINDS[$kind])){throw new HttpFailure(422,'下書きの種類を選んでください');}
$data=$record['data']??[];$items=$service->listing($actor);$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$dateLabel=static fn($v)=>(new DateTimeImmutable($v,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y年m月d日 H:i');
require_once __DIR__.'/_office-layout.php';jmrc_office_header('資料・日程・文面の下書き');
?>
<p class="office-intro">事務局の担当者だけで内容を準備する画面です。保存した内容は一般公開されず、メール送信・ポイント集計・申込受付にも使われません。</p>
<p class="office-readonly">正式な規程・公開項目・承認手順の確定を待っています。公開・送信のボタンはありません。</p>
<ol><li>下書きの種類を選びます。</li><li>件名と内容を入力し、「下書きを保存する」を押します。</li><li>訂正するときは下の「保存した下書き」から開き直します。前の版も記録に残ります。</li></ol>
<p>保存期限は最後に内容を変更してから3か月です。開くだけ、同じ内容を保存するだけでは延長されません。期限後は画面から開けません。物理削除はまだ実行しません。ブラウザーを閉じても現在のログイン有効期間内はサインイン状態が続きます。共用端末ではサインアウトしてください。</p>
<nav class="office-actions" aria-label="下書きの種類"><?php foreach(EditorService::KINDS as $key=>$label): ?><a class="office-button secondary" href="?kind=<?=$e($key)?>"><?=$e($label)?></a><?php endforeach; ?></nav>
<?php if(isset($_GET['saved'])): ?><p class="office-success" role="status">下書きを保存しました。下の内容を確認してください。公開・送信はされていません。</p><?php endif; ?>
<section class="office-panel"><h2><?=$e(EditorService::KINDS[$kind])?></h2>
<?php if($record): ?><p>更新版：<?=$e($record['row_version'])?> / 最終編集：<?=$e($dateLabel($record['edited_at']))?> / 保存期限：<?=$e($dateLabel($record['expires_at']))?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="office-form">
<input type="hidden" name="_csrf" value="<?=$e($auth->csrfToken())?>"><input type="hidden" name="kind" value="<?=$e($kind)?>"><input type="hidden" name="version" value="<?=$e($record['row_version']??0)?>">
<div class="office-field"><label for="item-key">管理キー（半角英数字と - _ ）</label><input id="item-key" name="item_key" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" required value="<?=$e($record['item_key']??'')?>" <?=$record?'readonly':''?>><p class="office-help">同じ資料の訂正版には同じキーを使います。例：meeting-2027-01</p></div>
<div class="office-field"><label for="title">件名</label><input id="title" name="title" maxlength="200" required value="<?=$e($data['title']??'')?>"></div>
<?php if($kind==='calendar'): ?>
<div class="office-field"><label for="date">日付</label><input id="date" name="date" type="date" required value="<?=$e($data['date']??'')?>"></div>
<div class="office-field"><label for="category">予定の分類</label><select id="category" name="category"><?php foreach(AnswerRules::CALENDAR as $key=>$label): ?><option value="<?=$e($key)?>" <?=($data['category']??null)===$key?'selected':''?>><?=$e($label)?></option><?php endforeach; ?></select></div>
<div class="office-field office-wide"><label for="description">予定の説明</label><textarea id="description" name="description" rows="5" maxlength="2000"><?=$e($data['description']??'')?></textarea></div><p class="office-wide">事務局が実際の日程を入力します。現在は事務局長への承認依頼・一般公開には接続していません。</p>
<?php elseif($kind==='mail_template'): ?>
<div class="office-field"><label for="purpose">通知の種類</label><select id="purpose" name="purpose"><?php foreach(AnswerRules::MAIL as $key=>$label): ?><option value="<?=$e($key)?>" <?=($data['purpose']??null)===$key?'selected':''?>><?=$e($label)?></option><?php endforeach; ?></select></div>
<div class="office-field office-wide"><label for="body">本文</label><textarea id="body" name="body" rows="10" maxlength="10000"><?=$e($data['body']??'')?></textarea></div><p class="office-wide">事務局が文面を確認し、任意の時点で手動送信する方針です。12月の正式文面登録を待ち、ここでは文案のみ保存します。宛先は登録しません。</p>
<?php elseif($kind==='result_csv'): ?>
<p class="office-wide">同一イベントの訂正版は一覧から開き、全件を差し替えます。管理キーは event-イベントID（例：event-123）です。UTF-8形式・512KB以内・1,000行以内で、下の6列を使います。得点は部会長が確認した整数を入力します。PDFからの自動採点は行いません。現在は共通IDとの実在照合・公式集計に接続していない準備用です。</p>
<div class="office-field office-wide"><label for="csv">結果CSV（ファイルを選ばない場合はここに貼り付け）</label><textarea id="csv" name="csv" rows="8" maxlength="524288" placeholder="event_id,class_code,entrant_id,position,points,starters"><?php
if(isset($data['result'])){echo "event_id,class_code,entrant_id,position,points,starters\n";foreach($data['result']['rows'] as $row){echo $e(implode(',',[$row['event_id'],$row['class_code'],$row['entrant_id'],$row['position'],$row['points'],$row['starters']]))."\n";}}
?></textarea><p class="office-help">列の意味：イベントID、クラス記号、共通ユーザーID、順位、得点、そのクラスの出走台数。1ファイルは1イベントです。</p></div>
<div class="office-field"><label for="attachment">結果CSVファイル（任意）</label><input id="attachment" type="file" name="attachment" accept=".csv,text/csv"></div>
<?php else: ?>
<p class="office-wide">正式規程・クラス規程・役員名簿・結果原本などのPDFを、2MB以内で保存できます。画面内では開かずダウンロードします。訂正時は差し替えるPDFを選び直してください。</p>
<div class="office-field"><label for="attachment">資料PDF</label><input id="attachment" type="file" name="attachment" accept=".pdf,application/pdf" required></div>
<?php if($record): ?><p class="office-wide"><a class="office-button secondary" href="?id=<?=$e($record['id'])?>&amp;download=1">保存したPDFをダウンロード</a></p><?php endif; ?>
<?php endif; ?>
<div class="office-submit"><button class="office-button" type="submit"><?=$record?'訂正版を保存する':'下書きを保存する'?></button></div></form></section>
<section class="office-panel"><h2>保存した下書き</h2><p>期限内の直近200件です。対象が見つからない場合は事務局の担当者へ確認してください。</p>
<?php if(!$items): ?><p>保存した下書きはありません。</p><?php endif; ?>
<?php foreach($items as $item): ?><article class="office-history"><h3><?=$e(EditorService::KINDS[$item['kind']])?>：<?=$e($item['item_key'])?></h3><p>更新版 <?=$e($item['row_version'])?> / 保存期限 <?=$e($dateLabel($item['expires_at']))?></p><a class="office-button secondary" href="?id=<?=$e($item['id'])?>">この下書きを開く</a></article><?php endforeach; ?></section>
<?php jmrc_office_footer(); ?>
