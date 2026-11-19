<?php
// Path: public/manage/license-export.php
// 機能: 事務局向け照会出力の確認画面。未確定の書式・対象範囲が決まるまでダウンロードは停止する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';require_once __DIR__.'/_office-layout.php';
$user=jmrc_auth()->requireIdentity();$config=jmrc_auth_config();$service=new \Jmrc\License\LicenseExport(jmrc_db(),$config);$service->access($user['id']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    if(($_POST['confirmed']??'')!=='1'){throw new \Jmrc\Auth\HttpFailure(422,'個人情報の取扱いを確認してください');}
    $csv=$service->csv($user['id'],$_POST['year']??null);
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jmrc-license-reference-'.(int)$_POST['year'].'.csv"');echo $csv;exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$years=jmrc_db()->query('SELECT year FROM portal_years ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$enabled=($config['license_export_enabled']??false)===true;
jmrc_office_header('JAF照会用出力の確認');
?>
<p>本人申告のライセンスを事務局で確認するためのCSVです。JAFへ自動送信せず、ライセンスの有効・無効を判定しません。</p>
<p><a class="office-button secondary" href="/manage/licenses.php">本人用のライセンス入力項目を確認する</a></p>
<p>JAFの指定書式はなく、選択年度に有効状態で登録済みのGeneralだけを対象にします。氏名は本人が申告したJAFライセンス登録名を使い、未登録者がいれば全体の出力を止めます。申請中・停止中は含めません。発行日の入力条件は引き続き確認中です。現在の資格照会の代わりには使いません。</p>
<?php if(!$enabled):?><p role="status" class="office-notice">出力は確認中です。まだダウンロードできません。受付と正本の切替も行いません。</p><?php endif;?>
<section class="office-panel"><h2 id="export-heading">照会用CSVの準備</h2>
<p>出力予定の項目：</p><ul><?php foreach(\Jmrc\License\LicenseExport::COLUMNS as $column):?><li><?=$e($column)?></li><?php endforeach;?></ul>
<form class="office-form" method="post" action="/manage/license-export.php" aria-labelledby="export-heading">
<input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>">
<label for="export-year">対象年度（必須）</label><select id="export-year" name="year" required><?php foreach($years as $year):?><option value="<?=$e($year)?>"><?=$e($year)?>年度</option><?php endforeach;?></select>
<label><input type="checkbox" name="confirmed" value="1" required>個人情報を含むファイルを、事務局の照会用途で適切に保管します</label>
<button class="office-button" type="submit" <?=$enabled?'':'disabled'?>>照会用CSVをダウンロード</button></form></section>
<?php jmrc_office_footer();
