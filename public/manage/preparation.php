<?php
// Path: public/manage/preparation.php
// 機能: 事務局向けの運用開始前確認。実DBを参照するが、GETによる確認のみで設定・業務記録を更新しない。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$actor=jmrc_auth()->requireIdentity()['id'];
$service=new \Jmrc\Operations\PreparationService(jmrc_db(),jmrc_auth_config());$service->access($actor);
if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET');http_response_code(405);exit('この画面は確認専用です'); }
$integer=static function(string $key):int { $v=filter_var($_GET[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if (!$v) { throw new \Jmrc\Auth\HttpFailure(422,'確認する番号を入力してください'); }return $v; };
$section=$_GET['section']??'overview';
if (!is_string($section) || !in_array($section,['overview','club','event','privacy','membership'],true)) { throw new \Jmrc\Auth\HttpFailure(422,'確認する項目を選択してください'); }
$data=null;
if ($section==='overview') { $data=$service->overview($actor); }
elseif (isset($_GET['id'])) {
    $id=$integer('id');
    if ($section==='club') {
        foreach (['category','membership','registration'] as $key) { if (!is_string($_GET[$key]??null)) { throw new \Jmrc\Auth\HttpFailure(422,'料金の条件を選択してください'); } }
        $data=$service->clubPlan($actor,$id,2027,$_GET['category'],$_GET['membership'],$_GET['registration']);
    } elseif ($section==='event') { $data=$service->eventPlan($actor,$id); }
    elseif ($section==='privacy') { $data=$service->personalDataInventory($actor,$id); }
    else { $data=$service->latestMembership($actor,$id); }
}
if (($_GET['format']??null)==='json') { header('Content-Type: application/json; charset=utf-8');echo json_encode(['section'=>$section,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$labels=['overview'=>'年度・入金先の設定','club'=>'クラブ請求の事前確認','event'=>'既存イベントとの接続','privacy'=>'個人情報の保存先','membership'=>'照会時点の個人会員資格'];
require_once __DIR__.'/_office-layout.php';jmrc_office_header('運用開始前の確認');
?>
<p class="office-readonly" role="status">確認専用です。この画面では受付開始、請求発行、入金、資格変更、個人情報の削除を行いません。</p>
<p class="office-intro">現在の登録・設定から、準備状況を確認します。機能の操作確認にある架空データとは別です。未確定の条件は保留として表示します。</p>
<nav class="office-actions" aria-label="確認する項目"><?php foreach ($labels as $key=>$label): ?><a class="office-button secondary" href="?section=<?=$e($key)?>"><?=$e($label)?></a><?php endforeach; ?><a class="office-button secondary" href="staff-roles.php">役職の申請・承認</a></nav>
<section class="office-panel"><h2><?=$e($labels[$section])?></h2>
<?php if ($section!=='overview'): ?>
<form method="get" class="office-form"><input type="hidden" name="section" value="<?=$e($section)?>">
<div class="office-field"><label for="target-id"><?=$section==='club'?'ポータルのクラブID':($section==='event'?'既存エントリーのイベントID':'共通ユーザーID')?></label><input id="target-id" name="id" type="number" min="1" required value="<?=$e($_GET['id']??'')?>"></div>
<?php if ($section==='club'): ?>
<p class="office-wide">2027年度の確認です。下の区分は今回の計算用で、実在クラブの正式な区分や新規・再加入を確定しません。</p>
<?php foreach (['category'=>['会費区分',\Jmrc\Club\ClubFeePolicy::CATEGORIES],'membership'=>['計算する会員区分',['regular'=>'正会員','supporting'=>'賛助会員']],'registration'=>['計算する登録方法',['new'=>'新規','renewal'=>'年度更新','rejoin'=>'再加入']]] as $key=>[$label,$options]): ?><div class="office-field"><label for="<?=$e($key)?>"><?=$e($label)?></label><select id="<?=$e($key)?>" name="<?=$e($key)?>" required><option value="">選択してください</option><?php foreach ($options as $value=>$text): ?><option value="<?=$e($value)?>" <?=($_GET[$key]??null)===$value?'selected':''?>><?=$e($text)?></option><?php endforeach; ?></select></div><?php endforeach; ?>
<?php endif; ?><div class="office-submit"><button class="office-button" type="submit">現在の状態を確認する</button></div></form>
<?php endif; ?>
<?php if ($data && $section==='overview'): ?>
<p>確認日時：<?=$e($data['checked_at'])?></p><h3>年度の参照先</h3>
<?php foreach ($data['years'] as $year): ?><p><?=$e($year['year'])?>年度：<?=$e(['shadow'=>'切替前（受付保留）','legacy'=>'既存エントリーを参照','portal'=>'ポータルを参照'][$year['source_mode']])?></p><?php endforeach; ?>
<?php if (!$data['years']): ?><p>年度設定はありません。</p><?php endif; ?>
<h3>機能の受付設定</h3><dl class="office-definition"><?php foreach (['license_self_service_enabled'=>'ライセンス本人更新','insurance_intents_enabled'=>'保険加入の意思受付','yucho_import_enabled'=>'銀行CSV取込','staff_role_requests_enabled'=>'役職の申請受付'] as $key=>$label): ?><dt><?=$e($label)?></dt><dd><?=$data['flags'][$key]?'設定あり（利用条件は各画面で確認）':'保留中'?></dd><?php endforeach; ?></dl>
<h3>登録済みの件数</h3><dl class="office-definition"><?php foreach (['clubs'=>'クラブ','club_years'=>'クラブ年度登録','event_links'=>'既存イベントとの対応','organizer_links'=>'主催者との対応','invoices'=>'請求','payments'=>'入金','bank_review'=>'手動確認待ちの銀行明細'] as $key=>$label): ?><dt><?=$e($label)?></dt><dd><?=$e($data['counts'][$key])?>件</dd><?php endforeach; ?></dl>
<h3>用途別口座・決済URL・CSV取込元</h3><p>口座番号やURLそのものは表示しません。設定内容は「入金先・決済URLの設定」で確認してください。</p>
<?php if (!$data['accounts']): ?><p>入金先口座は未登録です。</p><?php endif; ?>
<?php foreach ($data['accounts'] as $account): ?><article class="office-history"><h3>口座設定 #<?=$e($account['id'])?> / <?=$e($account['purpose_code'])?></h3><p>年度：<?=$e($account['year']??'共通')?> / 設定：<?=$account['enabled']?'有効':'無効'?></p><p>決済URL：<?=$account['has_payment_url']?'登録あり':'未登録'?> / CSV取込元：<?=$account['source_id']===null?'未対応':'#'.$e($account['source_id']).($account['source_enabled']?' 有効':' 無効')?></p><p>有効期間：<?=$e($account['valid_from']??'開始指定なし')?> ～ <?=$e($account['valid_until']??'終了指定なし')?>（UTC）</p></article><?php endforeach; ?>
<?php if ($data['accounts_truncated']): ?><p>口座設定は先頭200件を表示しています。</p><?php endif; ?>
<p>設定が揃っても自動では受付を開始しません。未確定事項の回答と、運営者による開始判断を待ちます。</p>
<?php elseif ($data && $section==='club'): ?>
<dl class="office-definition"><?php foreach ($data['lines'] as $line): ?><dt><?=$e($line['label'])?></dt><dd><?=number_format($line['amount_yen'])?>円</dd><?php endforeach; ?><dt>合計</dt><dd><?=number_format($data['quote']['total_yen'])?>円</dd></dl>
<p>過年度の登録：<?=count($data['prior_years'])?>件。当年度の登録：<?=$data['current']?'あり':'なし'?>。</p>
<?php if ($data['current'] && $data['current']['invoice_id']!==null): ?><p>発行済み請求 #<?=$e($data['current']['invoice_id'])?>：<?=number_format((int)$data['current']['amount_yen'])?>円。確認用計算との金額：<?=$data['existing_invoice_amount_matches']?'一致':'不一致（要確認）'?>。請求は変更しません。</p><?php endif; ?>
<p class="office-readonly">請求は発行できません。数字コードの対応と新規・再加入の履歴確認が必要です。ポータルの履歴が0件でも、新規とは判断しません。</p>
<?php elseif ($data && $section==='event'): ?>
<dl class="office-definition"><dt>開催日</dt><dd><?=$e($data['event_date'])?></dd><dt>申込締切日</dt><dd><?=$e($data['entry_end_date'])?></dd><dt>照会開始</dt><dd><?=$e($data['lookup_from'])?></dd><dt>照会終了境界（この時刻は対象外）</dt><dd><?=$e($data['lookup_until_exclusive'])?></dd><dt>現在の期間判定</dt><dd><?=$e(['open'=>'照会期間内','not_open'=>'開始前','expired'=>'終了後','unconfirmed'=>'対応確認待ち'][$data['window']])?></dd></dl>
<p><?=$e($data['reason'])?></p><p>主催者への資格表示は照会時点、ポイント対象資格は申込時点です。申込時の記録がない過去分を、現在の資格で埋め直しません。</p>
<?php elseif ($data && $section==='privacy'): ?>
<p><?=$e($data['retained'])?></p><p>以下は保存先の棚卸しです。削除対象者の確定や、削除実行ではありません。</p>
<?php foreach ($data['items'] as $item): ?><article class="office-history"><h3><?=$e($item['label'])?>：<?=$e($item['count'])?>件</h3><p><?=$e(['candidate'=>'ポータル個人情報の削除対象候補','review'=>'履歴・複製の扱いを確認する記録','keep'=>'保持する記録'][$item['scope']])?></p></article><?php endforeach; ?><p><?=$e($data['external_copies'])?></p>
<?php elseif ($data && $section==='membership'): ?>
<p>確認日時：<?=$e($data['checked_at'])?></p><p>照会時点の個人会員資格：<?=$data['member_active']===null?'確認できません（年度未切替等）':($data['member_active']?'有効':'有効な資格なし')?></p><p><?=$e($data['regular_club_reason'])?></p><p>過去のポイントは再計算せず、エントリーの受理も変更しません。</p>
<?php endif; ?></section><?php jmrc_office_footer(); ?>
