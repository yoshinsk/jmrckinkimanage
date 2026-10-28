<?php
// Path: public/manage/clubs.php
// 機能: 対象クラブの年度登録・前年情報継続・連絡先訂正・年会費請求・事務局承認を提供する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
$user=jmrc_auth()->requireIdentity();$db=jmrc_db();$service=new \Jmrc\Club\ClubService($db,jmrc_auth_config()['data_key']??null);
$json=($_GET['format']??'')==='json';$result=null;
$number=static function(array $input,string $name): int {
    $v=filter_var($input[$name]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$v) { throw new \Jmrc\Auth\HttpFailure(422,'対象・年度を確認してください'); }return $v;
};
if ($_SERVER['REQUEST_METHOD']==='POST') {
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    $result=match($_POST['action']??'') {
        'submit'=>$service->submit($user['id'],$number($_POST,'club_id'),$number($_POST,'year'),is_string($_POST['category_digit']??null)?$_POST['category_digit']:'',$_POST,is_string($_POST['request_key']??null)?$_POST['request_key']:''),
        'update'=>$service->update($user['id'],$number($_POST,'id'),$_POST,$number($_POST,'row_version')),
        'issue'=>(new \Jmrc\Billing\BillingService($db))->issueClub($user['id'],$number($_POST,'id')),
        'approve'=>$service->approve($user['id'],$number($_POST,'id')),
        default=>throw new \Jmrc\Auth\HttpFailure(422,'操作を選択してください'),
    };
    if (($_POST['action']??'')==='submit' && !$result['replayed']) { http_response_code(201); }
} elseif ($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'POST required'); }
if (isset($_GET['id'])) { $result=$service->read($user['id'],$number($_GET,'id')); }
$club=isset($_GET['club_id'])?$number($_GET,'club_id'):null;$year=isset($_GET['year'])?$number($_GET,'year'):(int)gmdate('Y')+1;$previous=null;$rows=[];
if ($club!==null) {
    $service->requireAccess($user['id'],$club);
    $s=$db->prepare('SELECT id,year,display_code,status FROM portal_club_years WHERE club_id=? ORDER BY year DESC');$s->execute([$club]);$rows=$s->fetchAll();
    foreach($rows as $r) { if ((int)$r['year']<$year) { $previous=$service->read($user['id'],(int)$r['id']);break; } }
}
$clubs=[];
foreach($db->query('SELECT id,name_full,name_short FROM portal_clubs ORDER BY fixed_code')->fetchAll() as $c) {
    try { $service->requireAccess($user['id'],(int)$c['id']);$clubs[]=$c; }catch(\Jmrc\Auth\HttpFailure $e) { if($e->status!==403) { throw $e; } }
}
if ($json) { header('Content-Type: application/json; charset=utf-8');echo json_encode($result??['clubs'=>$clubs,'registrations'=>$rows,'previous'=>$previous],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$csrf=jmrc_auth()->csrfToken();
$editing=isset($_GET['id']) && $result && isset($result['club_id']);$values=$editing?$result:($previous??[]);
if (!$values && $club!==null) { foreach($clubs as $c) { if((int)$c['id']===$club) { $values=$c;break; } } }
$labels=['name_full'=>'正式名','name_short'=>'略称','rep_name'=>'代表者名','contact_name'=>'連絡先担当者名','postcode'=>'郵便番号','address'=>'住所','phone'=>'電話番号','email'=>'メールアドレス'];
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><meta charset="utf-8"><title>正会員クラブの年度登録</title><h1>正会員クラブの年度登録</h1>
<p>年会費の満額入金確認後に事務局が承認します。年度開始前の登録は先行登録として扱います。</p>
<form method="get"><label>クラブ<select name="club_id"><?php foreach($clubs as $c): ?><option value="<?=$e($c['id'])?>" <?=($club===(int)$c['id'])?'selected':''?>><?=$e($c['name_full'])?></option><?php endforeach; ?></select></label><label>登録年度<input type="number" name="year" value="<?=$e($year)?>" required></label><button>年度情報を開く</button></form>
<?php if($result && isset($result['invoice_no'])): ?><p>年会費請求を発行しました。<a href="/manage/billing.php?invoice=<?=$e($result['id'])?>"><?=$e($result['invoice_no'])?></a></p><?php elseif($result): ?><p role="status">年度 <?=$e($result['year'])?> ／ コード <?=$e($result['display_code'])?> ／ 入金 <?=$result['eligibility']['paid']?'確認済み':'未完了'?> ／ 承認 <?=$result['eligibility']['approved']?'承認済み':'未完了'?> ／ 現在 <?=$result['eligibility']['active']?'有効':'無効'?></p><?php endif; ?>
<?php if($club!==null || $editing): ?><h2><?=$editing?'登録情報の訂正':'年度登録'?></h2><?php if($previous && !$editing): ?><p>前年度以前の直近情報を入力欄へ引き継いでいます。内容を確認して登録してください。</p><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="<?=$editing?'update':'submit'?>">
<?php if($editing): ?><input type="hidden" name="id" value="<?=$e($result['id'])?>"><input type="hidden" name="row_version" value="<?=$e($result['row_version'])?>"><?php else: ?><input type="hidden" name="club_id" value="<?=$e($club)?>"><input type="hidden" name="year" value="<?=$e($year)?>"><input type="hidden" name="request_key" value="<?=bin2hex(random_bytes(24))?>"><label>区分<select name="category_digit"><?php foreach($db->query('SELECT category_digit,name FROM portal_club_categories WHERE enabled=1 ORDER BY category_digit')->fetchAll() as $cat): ?><option value="<?=$e($cat['category_digit'])?>" <?=($values['category_digit']??'')===$cat['category_digit']?'selected':''?>><?=$e($cat['name'])?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php foreach($labels as $field=>$label): ?><p><label><?=$label?><input name="<?=$field?>" value="<?=$e($values[$field]??'')?>" required></label></p><?php endforeach; ?>
<p><label>クラブの振込先口座（変更時のみ入力。既存の値は表示しません）<textarea name="bank_details"></textarea></label></p>
<?php if(!$editing && !empty($previous['has_bank_details'])): ?><label><input type="checkbox" name="carry_bank" value="1" checked>直近の年度登録の口座情報を継続する</label><?php endif; ?><button>登録情報を保存</button></form><?php endif; ?>
<h2>年度登録一覧</h2><?php foreach($rows as $r): ?><p><a href="?id=<?=$e($r['id'])?>"><?=$e($r['year'].' '.$r['display_code'])?></a></p><?php endforeach; ?>
<?php if($result && isset($result['club_id'])): ?><form method="post"><input type="hidden" name="_csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$e($result['id'])?>"><button name="action" value="issue">年会費請求を表示・発行</button><?php if(jmrc_auth()->can('club.registration.approve')): ?><button name="action" value="approve">入金確認済みの年度登録を承認</button><?php endif; ?></form><?php endif; ?>
<p><a href="/manage/billing.php">請求・入金確認へ</a> ／ <a href="/manage/">マイページへ</a></p></html>
