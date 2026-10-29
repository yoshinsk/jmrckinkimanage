<?php
// Path: public/manage/insurance.php
// 機能: 本人の保険加入意思受付と、対象クラブ代表/事務局による確認用Excelの選択出力。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';require_once __DIR__.'/_card-public.php';
$user=jmrc_auth()->requireIdentity();$service=new \Jmrc\Insurance\InsuranceService(jmrc_db(),jmrc_auth_config());
$json=($_GET['format']??'')==='json';$result=null;
$positive=static function($value):int{$v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($v===false){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}return $v;};
$club=isset($_GET['club_year_id'])?$positive($_GET['club_year_id']):null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
    // 未確定の金額・資格・補償期間を受け付ける入口を設けない。
    foreach(array_keys($_POST) as $key){if(!in_array($key,['_csrf','action','membership_id','consent','club_year_id','request_ids'],true)){throw new \Jmrc\Auth\HttpFailure(422,'未対応の入力項目です');}}
    if(($_POST['action']??'')==='request'){$result=$service->request($user['id'],$positive($_POST['membership_id']??null),($_POST['consent']??'')==='1');}
    elseif(($_POST['action']??'')==='export'){
        $ids=$_POST['request_ids']??null;if(!is_array($ids)){throw new \Jmrc\Auth\HttpFailure(422,'申請を選択してください');}
        $export=$service->export($user['id'],$positive($_POST['club_year_id']??null),array_map($positive,$ids));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="sports-insurance-preview-'.$export['id'].'.xlsx"');
        header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($export['bytes']));echo $export['bytes'];exit;
    }else{throw new \Jmrc\Auth\HttpFailure(422,'操作を指定してください');}
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){throw new \Jmrc\Auth\HttpFailure(405,'POST required');}
$rows=$club?$service->listing($user['id'],$club):[];$mine=$service->mine($user['id']);
if($json){header('Content-Type: application/json; charset=utf-8');echo json_encode($result??($club?['requests'=>$rows,'readiness'=>$service->readiness($user['id'],$club)]:['requests'=>$mine]),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$s=jmrc_db()->prepare("SELECT id,year,member_type FROM portal_memberships WHERE user_id=? AND member_type='general' ORDER BY year DESC,id");$s->execute([$user['id']]);$members=$s->fetchAll();$clubs=$service->clubs($user['id']);
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>スポーツ安全保険の申請</title><link rel="stylesheet" href="/manage/assets/cards.css"><link rel="stylesheet" href="/manage/assets/licenses.css"><link rel="stylesheet" href="/manage/assets/insurance.css"></head><body><main><h1>スポーツ安全保険の申請</h1>
<p>現在は加入意思の受付と確認用帳票の出力のみです。Generalのみが対象で、Temporary・ノンライセンスは加入できません。初回は年度・クラブ単位で有効なGeneralが4名以上必要です。2027年の保険料・保険期間は確認待ちです。意思の記録・確認用帳票出力では加入は成立しません。</p>
<?php if($result): ?><p role="status">加入意思を記録しました。加入可否の確認をお待ちください。</p><?php endif; ?>
<h2>本人の加入意思</h2><form class="license-form" method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="request"><label>個人会員登録<select name="membership_id" aria-label="個人会員登録" required><?php foreach($members as $m): ?><option value="<?=$e($m['id'])?>"><?=$e($m['year'].'年度 '.$m['member_type'].' #'.$m['id'])?></option><?php endforeach; ?></select></label><label><input type="checkbox" name="consent" value="1" required>スポーツ安全保険への加入を希望します。加入条件は確認待ちであることを確認しました。</label><button type="submit">加入意思を記録する</button></form>
<h2>自分の申請記録</h2><ul><?php foreach($mine as $m): ?><li><?=$e($m['year'])?>年度 ／ 申請 #<?=$e($m['id'])?> ／ 加入可否・保険料確認待ち</li><?php endforeach; ?></ul>
<?php if($clubs): ?><h2>クラブ代表・事務局の確認用出力</h2><form class="license-form" method="get"><label>年度クラブ<select name="club_year_id"><?php foreach($clubs as $c): ?><option value="<?=$e($c['id'])?>" <?=(int)$c['id']===$club?'selected':''?>><?=$e($c['year'].'年度 '.$c['name_full'])?></option><?php endforeach; ?></select></label><button>申請を確認する</button></form><?php endif; ?>
<?php if($club): $ready=$service->readiness($user['id'],$club); ?><p>初回人数の確認: 有効General <?=$e($ready['eligible_count'])?>名 / 必要4名。<?=$ready['initial_minimum_met']?'人数条件を満たします':'人数が不足しています'?>。保険料・期間が未確定のため正式申請はできません。</p><p>1回24名まで選択できます。人数は様式の行数による制限です。初回4名の条件は有効なGeneralの加入意思をクラブ・年度単位で確認します。保険区分・年齢・掛金は未確定です。未登録のプロフィール項目・未発行の会員番号は空欄です。確認用帳票を提出しないでください。</p><form method="post"><input type="hidden" name="_csrf" value="<?=jmrc_auth()->csrfToken()?>"><input type="hidden" name="action" value="export"><input type="hidden" name="club_year_id" value="<?=$e($club)?>"><?php foreach($rows as $r): ?><p><label><input type="checkbox" name="request_ids[]" value="<?=$e($r['id'])?>"><?=$e('#'.$r['id'].' '.$r['name'].' ／ '.$r['member_type'])?></label></p><?php endforeach; ?><button type="submit">指定Excel形式で確認用出力</button></form><?php endif; ?>
<p><a href="/manage/">マイページへ</a></p></main></body></html>
