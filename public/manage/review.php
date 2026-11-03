<?php
// Path: public/manage/review.php
// 機能: 明示された事務局権限で、架空データだけの24機能・確認メモ・確認帳票を操作する。
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
$auth=jmrc_auth();$identity=$auth->requireIdentity();$auth->requirePermission('profiles.correct');
require_once __DIR__.'/_review-workspace.php';
require_once __DIR__.'/_office-layout.php';
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$catalog=jmrc_review_catalog();$screen=$_GET['screen']??'overview';
if(!is_string($screen) || ($screen!=='overview' && !isset($catalog[$screen]))) { throw new \Jmrc\Auth\HttpFailure(404,'確認画面が見つかりません'); }
$session=$_SESSION['jmrc_review']??null;
if(!is_array($session) || ($session['schema']??null)!==1 || !is_string($session['instance']??null) || ($session['owner']??null)!==$identity['id'] || time()-($session['created']??0)>=7200) {
    $session=JmrcReviewWorkspace::initial($identity['id']);
}
$review=new JmrcReviewWorkspace($session);
if(!$review->visible($screen)) { throw new \Jmrc\Auth\HttpFailure(403,'選択中の立場ではこの確認画面を表示できません'); }
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    $auth->verifyCsrf($_POST['_csrf']??null);
    try {
        $message=$review->apply($screen,$_POST);
        $_SESSION['jmrc_review']=$review->state;
        $_SESSION['jmrc_review_message']=$message;
        // PRGによりブラウザーの再読み込みを再送にしない。立場切替後は確認一覧へ戻る。
        $next=($_POST['action']??'')==='role' || !$review->visible($screen)?'overview':$screen;
        // 初心者向けの固定例から会員証へ進む。外部URLや任意の遷移先は受け付けない。
        if($screen==='operations' && ($_POST['action']??'')==='save' && ($_POST['return_to']??'')==='card') { $next='card'; }
        header('Location: /manage/review.php?screen='.$next,true,303);exit;
    } catch(\Jmrc\Auth\HttpFailure $failure) { http_response_code($failure->status);$error=$failure->getMessage(); }
} elseif($_SERVER['REQUEST_METHOD']!=='GET') { throw new \Jmrc\Auth\HttpFailure(405,'GETまたはPOSTを使用してください'); }
$_SESSION['jmrc_review']=$review->state;
if(isset($_SESSION['jmrc_review_message'])) { $message=$_SESSION['jmrc_review_message'];unset($_SESSION['jmrc_review_message']); }
$state=$review->state;$eligibility=$review->eligibility();$role=$state['role'];

