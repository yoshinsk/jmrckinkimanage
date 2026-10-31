<?php
// Path: tests/auth/information_http.php
// 機能: 旧HP試作の権限・元年度・候補URL・閲覧専用境界を、隔離HTTPSと架空DBで検証する。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once '/srv/auth/src/Content/InformationPreview.php';
use Jmrc\Content\InformationPreview;
$infoOffice = new Browser(); $infoOffice->login(7);
$infoUsers = [];
foreach ([1,2,3] as $id) { $infoUsers[$id] = new Browser(); $infoUsers[$id]->login($id); }
// 認証に伴う監査だけを除外し、既存entryとポータルの全業務表を前後比較する。
$infoTables = array_filter($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN), static fn($name)=>!in_array($name,['audit_logs','portal_audit_events'],true));
$infoChecksum = static fn(): array=>$db->query('CHECKSUM TABLE '.implode(',',array_map(static fn($name)=>'`'.$name.'`',$infoTables)))->fetchAll(PDO::FETCH_ASSOC);
$infoBefore = $infoChecksum();
check('旧HP試作の比較対象表が実在',count($infoBefore)>30 && !in_array(null,array_column($infoBefore,'Checksum'),true));
$base = '/manage/information-preview.php';
check('旧HP試作は匿名401',(new Browser())->request($base)['status']===401);
foreach ($infoUsers as $id=>$browser) {
    foreach (['','?item=HP-19','?section=migration','?download=all'] as $query) {
        check('旧HP試作の直URL拒否 '.$id.$query,$browser->request($base.$query)['status']===403);
    }
    check('旧HP試作入口を権限なしに表示しない '.$id,!str_contains($browser->request('/manage/')['body'],'href="'.$base.'"'));
}
check('事務局へ旧HP試作入口を表示',str_contains($infoOffice->request('/manage/')['body'],'href="'.$base.'"'));
$catalog = InformationPreview::catalog();
check('旧HP試作は24対応・154候補',count($catalog['topics'])===24 && array_sum(array_map('count',$catalog['sources']))===154);
check('追加回答14件と既存4件を表示',count($catalog['decisions'])===18);
foreach (['committee','national'] as $category) {
    $filtered = $infoOffice->request($base.'?section=information&category='.$category);
    check('会則は文書別の両分類に現れる '.$category,str_contains($filtered['body'],'data-topic="HP-20"'));
    $detail = $infoOffice->request($base.'?item=HP-20&category='.$category);
    $other = $category==='committee'?'national':'committee';
    check('会則の分類内原本は2件 '.$category,substr_count($detail['body'],'data-document-category="'.$category.'"')===2);
    check('別分類の原本は混在しない '.$category,!str_contains($detail['body'],'data-document-category="'.$other.'"'));
}
$answered = $infoOffice->request($base)['body'];
check('事務局編集・事務局長承認の回答反映',str_contains($answered,'すべて事務局員が編集し、事務局長が承認'));
check('有効戦数の切上げと最新照会を画面にも反映',str_contains($answered,'全7戦なら5戦') && str_contains($answered,'照会時点の最新資格'));
check('保険は氏名とクラブ名の連絡のみ',str_contains($answered,'入力は被保険者名・クラブ名の2項目'));
check('旧クラブ名簿などの移行対象見直し',str_contains($answered,'クラブ名簿、旧年度の規則・様式は原則として新ポータルへ移さない'));
foreach (InformationPreview::SECTIONS as $section=>$title) {
    $page = $infoOffice->request($base.'?section='.$section);
    check('旧HP試作の一覧 '.$section,$page['status']===200 && str_contains($page['body'],$title) && str_contains($page['body'],'事務局の確認用・一般公開前'));
    foreach (['no-store','frame-ancestors','noindex','no-referrer'] as $header) {
        check('旧HP試作の応答保護 '.$section.' '.$header,(bool)array_filter($infoOffice->lastHeaders,static fn($line)=>str_contains($line,$header)));
    }
}
foreach ($catalog['topics'] as $id=>$topic) {
    $page = $infoOffice->request($base.'?item='.$id);
    check('旧HP試作の詳細 '.$id,$page['status']===200 && str_contains($page['body'],$topic['title']) && str_contains($page['body'],InformationPreview::escape($topic['pending'])));
    check('旧HP試作は資料ダウンロードなし '.$id,!str_contains($page['body'],'download=') && str_contains($page['body'],'資料ファイルは未掲載'));
}
$page = $infoOffice->request($base.'?section=information&category=committee');
check('お知らせ分類の絞込',str_contains($page['body'],'data-topic="HP-19"') && !str_contains($page['body'],'data-topic="HP-13"'));
$page = $infoOffice->request($base.'?section=migration&year=2008');
check('過年度を現年度に変更しない',str_contains($page['body'],'data-topic="HP-21"') && str_contains($page['body'],'value="2008"'));
$page = $infoOffice->request($base.'?section=links&division=rally');
check('競技部会の絞込',str_contains($page['body'],'data-topic="HP-08"') && !str_contains($page['body'],'data-topic="HP-06"'));
$page = $infoOffice->request($base.'?q='.rawurlencode('存在しないXYZ'));
check('該当なしの案内',str_contains($page['body'],'該当する確認対象はありません'));
$page = $infoOffice->request($base.'?q='.rawurlencode('<script>alert(1)</script>'));
check('検索値のHTMLをエスケープ',!str_contains($page['body'],'<script>') && str_contains($page['body'],'&lt;script&gt;'));
foreach (['?section[]=links','?category=bad','?division=bad','?year=2027x','?kind=bad','?q='.str_repeat('x',101)] as $query) {
    check('不正フィルターを拒否 '.$query,$infoOffice->request($base.$query)['status']===422);
}
check('任意ファイルを詳細として指定不可',$infoOffice->request($base.'?item=../../private/auth.config.php')['status']===404);
check('POSTで公開や保存はできない',$infoOffice->request($base,['action'=>'publish','body'=>'PUBLIC'])['status']===405);
check('閲覧専用のAllow',in_array('Allow: GET',$infoOffice->lastHeaders,true));
check('PUTも拒否',$infoOffice->request($base,null,'PUT')['status']===405);
foreach (['/src/Content/information-catalog.php','/src/Content/information-template.php','/manage/information-catalog.php'] as $path) {
    check('カタログ原本は公開領域外 '.$path,$infoOffice->request($path)['status']===404);
}
foreach (['javascript:alert(1)','data:text/html,bad','//example.org','https://user:pass@example.org/','https://example.org/\x0a','https://example.org\\@evil.test'] as $url) {
    check('危険な参照URLをリンクにしない '.$url,InformationPreview::sourceUrl($url)===null);
}
check('旧HTTP URLを勝手にHTTPS化しない',InformationPreview::sourceUrl('http://www.jmrc-kinki.net/index.html')==='http://www.jmrc-kinki.net/index.html');
check('カレンダーの更新元は一意',count(array_unique(array_column(InformationPreview::calendar(),'key')))===5);
$page=$infoOffice->request($base.'?section=calendar&kind=office');
check('架空の事務局予定だけ表示',str_contains($page['body'],'data-source-key="demo-office-1"') && !str_contains($page['body'],'data-source-key="demo-competition-1"') && str_contains($page['body'],'Asia/Tokyo'));
check('HP24をお知らせに混入しない',!str_contains($infoOffice->request($base)['body'],'data-topic="HP-24"'));
check('HP24は移行対象外の確認のみ',str_contains($infoOffice->request($base.'?item=HP-24')['body'],'移行対象外'));
$infoGrants = $db->query('SELECT id,revoked_at,updated_at FROM portal_user_role_grants WHERE user_id=7 AND role_id=1')->fetchAll(PDO::FETCH_ASSOC);
try {
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP() WHERE user_id=7 AND role_id=1");
    check('権限取消を次の一覧に反映',$infoOffice->request($base)['status']===403);
    check('権限取消を詳細に反映',$infoOffice->request($base.'?item=HP-19')['status']===403);
    check('権限取消でマイページ入口も消える',!str_contains($infoOffice->request('/manage/')['body'],'href="'.$base.'"'));
} finally {
    // 試験自身の権限取消を、ON UPDATEで変わる時刻も含めて元のfixtureへ戻す。
    $restoreInfoGrant = $db->prepare('UPDATE portal_user_role_grants SET revoked_at=?,updated_at=? WHERE id=?');
    foreach ($infoGrants as $grant) { $restoreInfoGrant->execute([$grant['revoked_at'],$grant['updated_at'],$grant['id']]); }
}
$infoAfter=$infoChecksum();
$infoChanged=[];
foreach ($infoBefore as $index=>$row) { if ($row!==$infoAfter[$index]) { $infoChanged[]=$row['Table']; } }
check('旧HP試作で既存entry・全業務表を変更しない'.($infoChanged?' ['.implode(',',$infoChanged).']':''),$infoAfter===$infoBefore);
