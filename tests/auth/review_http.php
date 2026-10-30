<?php
// Path: tests/auth/review_http.php
// 機能: 操作確認の認可・再送・非更新性と、架空状態の承認/入金/公開範囲を内部HTTPSで検証する。
declare(strict_types=1);
if(PHP_SAPI!=='cli') { exit(1); }
require_once '/srv/auth/src/Auth/HttpFailure.php';
require_once '/srv/web/manage/_review-workspace.php';
$reviewSnapshot=static function() use($db): array {
    return $db->query('CHECKSUM TABLE portal_profiles,portal_identifiers,portal_club_years,portal_memberships,portal_applications,portal_affiliations,portal_invoices,portal_invoice_lines,portal_payments,portal_allocations,portal_years,portal_bank_imports,portal_insurance_requests')->fetchAll(PDO::FETCH_ASSOC);
};
$reviewBefore=$reviewSnapshot();
check('操作確認の非更新比較13表が実在',count($reviewBefore)===13 && !in_array(null,array_column($reviewBefore,'Checksum'),true));
$rOffice=new Browser();$rOffice->login(7);$rMember=new Browser();$rMember->login(3);$rClub=new Browser();$rClub->login(2);$rAdmin=new Browser();$rAdmin->login(1);
check('操作確認は匿名401',(new Browser())->request('/manage/review.php')['status']===401);
foreach(['会員'=>$rMember,'クラブ代表'=>$rClub,'旧entry管理者'=>$rAdmin] as $role=>$browser) {
    check('操作確認は実権限なし403 '.$role,$browser->request('/manage/review.php')['status']===403);
    check('操作確認の書出しも実権限なし403 '.$role,$browser->request('/manage/review.php?download=notes')['status']===403);
    check('実権限なしのマイページに確認入口なし '.$role,!str_contains($browser->request('/manage/')['body'],'href="/manage/review.php"'));
}
check('事務局の確認入口',str_contains($rOffice->request('/manage/')['body'],'href="/manage/review.php"'));
// 本番OFFICEには情報公開の権限が未付与。既存の事務局訂正権限だけで説明画面に入れることを再現する。
try {
    $db->exec("DELETE FROM portal_role_permissions WHERE role_id=1 AND permission_code='portal.content.write'");
    check('本番同等の情報公開権限なし事務局で確認画面200',$rOffice->request('/manage/review.php')['status']===200);
    check('本番同等の情報公開権限なし事務局に確認入口',str_contains($rOffice->request('/manage/')['body'],'href="/manage/review.php"'));
} finally { $db->exec("INSERT INTO portal_role_permissions(role_id,permission_code) VALUES(1,'portal.content.write')"); }
foreach(jmrc_review_catalog() as $screen=>$item) {
    $page=$rOffice->request('/manage/review.php?screen='.$screen);
    check('確認画面GET '.$screen,$page['status']===200 && str_contains($page['body'],$item['title']) && str_contains($page['body'],'操作確認専用・架空データ'));
    check('確認画面は非キャッシュ・外部送信禁止 '.$screen,(bool)array_filter($rOffice->lastHeaders,static fn($h)=>str_contains($h,"script-src 'none'")) && (bool)array_filter($rOffice->lastHeaders,static fn($h)=>str_contains($h,'no-store')));
}
foreach(['_review-workspace.php','_review-catalog.php'] as $file) { check('確認補助PHPの直接実行拒否 '.$file,$rOffice->request('/manage/'.$file)['status']===404); }
check('未知の確認画面は404',$rOffice->request('/manage/review.php?screen=../../entry')['status']===404);
check('操作確認のCSRF欠落拒否',$rOffice->request('/manage/review.php',['action'=>'reset'])['status']===400);
check('操作確認のPUT拒否',$rOffice->request('/manage/review.php',null,'PUT')['status']===405);
/** 表示された版とCSRFだけを使い、GET/POSTの実際の結果を観測する。 */
$rPost=static function(Browser $b,string $screen,string $action,array $values=[]): array {
    $page=$b->request('/manage/review.php?screen='.$screen);
    preg_match('/name="_csrf" value="([a-f0-9]+)"/',$page['body'],$csrf);preg_match('/name="revision" value="([0-9]+)"/',$page['body'],$rev);
    preg_match('/name="instance" value="([a-f0-9]+)"/',$page['body'],$instance);
    return $b->request('/manage/review.php?screen='.$screen,['instance'=>$instance[1]??'','action'=>$action,'_csrf'=>$csrf[1]??'','revision'=>$rev[1]??'']+$values);
};
check('架空の有効状態を設定',$rPost($rOffice,'operations','save',['status'=>'active','date'=>'2027-04-10'])['status']===303);
check('確認用会員証は有効',str_contains($rOffice->request('/manage/review.php?screen=card')['body'],'data-eligibility="active"'));
$otherOffice=new Browser();$otherOffice->login(7);
check('同じ事務局でも別セッションへ状態が漏れない',str_contains($otherOffice->request('/manage/review.php?screen=card')['body'],'data-eligibility="inactive"'));
check('確認メモを保存',$rPost($rOffice,'card','note',['decision'=>'change','note'=>'<script>alert(1)</script>'])['status']===303);
$html=$rOffice->request('/manage/review.php?screen=card')['body'];
check('確認メモのHTMLをエスケープ',!str_contains($html,'<script>alert(1)</script>') && str_contains($html,'&lt;script&gt;'));
check('確認票を書出し',str_contains($rOffice->request('/manage/review.php?download=notes')['body'],'画面別確認'));
$rPost($rOffice,'card','note',['decision'=>'change','note'=>'=1+1']);
$csv=$rOffice->request('/manage/review.php?download=notes-csv');
check('Excel用確認票にBOM・式注入対策',$csv['status']===200 && str_starts_with($csv['body'],"\xEF\xBB\xBF") && str_contains($csv['body'],"'=1+1"));
check('公開閲覧例へ変更',$rPost($rOffice,'card','role',['role'=>'public'])['status']===303);
$html=$rOffice->request('/manage/review.php?screen=card')['body'];
check('公開照会例は有効時に氏名番号のみ',str_contains($html,'確認 太郎') && str_contains($html,'DEMO123-G-100001') && !str_contains($html,'確認用の住所') && !str_contains($html,'競技 国内B'));
check('公開立場から会計画面へ直接GET拒否',$rOffice->request('/manage/review.php?screen=billing')['status']===403);
check('公開立場の確認票書出し拒否',$rOffice->request('/manage/review.php?download=notes')['status']===403);
$rPost($rOffice,'overview','role',['role'=>'office']);
check('保険はTemporaryを受付しない',$rPost($rOffice,'insurance','save',['type'=>'temporary','count'=>'4'])['status']===422);
check('保険の架空4名設定',$rPost($rOffice,'insurance','save',['type'=>'general','count'=>'4'])['status']===303);
$xlsx=$rOffice->request('/manage/review.php?screen=insurance&download=insurance');
check('確認Excelを指定形式で生成',$xlsx['status']===200 && str_starts_with($xlsx['body'],'PK'));
file_put_contents('/tmp/review-insurance.xlsx',$xlsx['body']);
check('空の資料取下げも失敗しない',$rPost($rOffice,'documents','remove')['status']===303);
check('委員会資料の公開を拒否',$rPost($rOffice,'documents','save',['visibility'=>'public'])['status']===422);
check('危険なURL拒否',$rPost($rOffice,'links','save',['url'=>'javascript:alert(1)'])['status']===422);
check('配列入力を拒否',$rPost($rOffice,'notices','save',['title'=>['bad']])['status']===422);
$page=$rOffice->request('/manage/review.php');preg_match('/name="revision" value="([0-9]+)"/',$page['body'],$oldRev);
$token=$rOffice->token('/manage/review.php');$rPost($rOffice,'overview','reset');
check('古いフォーム再送409',$rOffice->request('/manage/review.php',['_csrf'=>$token,'revision'=>$oldRev[1],'action'=>'reset'])['status']===409);
// 付与済み権限も毎要求確認し、別画面への遷移・ダウンロードから回避できないことを確認。
try {
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=7 AND role_id=1");
    check('操作確認の権限失効を即時反映',$rOffice->request('/manage/review.php')['status']===403);
    check('失効時は確認Excelも拒否',$rOffice->request('/manage/review.php?screen=insurance&download=insurance')['status']===403);
} finally { $db->exec('UPDATE portal_user_role_grants SET revoked_at=NULL WHERE user_id=7 AND role_id=1'); }

