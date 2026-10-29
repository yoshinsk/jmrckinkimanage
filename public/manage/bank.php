<?php
// Path: public/manage/bank.php
// 機能: 会計担当者のゆうちょCSV取込・照合候補提示・新規入金/既存紐付け/重複確認を提供する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\Bank\BankService(jmrc_db(),jmrc_auth_config());$service->access($user['id']);
$json=($_GET['format']??'')==='json';$result=null;
$id=static function($v,bool $zero=false):int{$n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1]]);if($n===false){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}return $n;};
$source=isset($_GET['source'])?$id($_GET['source']):null;$rowId=isset($_GET['row'])?$id($_GET['row']):null;$after=isset($_GET['after'])?$id($_GET['after'],true):0;
if($_SERVER['REQUEST_METHOD']==='POST'){
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    if(($_POST['action']??'')==='import'){
        $file=$_FILES['csv']??null;$text=$_POST['csv_text']??null;
        if($file!==null){
            if($text!==null && $text!==''){throw new \Jmrc\Auth\HttpFailure(422,'ファイルまたはテキストの一方を指定してください');}
            if(!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || ($file['size']??0)>2097152){throw new \Jmrc\Auth\HttpFailure(422,'2MiB以内のCSVを選択してください');}
            $text=file_get_contents($file['tmp_name']);
        }
        if(!is_string($text) || !is_string($_POST['encoding']??null)){throw new \Jmrc\Auth\HttpFailure(422,'CSVと文字コードを指定してください');}
        $result=$service->import($user['id'],$id($_POST['source']??null),$text,$_POST['encoding']);
    }elseif(($_POST['action']??'')==='resolve'){
        foreach(['mode','date','reason'] as $key){if(!is_string($_POST[$key]??null)){throw new \Jmrc\Auth\HttpFailure(422,'確認内容を入力してください');}}
        $result=$service->resolve($user['id'],$id($_POST['row']??null),$id($_POST['version']??null),$_POST['mode'],$id($_POST['invoice']??0,true),$id($_POST['payment']??0,true),$_POST['date'],$_POST['reason'],($_POST['legacy_checked']??'')==='1',($_POST['separate_transfer']??'')==='1',($_POST['manual_date_checked']??'')==='1');
    }elseif(($_POST['action']??'')==='split'){
        if(!is_string($_POST['reference']??null) || !is_string($_POST['reason']??null) || ($_POST['separate_transfer']??'')!=='1'){throw new \Jmrc\Auth\HttpFailure(422,'別実取引の証跡確認が必要です');}
        $result=$service->split($user['id'],$id($_POST['row']??null),$_POST['reference'],$_POST['reason']);
    }else{throw new \Jmrc\Auth\HttpFailure(422,'操作を指定してください');}
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$detail=$rowId?$service->read($user['id'],$rowId):null;$rows=$source?$service->listing($user['id'],$source,$after):[];$sources=$service->sources($user['id']);
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result??$detail??['rows'=>$rows,'sources'=>$sources],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$statusText=static fn($v)=>['review'=>'要確認','confirmed'=>'照合済み','duplicate'=>'重複確認済み'][$v]??'確認待ち';
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>銀行CSV・入金照合</title><link rel="stylesheet" href="/manage/assets/cards.css"><link rel="stylesheet" href="/manage/assets/licenses.css"><link rel="stylesheet" href="/manage/assets/insurance.css"></head><body><main><h1>銀行CSV・入金照合</h1><p>取込だけでは入金を確定しません。候補と請求・入金日を確認してください。同じ取扱日・金額・名義の明細は重複候補としてまとめます。実際に複数の振込がある場合も件数から自動加算しません。</p>
<?php if($result): ?><p role="status">処理を記録しました。#<?=$e($result['id'])?><?php if(isset($result['new_rows'])): ?> ／ 新規明細 <?=$e($result['new_rows'])?>件<?php endif; ?></p><?php if(isset($result['new_rows'])): ?><p><a href="?source=<?=$e($_POST['source'])?>">取込元の明細へ</a></p><?php endif; ?><?php endif; ?>
<h2>CSV取込</h2><form class="license-form" method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="import"><label>実口座の取込元<select name="source" aria-label="実口座の取込元" required><?php foreach($sources as $s): ?><option value="<?=$e($s['id'])?>"><?=$e($s['display_name'])?></option><?php endforeach; ?></select></label><label>文字コード<select name="encoding" aria-label="文字コード"><option value="CP932">Shift-JIS（CP932）</option><option value="UTF-8">UTF-8</option></select></label><label>ゆうちょCSV<input type="file" name="csv" accept=".csv,text/csv" required></label><button>CSVを取込む</button></form>
<h2>取込元の明細</h2><ul><?php foreach($sources as $s): ?><li><a href="?source=<?=$e($s['id'])?>"><?=$e($s['display_name'])?></a></li><?php endforeach; ?></ul><?php foreach($rows as $r): ?><p><a href="?row=<?=$e($r['id'])?>"><?=$e('#'.$r['id'].' '.($r['receipt_date']??'日付未確認').' '.$r['payer_name'].' '.$r['amount_yen'].'円 '.$statusText($r['status']))?></a></p><?php endforeach; ?>
<?php if($source && count($rows)===100): ?><a href="?source=<?=$e($source)?>&amp;after=<?=$e(end($rows)['id'])?>">次の100件</a><?php endif; ?>
<?php if($detail): ?><h2>明細 #<?=$e($detail['id'])?></h2><p><?=$e(($detail['receipt_date']??'日付未確認').' ／ '.$detail['payer_name'].' ／ '.$detail['amount_yen'].'円 ／ '.$statusText($detail['status']))?></p><p>照合：<?=$e($detail['match_state']==='unique_candidate'?'候補1件（未確定）':'手動確認が必要')?>。単一候補でも自動確定しません。同一ファイル内の同一条件明細：<?=$e($detail['same_file_occurrences'])?>件。</p><h3>氏名一致の請求候補</h3><ul><?php foreach($detail['candidates'] as $c): ?><li><?=$e('#'.$c['invoice_id'].' '.$c['invoice_no'].' 残額'.$c['balance_yen'].'円')?></li><?php endforeach; ?></ul><h3>同日同額の既存入金</h3><ul><?php foreach($detail['prior_payments'] as $p): ?><li><?=$e('入金 #'.$p['id'].' '.($p['source_type']==='manual'?'手動登録':'銀行CSV'))?></li><?php endforeach; ?></ul>
<?php if($detail['status']==='review'): ?><form class="license-form" method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="row" value="<?=$e($detail['id'])?>"><input type="hidden" name="version" value="<?=$e($detail['row_version'])?>"><label>処理<select name="mode"><option value="new">新しい実入金を登録・充当</option><option value="link">既登録の手動入金へ紐付け</option><option value="duplicate">既存入金と重複として記録</option></select></label><label>請求ID（新規・紐付け）<input name="invoice" type="number" min="0" value="0"></label><label>既存入金ID（紐付け・重複）<input name="payment" type="number" min="0" value="0"></label><label>確認した入金日<input name="date" type="date" value="<?=$e($detail['receipt_date']??'')?>" required></label><label>確認理由・証跡<input name="reason" maxlength="500" required></label><label><input type="checkbox" name="legacy_checked" value="1" required>既存entry・手動登録を含め、入金の重複を確認しました</label><label><input type="checkbox" name="separate_transfer" value="1">同日同額の既存入金とは別の実取引と確認しました（新規登録時のみ）</label><label><input type="checkbox" name="manual_date_checked" value="1">旧手動入金の記録日と実入金日の差異を証跡で確認しました（紐付け・重複時のみ）</label><button>確認して記録する</button></form><?php endif; ?><?php endif; ?>
<?php if($detail && $detail['status']==='confirmed' && $detail['split_from_id']===null): ?><h2>同条件の別実取引を確認</h2><p>実際に複数回の振込があった場合だけ、銀行明細等の証跡を照合して別明細を作成してください。再取込の重複に使わないでください。作成後も手動照合が必要です。</p><form class="license-form" method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="split"><input type="hidden" name="row" value="<?=$e($detail['id'])?>"><label>別実取引の証跡識別子<input name="reference" pattern="[A-Za-z0-9_.:-]{1,100}" required></label><label>確認理由<input name="reason" maxlength="500" required></label><label><input type="checkbox" name="separate_transfer" value="1" required>別の実振込である証跡を確認しました</label><button>別取引として確認待ちにする</button></form><?php endif; ?><p>日付なし・候補なし・複数候補・過不足金は資料を確認して手動指定してください。保険など未確定の請求は作成しません。訂正は<a href="/manage/billing.php">会計の逆仕訳・再充当</a>を利用してください。</p><p><a href="/manage/">マイページへ</a></p></main></body></html>