// 書出しは事務局の認可を毎回確認し、宛先や本番識別子を一切含めない。
if(isset($_GET['download'])) {
    if($error!=='') { throw new \Jmrc\Auth\HttpFailure(422,'入力を確認してください'); }
    if(in_array($_GET['download'],['notes','notes-csv'],true) && in_array($role,['office','finance','approver'],true)) {
        $notes=[];foreach($catalog as $id=>$item) { $notes[]=['画面'=>$item['title'],'実装区分'=>$item['stage'],'保留事項'=>$item['pending'],'確認'=>$state['notes'][$id]??['decision'=>'pending','note'=>'']]; }
        if($_GET['download']==='notes-csv') {
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="jmrc-review-notes.csv"');
            $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['画面','実装区分','確認が残る事項','確認結果','メモ'],',','"','');
            $decisions=['pending'=>'確認待ち','ok'=>'画面の流れを確認','change'=>'修正・回答が必要'];
            foreach($notes as $row) {
                $cells=[$row['画面'],$row['実装区分'],$row['保留事項'],$decisions[$row['確認']['decision']],$row['確認']['note']];
                // 運営者の自由入力をExcelの式として実行させない。個人情報CSVとは別の確認票。
                $cells=array_map(static fn($v)=>preg_match('/^\s*[=+\-@]/u',$v)?"'".$v:$v,$cells);
                fputcsv($out,$cells,',','"','');
            }
            fclose($out);exit;
        }
        header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="jmrc-review-notes.json"');
        echo json_encode(['用途'=>'事務局操作確認・受付開始の承認ではありません','作成UTC'=>gmdate('c'),'画面別確認'=>$notes],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
    }
    if($_GET['download']==='insurance' && in_array($role,['office','finance','approver','club'],true)) {
        $count=$state['insurance']['count'];if(!$state['insurance']['intent'] || $count<1 || $count>24) { throw new \Jmrc\Auth\HttpFailure(422,'先に1〜24名の架空人数で加入意思を確認してください'); }
        $members=[];for($i=1;$i<=$count;$i++) { $members[]=['name'=>'架空 会員'.$i,'kana'=>'カクウ カイイン','birthday'=>'1990-04-01','gender'=>'','member_number'=>'']; }
        $bytes=(new \Jmrc\Insurance\InsuranceWorkbook())->build(jmrc_auth_config()['insurance_template']??'', ['name_full'=>'操作確認専用・提出不可','display_code'=>'100001','contact_name'=>'架空 担当'],$members);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="jmrc-demo-insurance-not-for-submission.xlsx"');echo $bytes;exit;
    }
    throw new \Jmrc\Auth\HttpFailure(403,'この書出しは利用できません');
}
$e=static fn(mixed $v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$csrf=$auth->csrfToken();$revision=(string)$state['revision'];
/** 全フォームの送信先を操作確認に固定し、他の本番サービスへPOSTしない。 */
$hidden=static function() use($e,$csrf,$revision,$state): void { echo '<input type="hidden" name="instance" value="'.$e($state['instance']).'">';echo '<input type="hidden" name="_csrf" value="'.$e($csrf).'"><input type="hidden" name="revision" value="'.$e($revision).'">'; };
$actionUrl='/manage/review.php?screen='.$screen;
header('Content-Type: text/html; charset=utf-8');
jmrc_office_header($screen==='overview'?'機能の操作確認':$catalog[$screen]['title'],'事務局・操作確認');
?>
<link rel="stylesheet" href="/manage/assets/review.css?v=20261030">
<?php if(in_array($role,['office','finance','approver'],true)): ?><p><a class="office-button secondary" href="/manage/information-preview.php">旧HPの情報ページを確認</a></p><?php endif; ?>
<aside class="review-banner" aria-label="操作確認の注意"><strong>操作確認専用・架空データ</strong><p>本番の受付・入金・資格・メール送信には反映しません。個人情報や実口座を入力しないでください。操作結果とメモはこのサインイン中だけ保持し、2時間経過後の再表示・サインアウト・初期化で利用できなくなります。</p></aside>
<?php if($message!==''): ?><p class="office-success" role="status"><?=$e($message)?></p><?php endif; ?>
<?php if($error!==''): ?><p class="review-error" role="alert"><?=$e($error)?></p><?php endif; ?>
<div class="office-actions"><a class="office-button secondary" href="/manage/review.php">確認画面の一覧</a><?php if(in_array($role,['office','finance','approver'],true)): ?><a class="office-button secondary" href="/manage/review.php?download=notes-csv">確認メモをExcel用CSVに保存</a><a class="office-button secondary" href="/manage/review.php?download=notes">確認メモをJSONに保存</a><?php endif; ?></div>
<details class="office-disclosure"><summary>確認する立場：<?=$e(JmrcReviewWorkspace::ROLES[$role])?> ／ 架空の確認日：<?=$e($state['date'])?></summary>
<div class="review-pad"><form class="office-form" method="post" action="<?=$e($actionUrl)?>" aria-label="確認する立場"><?php $hidden(); ?><input type="hidden" name="action" value="role"><div class="office-field"><label for="review-role">立場を切り替える</label><select id="review-role" name="role"><?php foreach(JmrcReviewWorkspace::ROLES as $key=>$label): ?><option value="<?=$e($key)?>" <?=$role===$key?'selected':''?>><?=$e($label)?></option><?php endforeach; ?></select></div><div class="office-submit"><button class="office-button">立場を変更</button></div></form>
<p class="office-help">これは表示と操作範囲の確認です。実際のアカウント権限は変わりません。事務局の方は全画面を確認できます。</p></div></details>
<details class="office-disclosure" id="review-help" <?=$screen==='overview'?'open':''?>><summary>使い方・困ったときはこちら</summary><div class="review-pad">
<p>ここは練習用の画面です。実際の会員登録、入金、メール送信は行われません。氏名や口座は画面に入っている架空の例を使ってください。</p>
<ol><li>まず「会員証の例を見る」を押します。すぐに表示を確認できます。</li><li>「会費請求・入金確認・訂正」で「最後の入金を取り消す」を押すと、会員証が無効になる例を試せます。</li><li>気づいたことは各画面の下にある「運営者の確認メモ」へ書き、「確認メモを保存」を押します。</li><li>終了前に上の「確認メモをExcel用CSVに保存」を押します。保存されたファイルをExcelで開き、運営担当者へ手動で渡してください。</li></ol>
<p><strong>「確認メモを保存」だけでは、ほかの担当者には届きません。</strong>メモはこのブラウザーで一時的に保持されます。2時間経過後の再表示、サインアウト、初期化で消えるため、終了前にファイルを保存してください。</p>
<p>ボタンが見つからないときは、上の「確認する立場」を開いて「事務局」に戻してください。入力エラーは赤い説明に沿って直します。「別の操作で更新」と出た場合は画面を開き直してください。</p>
<p>「業務処理あり」は仕組みの検証済み、「画面試作」は見た目・手順の確認、「運用確認」は方針の説明です。どの画面も、この場所から本番の受付は始まりません。</p>
<p><a href="https://docs.google.com/spreadsheets/d/1z0WuTupTRBSXgzMNBRjosTNgOi13HyVPYtn1knIsato/edit#gid=202611011" target="_blank" rel="noopener noreferrer">資料の食い違い・追加確認に回答する（別画面）</a></p>
</div></details>
<?php if($screen==='overview'): ?>
<?php if(in_array($role,['office','finance','approver'],true)): ?><section class="office-panel" aria-label="はじめての確認"><h2>まずは会員証の例を見る</h2><p>入力せずに、承認と入金が済んだ架空の例を作れます。確認中の申請・入金の例は入れ替わりますが、確認メモは残ります。</p><form class="office-form" method="post" action="/manage/review.php?screen=operations"><?php $hidden(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="status" value="active"><input type="hidden" name="date" value="2027-04-10"><input type="hidden" name="return_to" value="card"><div class="office-submit"><button class="office-button">会員証の例を見る</button></div></form></section><?php endif; ?>
<section class="office-panel"><h2>状態を変えて比較する場合</h2><ol><li>「年度設定・権限・移行・保存方針」で、有効・未入金・停止・期間終了の状態を選びます。</li><li>「General会員証」で表示を比べ、「会費請求」で取消後の資格を確認します。</li><li>手順を最初から試すときは初期化し、クラブ登録 → 入金・承認 → ライセンス → 個人申請 → 所属承認 → 入金の順に操作します。</li></ol><p>「業務処理あり」は既存処理を隔離環境で検証済みです。この確認画面での動作は説明用です。「画面試作」「運用確認」は入力・表示の確認段階で、本番業務への接続が残っています。</p></section>
<?php $group='';foreach($catalog as $id=>$item): if(!$review->visible($id)) { continue; }if($group!==$item['group']): if($group!==''): ?></ul></section><?php endif;$group=$item['group']; ?><section class="menu-section"><h2><?=$e($group)?></h2><ul class="menu-list"><?php endif; ?>
<li><a class="menu-link" href="/manage/review.php?screen=<?=$e($id)?>"><span class="menu-title"><?=$e($item['title'])?></span><span class="review-tag"><?=$e($item['stage'])?></span><span class="menu-description"><?=$e($item['flow'])?></span></a></li>
<?php endforeach;if($group!==''): ?></ul></section><?php endif; ?>
<?php else: $item=$catalog[$screen]; ?>
<p class="office-intro"><span class="review-tag"><?=$e($item['stage'])?></span> <?=$e($item['flow'])?></p><aside class="office-readonly"><strong>確認が残る事項</strong><p><?=$e($item['pending'])?></p></aside>
<?php if($screen==='clubs' && isset($state['club_quote'])): $quote=$state['club_quote']; ?>
<section class="office-panel" aria-label="2027年度の料金計算"><h2>2027年度の料金計算</h2><dl class="office-definition"><dt>年会費</dt><dd><?=$e(number_format($quote['annual_yen']))?>円</dd><dt>入会金</dt><dd><?=$e(number_format($quote['joining_yen']))?>円</dd><dt>合計</dt><dd><?=$e(number_format($quote['total_yen']))?>円</dd></dl><p>選んだ区分の計算結果です。クラブコードの確定や本番の請求には使いません。</p></section>
<?php endif; ?>
<?php if($screen==='operations' && isset($state['staff_role_example'])): ?>
<section class="office-panel" aria-label="役職承認の確認結果"><h2>役職承認の確認結果</h2><p>別の架空担当者を「<?=$e(\Jmrc\Auth\StaffRolePolicy::LABELS[$state['staff_role_example']['role']])?>」として承認できました。承認した立場は<?=$e($state['staff_role_example']['approved_by'])?>です。</p><p>実在アカウントの権限は変更していません。</p></section>
<?php endif; ?>
<?php if($screen==='handbook' && isset($state['series_example'])): $series=$state['series_example']; ?>
<section class="office-panel" aria-label="シリーズの計算例"><h2>シリーズの計算例</h2><dl class="office-definition"><dt>出走台数・中止によるクラス判定</dt><dd><?=$series['class_established']?'成立':'不成立'?></dd><dt>終了時のシリーズ成立</dt><dd><?=$series['series_established']?'成立':'未成立'?></dd><dt>入力した成立戦数に対する有効戦数</dt><dd><?=$e($series['effective_rounds'])?>戦</dd><dt>指定した分母での表彰人数の上限</dt><dd><?=$e($series['award_limit'])?>名</dd></dl><p>成立5戦以下は全戦、それ以上は70％を四捨五入し、高得点から合計します。同点比較に使うのは有効戦だけです。</p><p>表彰人数は計算例です。対象競技会の範囲・正式順位・同点者を確認してから選定します。「通知・承認依頼の文面」で案内文を試せます。</p></section>
<?php endif; ?>

<?php if(in_array($screen,['memberships','affiliations','preregistration','billing','card','licenses'],true)): ?>
<section class="office-panel" aria-label="General資格"><h2>Generalの資格 <span class="review-tag" data-eligibility="<?=$eligibility['active']?'active':'inactive'?>"><?=$eligibility['active']?'有効（確認例）':'無効（確認例）'?></span></h2>
<?php if($screen==='card' && $role==='public'): ?>
<?php if($eligibility['active']): ?><dl class="office-definition"><dt>氏名</dt><dd><?=$e($state['profile']['name'])?></dd><dt>会員番号</dt><dd><?=$e($state['license']['number'].'-G-'.$state['club']['code'])?></dd></dl><?php else: ?><p>有効な資格を確認できません。氏名・番号は表示しません。</p><?php endif; ?>
<?php else: ?><ul class="review-checks"><?php foreach($eligibility['checks'] as $label=>$passed): ?><li><span><?=$passed?'充足':'未充足'?></span><?=$e($label)?></li><?php endforeach; ?></ul>
<?php if($screen==='card' && $eligibility['active']): ?><div class="review-card"><p>確認用会員証・使用不可</p><h3>JMRC近畿 General 2027</h3><p><?=$e($state['profile']['name'])?></p><p><?=$e($state['license']['number'].'-G-'.$state['club']['code'])?></p><p><?=$e($state['club']['short'])?> ／ <?=$state['license']['kind']==='competition'?'競技 国内B':'審判員 B3'?></p><p>上の「確認する立場」を公開閲覧者に切り替え、同じ会員証画面で公開QRの照会項目を確認できます。本番のQRは発行しません。</p></div><?php endif; ?>
<?php endif; ?></section>
<?php endif; ?>

<?php if(in_array($screen,['clubs','billing','bank'],true)): ?>
<section class="office-panel"><h2>確認用の請求・入金</h2><?php if(!$state['invoices']): ?><p>請求はありません。申請から操作してください。</p><?php endif; ?><ul class="office-records"><?php foreach($state['invoices'] as $target=>$amount): ?><li><h3><?=$e($target==='club'?'クラブ年会費（説明用金額）':($target==='general'?'General会費':'Temporary '.substr($target,10)))?></h3><dl class="office-definition office-amounts"><dt>請求額</dt><dd><?=number_format($amount)?>円</dd><dt>確認済み入金</dt><dd><?=number_format($review->paid($target))?>円</dd><dt>未入金額</dt><dd data-balance="<?=$e($target)?>"><?=number_format($amount-$review->paid($target))?>円</dd></dl></li><?php endforeach; ?></ul>
<?php foreach($state['payments'] as $payment): ?><p><?=$e($payment['reference'])?> ／ <?=number_format($payment['amount'])?>円 ／ <?=$payment['reversed']?'取消済み':'確認済み'?></p><?php endforeach; ?></section>
<?php endif; ?>
<?php if($screen==='clubs'): ?><p class="office-readonly">年度コード <?=$e($state['club']['code'])?> ／ 申請 <?=$state['club']['submitted']?'済':'未'?> ／ 事務局承認 <?=$state['club']['approved']?'済':'未'?></p><?php endif; ?>
<?php if($screen==='affiliations'): ?><section class="office-panel"><h2>担当クラブの会員一覧（架空）</h2><?php if($state['profile'] && $state['member']['submitted']): ?><dl class="office-definition"><?php foreach($state['profile'] as $key=>$value): ?><dt><?=$e($catalog['memberships']['fields'][$key][0]??$key)?></dt><dd><?=$e($value)?></dd><?php endforeach; ?><dt>JAFライセンス番号（架空）</dt><dd><?=$e($state['license']['number']??'未登録')?></dd></dl><?php else: ?><p>この確認内に申請済みの会員はいません。</p><?php endif; ?><p>CSV出力の項目・財務委員を含む権限の最終範囲は確認待ちです。この確認画面から個人情報CSVは出力しません。</p></section><?php endif; ?>
<?php if($screen==='preregistration'): ?><p class="office-readonly">照合結果：<?=$e($state['preregistration'])?></p><?php endif; ?>
<?php if($screen==='temporary'): ?><section class="office-panel"><h2>確認用のイベント申請</h2><ul class="office-records"><?php foreach($state['temporary'] as $id=>$entry): $active=$entry['approved'] && $review->paid('temporary:'.$id)>=500 && $state['date']===$entry['date']; ?><li>確認走行会<?=$e($id)?> ／ <?=$e($entry['date'])?> ／ 主催承認 <?=$entry['approved']?'済':'未'?> ／ 入金 <?=number_format($review->paid('temporary:'.$id))?>円 ／ 当日資格 <?=$active?'有効':'無効'?></li><?php endforeach; ?></ul><p>主催承認を試すときは、確認する立場を「クラブ代表」に切り替えてください。</p></section><?php endif; ?>
<?php if($screen==='bank'): ?><section class="office-panel"><h2>取込結果</h2><p>同じ架空CSVを再取込しても2行のままです。複数候補は確定しません。</p><ul class="office-records"><?php foreach($state['bank'] as $id=>$row): ?><li><?=$e($id)?> ／ <?=number_format($row['amount'])?>円 ／ <?=$e($row['status'])?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if($screen==='insurance'): ?><section class="office-panel"><h2>加入意思と初回人数</h2><p><?=$state['insurance']['intent']?'加入意思を記録した例':'加入意思は未記録'?> ／ 有効General <?=$state['insurance']['count']?>名（架空）</p><p><?=$state['insurance']['count']>=4?'初回4名の人数条件を満たす例です。正式加入ではありません。':'初回4名に達していません。追加加入の取扱いは未確定です。'?></p><?php if(in_array($role,['office','finance','approver','club'],true)): ?><a class="office-button secondary" href="/manage/review.php?screen=insurance&amp;download=insurance">架空データの確認Excel</a><?php endif; ?><p class="office-help">Excelも「確認用・提出不可」です。料率・期間・保険料は補いません。</p></section><?php endif; ?>
<?php if($screen==='directory'): ?><section class="office-panel"><h2>公開クラブ一覧の例</h2><h3><?=$e($state['club']['name'])?></h3><p><?=$e($state['club']['short'])?> ／ <?=$e($state['club']['code'])?></p><p>架空の表示例です。正式な登録済みクラブの公開ではありません。</p></section><?php endif; ?>
<?php if($screen==='fund'): ?><section class="office-panel"><h2>各制度は独立して確認</h2><dl class="office-definition"><dt>協議会見舞金</dt><dd>個人会員資格と規程に基づいて確認。給付条件は確認待ち。</dd><dt>ジムカーナ部会救済基金</dt><dd>既存entryとの管理分担を確認中。ポータル会費の入金を基金入金として扱いません。</dd><dt>スポーツ安全保険</dt><dd>General限定。加入意思・人数・確認Excelまで。正式加入は保留。</dd></dl></section><?php endif; ?>
<?php if($screen==='operations'): ?><section class="office-panel"><h2>2027年度の設定・移行確認</h2><dl class="office-definition"><dt>クラブ受付</dt><dd>2026年12月1日〜2027年2月7日（本番では開始保留）</dd><dt>本人受付</dt><dd>2027年1月1日から（本番では開始保留）</dd><dt>年度資格</dt><dd>1月1日〜12月31日。既存entryの参照先切替は未実施。</dd><dt>会費マスター</dt><dd>General 2,000円／年、Temporary 500円／イベント。クラブ会費表の候補は受領済み。適用年度・区分・入会金を確認中。</dd><dt>権限の付与</dt><dd>既存ログインを継続し、承認されたポータル権限・担当クラブを個別付与。運営委員会担当者の登録は事務局長が承認。対象アカウントは確認待ち。</dd><dt>保存と削除</dt><dd>操作ログ2年。未更新者は翌年4月30日の削除候補。ドラフト3か月の起算点は確認待ち。バックアップは日次30世代・月次12世代・四半期復元試験の設計値で、自動運転と復元後の再削除は未接続。</dd></dl><p class="office-help">状態例を選ぶと、この確認内の申請・入金を入れ替えます。クラブ年会費1万円は説明用の仮額です。メモは残ります。</p></section><?php endif; ?>
<?php if($screen==='privacy'): ?><section class="office-panel"><h2>確認用プロフィール</h2><p><?=$state['profile']===null?'削除例を表示中':$e(($state['profile']['name']??'').' ／ '.($state['profile']['birthday']??''))?></p><p>候補例：最終登録2026年、翌年度未更新 → 2027年4月30日。更新申請が処理中の会員は候補から除外します。</p></section><?php endif; ?>

<?php if($screen==='insurance' && $review->allowed($screen,'claim_notice')): ?>
<section class="office-panel" aria-label="保険請求の連絡例"><h2>保険請求の連絡例</h2><p>旧制度・紙で申し込んだ方も対象です。クラブ代表が本人の代わりに連絡できます。事務局がクラブ員名簿を確認して本人へ連絡し、同姓同名の場合は電話で確認します。</p>
<form class="office-form" method="post" action="<?=$e($actionUrl)?>" aria-label="保険請求の2項目"><?php $hidden(); ?><input type="hidden" name="action" value="claim_notice"><?php foreach($item['claim_fields'] as $name=>[$label,$type,$default]): ?><div class="office-field"><label for="claim-<?=$e($name)?>"><?=$e($label)?></label><input id="claim-<?=$e($name)?>" name="<?=$e($name)?>" maxlength="1000" value="<?=$e($state['insurance_claim'][$name]??$default)?>" required></div><?php endforeach; ?><div class="office-submit"><button class="office-button">請求連絡の2項目を確認する</button></div></form>
<?php if(isset($state['insurance_claim'])): ?><p role="status">確認用に2項目を保存しました。事務局・保険会社への送信は行っていません。</p><?php endif; ?></section>
<?php endif; ?>
<?php if($screen==='clubs' && $review->allowed($screen,'classify_region')): $regional=$state['club_region_example']??null; ?>
<section class="office-panel" aria-label="他地域クラブの会員区分"><h2>他地域クラブの会員区分（2027年度の確認用）</h2><p>近畿地区外のクラブが対象です。JAF登録クラブで、JMRC近畿の事業として競技会を主催する場合は正会員です。一般の競技会の主催だけでは、この例外には該当しません。</p>
<form class="office-form" method="post" action="<?=$e($actionUrl)?>" aria-label="他地域クラブの条件"><?php $hidden(); ?><input type="hidden" name="action" value="classify_region">
<?php foreach($item['region_fields'] as $name=>[$label,$options,$default]): ?><div class="office-field"><label for="region-<?=$e($name)?>"><?=$e($label)?></label><select id="region-<?=$e($name)?>" name="<?=$e($name)?>"><?php foreach($options as $value=>$caption): ?><option value="<?=$e($value)?>" <?=($regional[$name]??$default)===$value?'selected':''?>><?=$e($caption)?></option><?php endforeach; ?></select></div><?php endforeach; ?><div class="office-submit"><button class="office-button">他地域クラブの区分を確認</button></div></form>
<?php if($regional!==null): ?><p role="status" data-regional-membership="<?=$e($regional['membership']??'pending')?>"><?php if($regional['membership']==='regular'): ?>正会員に該当します。<?php elseif($regional['membership']==='supporting'): ?>例外の条件に該当しないため、賛助会員です。<?php else: ?>区分は保留です。JAF登録とJMRC近畿の事業としての主催について確認してください。<?php endif; ?></p><?php endif; ?>
<p>入力はこの確認画面だけで使用します。料金を試す場合は、判定結果に合わせて「料金計算の会員区分」を選んでください。本番のクラブ区分・請求・資格は変更しません。近畿地区内のクラブや関連団体・企業は、この判定の対象外です。</p></section>
<?php endif; ?>
<?php $actions=array_filter($item['actions'],static fn($label,$action)=>!in_array($action,['claim_notice','classify_region'],true) && $review->allowed($screen,$action),ARRAY_FILTER_USE_BOTH);if($actions): ?>
<?php // iPhone相当のWebKitでは日付を消した後のネイティブ検証で送信が止まるため、Generalはサーバーで必須・日付・免許の組を検証する。 ?>
<section class="office-panel"><h2>入力・操作を試す</h2><form class="office-form" aria-label="<?=$e($item['title'])?>の操作" method="post" action="<?=$e($actionUrl)?>" <?=$screen==='memberships'?'novalidate':''?>><?php $hidden(); ?>
<?php foreach($item['fields'] as $name=>[$label,$type,$default]): $value=$default;
if($screen==='licenses' && $state['license']!==null) { $value=$state['license'][$name]??$default; }
if($screen==='clubs' && $state['club']['submitted']) { $value=match($name) {'name','short','fee'=>$state['club'][$name],'base'=>substr($state['club']['code'],1),'category'=>substr($state['club']['code'],0,1),default=>$state['club'][$name]??$default}; }
if($screen==='operations' && $name==='date') { $value=$state['date']; }
if($error!=='' && is_string($_POST[$name]??null) && strlen($_POST[$name])<=4000) { $value=$_POST[$name]; }
?>
<div class="office-field <?=$type==='textarea'?'office-wide':''?>"><label for="field-<?=$e($name)?>"><?=$e($label)?></label>
<?php if(is_array($type)): ?><select name="<?=$e($name)?>" id="field-<?=$e($name)?>"><?php foreach($type as $key=>$option): ?><option value="<?=$e($key)?>" <?=(string)$value===(string)$key?'selected':''?>><?=$e($option)?></option><?php endforeach; ?></select>
<?php elseif($type==='textarea'): ?><textarea name="<?=$e($name)?>" id="field-<?=$e($name)?>" maxlength="1000" rows="4" required><?=$e($value)?></textarea>
<?php else: ?><input type="<?=$e($type)?>" name="<?=$e($name)?>" id="field-<?=$e($name)?>" value="<?=$e($value)?>" <?=$type==='number'?'min="0" max="10000000" step="1"':'maxlength="1000"'?> <?=($name==='fee' || ($screen==='memberships' && in_array($name,['driver','driver_expiry'],true)))?'':'required'?>><?php if($screen==='memberships' && $name==='driver'): ?><small class="office-help">運転免許がない場合、免許番号と免許有効期限は両方空欄で試せます。JAFライセンスは必要です。</small><?php endif; ?><?php endif; ?></div>
<?php endforeach; ?><div class="office-submit"><?php foreach($actions as $action=>$label): ?><button class="office-button" name="action" value="<?=$e($action)?>"><?=$e($label)?></button><?php endforeach; ?></div></form></section>
<?php endif; ?>
<?php $entries=$review->entries($screen);if(in_array($screen,['events','notices','documents','handbook','links','meetings','inquiries','notifications','accounts'],true)): ?>
<section class="office-panel"><h2>確認用の保存内容</h2><?php if(!$entries): ?><p>この立場で表示する内容はありません。</p><?php endif; ?><ul class="office-records"><?php foreach($entries as $entry): ?><li><dl class="office-definition"><?php foreach($item['fields'] as $name=>[$label,$type,$default]): ?><dt><?=$e($label)?></dt><dd><?=$e(is_array($type)?($type[$entry[$name]]??''):$entry[$name])?></dd><?php endforeach; ?></dl><?php if(isset($entry['action']) && $entry['action']==='reply'): ?><p>事務局回答案・未送信</p><?php endif; ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<section class="office-panel"><h2>運営者の確認メモ</h2><form class="office-form" aria-label="確認メモ" method="post" action="<?=$e($actionUrl)?>"><?php $hidden(); ?><input type="hidden" name="action" value="note"><div class="office-field"><label for="decision">確認結果</label><select id="decision" name="decision"><?php foreach(['pending'=>'確認待ち','ok'=>'画面の流れを確認','change'=>'修正・回答が必要'] as $id=>$label): ?><option value="<?=$id?>" <?=($state['notes'][$screen]['decision']??'pending')===$id?'selected':''?>><?=$label?></option><?php endforeach; ?></select></div><div class="office-field office-wide"><label for="note">メモ（個人情報を含めない）</label><textarea id="note" name="note" rows="3" maxlength="1000"><?=$e($state['notes'][$screen]['note']??'')?></textarea></div><div class="office-submit"><button class="office-button">確認メモを保存</button></div></form><p class="office-help">操作の確認は、受付開始・規程・公開への承認とは別です。事務局の立場に戻ると確認メモを書き出せます。</p></section>
<?php endif; ?>
<details class="office-disclosure"><summary>確認用の操作履歴と初期化</summary><div class="review-pad"><ul class="office-records"><?php foreach(array_reverse($state['history']) as $row): ?><li><?=$e($catalog[$row['screen']]['title']??'確認一覧')?> ／ <?=$e(JmrcReviewWorkspace::ROLES[$row['role']])?> ／ <?=$e($row['result'])?></li><?php endforeach; ?></ul><form method="post" class="office-form" action="<?=$e($actionUrl)?>" aria-label="確認データ初期化"><?php $hidden(); ?><input type="hidden" name="action" value="reset"><p class="office-wide">初期化すると確認メモも消えます。共有するメモは先に書き出してください。</p><div class="office-submit"><button class="office-button secondary">確認データを初期化する</button></div></form></div></details>
<?php jmrc_office_footer(); ?>