// 純粋モデルの状態遷移も試験し、実DBを操作せずに境界・資格の再計算を確認する。
$model=new JmrcReviewWorkspace(JmrcReviewWorkspace::initial(7));
$act=static function(string $screen,string $action,array $values=[]) use(&$model): void { $model->apply($screen,['action'=>$action,'instance'=>$model->state['instance'],'revision'=>(string)$model->state['revision']]+$values); };
$reject=static function(string $name,string $screen,string $action,array $values=[],int $status=422) use(&$model,$act): void {
    $before=$model->state;try { $act($screen,$action,$values);check($name,false); }catch(\Jmrc\Auth\HttpFailure $error) { check($name,$error->status===$status && $model->state===$before); }
};
$reject('ライセンスなしGeneral拒否','memberships','save');
$act('clubs','save',['fee'=>'10000']);$act('clubs','issue');$reject('未入金のクラブ承認拒否','clubs','approve');
$act('billing','confirm',['target'=>'club','amount'=>'10000','reference'=>'CLUB-1']);$act('clubs','approve');
$act('licenses','save');$act('memberships','save');$act('memberships','save');
check('同年度の確認申請と請求は一意',count($model->state['invoices'])===2 && !$model->eligibility()['active']);
$act('affiliations','approve');$act('billing','confirm',['amount'=>'1000','reference'=>'PART-1']);
check('不足入金で資格を有効にしない',!$model->eligibility()['active']);
$act('billing','confirm',['amount'=>'1000','reference'=>'PART-2']);
check('必要承認と満額で資格有効',$model->eligibility()['active']);
$act('billing','confirm',['amount'=>'1000','reference'=>'PART-2']);
check('同じ入金の再操作でも二重計上しない',$model->paid('general')===2000);
$reject('識別番号の別金額流用を拒否','billing','confirm',['amount'=>'500','reference'=>'PART-2']);
$act('billing','reverse');check('取消を会員証へ即反映',!$model->eligibility()['active'] && $model->paid('general')===1000);
$act('billing','confirm',['amount'=>'1000','reference'=>'PART-3']);$act('licenses','save',['number'=>'CHANGED']);
check('番号変更後は旧会員証を失効',!$model->eligibility()['active']);
$act('operations','save',['status'=>'active']);$act('temporary','save',['event'=>'A']);
$reject('Temporary同日別イベント拒否','temporary','save',['event'=>'B']);
$reject('事務局の立場だけでは主催承認できない','temporary','approve',[],403);
$act('overview','role',['role'=>'club']);$act('temporary','approve',['event'=>'A']);
$act('overview','role',['role'=>'office']);$act('billing','confirm',['target'=>'temporary','amount'=>'500','reference'=>'TEMP-1']);
check('Temporaryの主催承認と独立請求',$model->state['temporary']['A']['approved'] && $model->paid('temporary:A')===500);
$act('operations','save',['status'=>'unpaid']);$act('bank','import');$act('bank','import');$act('bank','match');$act('bank','match');
check('CSV再取込・再照合は二重計上しない',count($model->state['bank'])===2 && $model->paid('general')===2000);
check('曖昧なCSV入金は手動確認',$model->state['bank']['DEMO-BANK-2']['status']==='複数候補・手動確認');
foreach(['suspended','expired','pending','unpaid'] as $status) { $act('operations','save',['status'=>$status]);check('無効な状態例 '.$status,!$model->eligibility()['active']); }
$act('notices','save',['title'=>'PRIVATE-DEMO']);$act('notices','save',['title'=>'PUBLIC-DEMO','visibility'=>'public']);
$act('overview','role',['role'=>'public']);check('公開一覧へ非公開記事を漏らさない',count($model->entries('notices'))===1 && $model->entries('notices')[0]['title']==='PUBLIC-DEMO');
$reject('公開立場の投稿を拒否','notices','save',[],403);$act('overview','role',['role'=>'office']);
$act('operations','save',['status'=>'active']);$invoices=$model->state['invoices'];$payments=$model->state['payments'];$act('privacy','erase');
check('削除例ではプロフィールのみ消し会計を保持',$model->state['profile']===null && $model->state['license']===null && $model->state['invoices']===$invoices && $model->state['payments']===$payments && !$model->eligibility()['active']);
check('すべての操作確認で本番相当の業務13表を更新しない',$reviewSnapshot()===$reviewBefore);
