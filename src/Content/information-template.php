<?php
// Path: src/Content/information-template.php
// 機能: 事務局確認用の情報ページを描画する共通テンプレート。公開処理・業務フォームは持たない。
declare(strict_types=1);
// InformationPreview::render()の変数を使用し、テンプレート単体では実行しない。
if (!isset($catalog, $selected, $e, $url, $offline)) { return; }
$categoryLabel = static fn(array $topic): string => implode('／', array_map(static fn($key)=>self::CATEGORIES[$key], $topic['categories'] ?? [$topic['category']]));
$detail = static function (string $id, array $topic) use ($catalog, $selected, $offline, $e, $categoryLabel): void {
    $sources = $catalog['sources'][$id] ?? [];
    // Q13は文書単位の分類。分類から開いた詳細では、その分類の原本候補だけを表示する。
    if ($id === 'HP-20' && !$offline && in_array($selected['category'], $topic['categories'] ?? [], true)) {
        $sources = array_values(array_filter($sources, static fn($row)=>($row['category'] ?? '') === $selected['category']));
    }
    $years = array_values(array_unique(array_column($sources, 'year')));
    ?>
    <article class="info-detail" data-topic="<?=$e($id)?>">
      <p class="info-meta"><?=$e($id)?> · <?=$e($categoryLabel($topic))?> · <?=$e($topic['page'])?></p>
      <h2><?=$e($topic['title'])?></h2>
      <p class="info-status"><?=$topic['state']==='excluded'?'移行対象外':'確認用の説明・原文未移行'?></p>
      <p class="info-body"><?=$e($topic['summary'])?></p>
      <?php if (in_array($id, ['HP-13','HP-18','HP-20','HP-21','HP-23'], true)): ?>
      <p><button class="office-button" type="button" disabled><?=$id==='HP-23'?'問い合わせ先は確認中':($id==='HP-20'?'見舞金の請求は保留':'受付開始は保留')?></button></p>
      <?php endif; ?>
      <aside class="office-readonly"><strong>確認が残る事項</strong><p><?=$e($topic['pending'])?>。対象年度・本文・添付・公開項目・正式な移行先は、回答と原本の確認後に反映します。</p></aside>
      <?php if ($topic['classification_note'] !== ''): ?><p class="office-help"><?=$e($topic['classification_note'])?></p><?php endif; ?>
      <dl class="info-facts">
        <div><dt>元資料の年度候補</dt><dd><?=$e(implode('、', $years) ?: '原本確認待ち')?> <small>現年度の有効性を示すものではありません。</small></dd></div>
        <div><dt>発行団体</dt><dd><?=$e($topic['publisher'])?></dd></div>
        <div><dt>本文・添付の版</dt><dd>画面確認版 <?=$topic['revision']?>。原本・添付の版は未確認。資料ファイルは未掲載。</dd></div>
        <div><dt>引継ぎ方針</dt><dd><?=$e($topic['policy'])?></dd></div>
        <div><dt>公開状態</dt><dd><?=$topic['state']==='excluded'?'移行対象外':'運営確認中・公開未承認'?></dd></div>
      </dl>
      <details class="office-disclosure info-sources"><summary>旧HPの参照元候補（<?=count($sources)?>件）</summary>
        <div class="info-inset"><p>2026年10月31日の台帳です。以下は元URLであり、正式な移行先ではありません。本文・年度・リンク先の到達性は移行前に確認します。</p>
          <ul class="office-records"><?php foreach ($sources as $source): $safeUrl = self::sourceUrl($source['url']); ?>
            <li><strong><?=$e($source['title'])?></strong><p class="office-meta">元年度候補：<?=$e($source['year'])?></p>
              <?php if (isset($source['category'])): ?><p class="office-meta" data-document-category="<?=$e($source['category'])?>">分類：<?=$e(self::CATEGORIES[$source['category']])?> ／ 発行団体：<?=$e($source['publisher'])?></p><?php endif; ?>
              <?php if ($safeUrl !== null): ?><a class="info-source-link" href="<?=$e($safeUrl)?>" target="_blank" rel="noopener noreferrer">旧ページを確認（別タブ）<span><?=$e($safeUrl)?></span></a><?php else: ?><p>元URLは確認待ちです。</p><?php endif; ?>
            </li>
          <?php endforeach; ?></ul>
        </div>
      </details>
    </article>
    <?php
};
?>
<!doctype html>
<html lang="ja"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
  <title>旧HPの情報ページ・確認用 | JMRC近畿ポータル</title>
  <?php if ($offline): ?>
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'">
  <style><?php foreach (['dashboard','office','information'] as $asset) { echo file_get_contents(dirname(__DIR__, 2).'/public/manage/assets/'.$asset.'.css'); } ?></style>
  <?php else: foreach (['dashboard','office','information'] as $asset): ?>
  <link rel="stylesheet" href="/manage/assets/<?=$asset?>.css?v=20261031">
  <?php endforeach; endif; ?>
</head><body>
<a class="skip-link" href="#main">本文へ移動</a>
<header class="portal-header"><div class="portal-container portal-header-inner">
  <a class="portal-brand" href="<?=$offline?'#main':'/manage/'?>">JMRC近畿ポータル</a>
  <?php if (!$offline && $csrf !== null): ?><form method="post" action="/manage/logout.php"><input type="hidden" name="_csrf" value="<?=$e($csrf)?>"><button class="signout" type="submit">サインアウト</button></form><?php else: ?><span>閲覧専用の確認資料</span><?php endif; ?>
</div></header>
<main id="main" class="portal-container office-page" tabindex="-1">
  <?php if (!$offline): ?><nav class="office-breadcrumb" aria-label="現在の位置"><a href="/manage/">マイページへ</a><span aria-hidden="true">/</span><a href="/manage/review.php">機能の操作確認</a></nav><?php endif; ?>
  <h1>旧HPから引き継ぐ情報ページ</h1>
  <p class="office-intro">お知らせ・資料・日程・関連リンクの配置と、内容の探しやすさをご確認ください。</p>
  <aside class="info-banner" aria-label="確認用画面の注意"><strong>事務局の確認用・一般公開前</strong><p>掲載本文は構成を確認するための説明です。旧記事・名簿・添付の原文はまだ移していません。カレンダーは架空例です。公開・申請受付・送信は行いません。</p></aside>
  <?php if ($offline): ?><p class="office-help">このHTMLは閲覧専用です。見出しを開いて詳細を確認できます。検索・日程の絞り込みは事務局用の認証画面で確認できます。外部リンクを選んだ場合だけ元サイトへ移動します。</p><?php endif; ?>
  <?php if (!empty($catalog['decisions'])): ?>
  <details class="office-disclosure info-answer-decisions"><summary>10月31日の追加回答を確認する（Q01～Q14・既存4項目）</summary><div class="info-inset">
    <p>回答とユーザーへの追加確認を反映しました。一般公開・受付開始・外部通知は保留です。以下は確定した方針と、実運用までに確認・実装が必要な事項です。</p>
    <?php foreach ($catalog['decisions'] as $decision): ?><section class="office-panel" data-answer="<?=$e($decision['id'])?>"><h3><?=$e($decision['id'].' '.$decision['title'])?></h3><p><?=$e($decision['confirmed'])?></p><p class="office-help"><?=$e($decision['pending'])?></p></section><?php endforeach; ?>
  </div></details>
  <?php endif; ?>
  <nav class="info-nav" aria-label="情報ページの種類">
    <?php foreach (self::SECTIONS as $key=>$label): ?><a href="<?=$e($url(['section'=>$key]))?>" <?=!$offline && $selected['section']===$key && $selected['item']===''?'aria-current="page"':''?>><?=$e($label)?></a><?php endforeach; ?>
  </nav>
  <?php if ($selected['item'] !== '' && !$offline): ?>
    <section class="office-panel"><?php $detail($selected['item'], $catalog['topics'][$selected['item']]); ?></section>
    <p><a class="office-button secondary" href="<?=$e($url(['section'=>$selected['section']]))?>">一覧へ戻る</a></p>
  <?php else:
    $sections = $offline ? self::SECTIONS : [$selected['section']=>self::SECTIONS[$selected['section']]];
    foreach ($sections as $section=>$label): ?>
    <section id="<?=$section?>" class="info-section" aria-label="<?=$e($label)?>">
      <h2><?=$e($label)?></h2>
      <?php if ($section === 'calendar'): ?>
        <p>競技会・会議・事務局在席／休業日をまとめた表示例です。日付・時間はすべて日本時間（Asia/Tokyo）で、実際の予定ではありません。</p>
        <aside class="office-readonly"><strong>Q11：事務局員が更新し、事務局長が承認</strong><p>元データの形式は任意です。Googleカレンダーのような表示を目指しますが、自動同期は決定していません。予定ごとの公開範囲と実日程は確認待ちです。</p></aside>
        <?php if (!$offline): ?><form class="office-form info-filter" method="get" action="/manage/information-preview.php"><input type="hidden" name="section" value="calendar"><div class="office-field"><label for="kind">予定の種類</label><select id="kind" name="kind"><option value="">すべて</option><?php foreach (['competition'=>'競技会','meeting'=>'会議','office'=>'事務局在席・休業'] as $key=>$name): ?><option value="<?=$key?>" <?=$selected['kind']===$key?'selected':''?>><?=$name?></option><?php endforeach; ?></select></div><div class="office-submit"><button class="office-button">日程を絞り込む</button></div></form><?php endif; ?>
        <ol class="info-calendar"><?php foreach (self::calendar($offline?'':$selected['kind']) as $event): ?>
          <li data-source-key="<?=$e($event['key'])?>"><div class="info-date"><time datetime="<?=$event['date']?>"><?=$event['date']?></time><span><?=$e($event['time'])?>（日本時間）</span></div><div><p class="info-status"><?=$e($event['status'])?></p><h3><?=$e($event['title'])?></h3><p><?=$e($event['detail'])?></p><small>更新：事務局員 ／ 承認：事務局長 ／ 公開範囲：確認待ち</small></div></li>
        <?php endforeach; ?></ol>
        <p><a class="office-button secondary" href="<?=$e($url(['section'=>'calendar','item'=>'HP-05']))?>">旧日程表と引継ぎ方針を確認</a></p>
      <?php else:
        $filter = $offline ? self::selection(['section'=>$section]) : $selected;
        $topics = self::matching($filter, $catalog);
        if ($section === 'information'): ?>
          <p>事務局・運営委員会・全国JMRC・JMRC近畿の4分類で案内します。すべて事務局員が編集し、事務局長が承認します。現在は確認用の閲覧だけで、編集・公開処理には接続していません。</p>
          <?php if (!$offline): ?><nav class="info-categories" aria-label="お知らせの分類"><a href="<?=$e($url(['section'=>'information']))?>">すべて</a><?php foreach (self::CATEGORIES as $key=>$name): ?><a href="<?=$e($url(['section'=>'information','category'=>$key]))?>" <?=$selected['category']===$key?'aria-current="page"':''?>><?=$name?></a><?php endforeach; ?></nav><?php endif; ?>
        <?php elseif ($section === 'documents'): ?>
          <p>制度の説明、会則・規程、空の様式、レンタル機器の案内を探せる構成です。原本の年度・版・公開可否が未確認のため、添付ダウンロードは保留しています。</p><p class="office-readonly">非公開の委員会資料、記入済み名簿、保険の個人帳票はこのページに掲載しません。</p>
        <?php elseif ($section === 'links'): ?>
          <p>競技の詳細は各部会のトップページ、JAF規則等は公式ページへ案内する方針です。ポイントの登録・確定は既存エントリー側で管理します。詳細欄の元URLは調査記録であり、正式な移行先・トップURL・問い合わせ先は確認待ちです。</p>
        <?php else: ?>
          <p>HP-01～HP-24と旧URL154件の調査記録です。Q12により部会の旧記事は部会へ移管し、クラブ名簿・旧年度の規則・様式は原則として新ポータルへ移しません。HP-24も移行対象外です。記録を残すことと、公開移行することは別です。旧サイトの削除や転送設定は行っていません。</p>
        <?php endif; ?>
        <?php if (!$offline): ?>
        <form class="office-form info-filter" method="get" action="/manage/information-preview.php" aria-label="情報を絞り込む">
          <input type="hidden" name="section" value="<?=$section?>">
          <div class="office-field"><label for="category">分類</label><select name="category" id="category"><option value="">すべて</option><?php foreach (self::CATEGORIES as $key=>$name): ?><option value="<?=$key?>" <?=$selected['category']===$key?'selected':''?>><?=$name?></option><?php endforeach; ?></select></div>
          <div class="office-field"><label for="division">競技部会</label><select name="division" id="division"><option value="">すべて</option><?php foreach (self::DIVISIONS as $key=>$name): ?><option value="<?=$key?>" <?=$selected['division']===$key?'selected':''?>><?=$name?></option><?php endforeach; ?></select></div>
          <div class="office-field"><label for="year">元資料の年度候補</label><input type="text" name="year" id="year" inputmode="numeric" pattern="(19|20)[0-9]{2}" maxlength="4" placeholder="例：2026" value="<?=$e($selected['year'])?>"></div>
          <div class="office-field"><label for="query">キーワード</label><input type="search" name="q" id="query" maxlength="100" value="<?=$e($selected['q'])?>"></div>
          <div class="office-submit"><button class="office-button">絞り込む</button><a class="office-button secondary" href="<?=$e($url(['section'=>$section]))?>">条件を解除</a></div>
        </form>
        <?php endif; ?>
        <p class="office-meta" role="status"><?=count($topics)?>件の確認対象</p>
        <?php if (!$topics): ?><p class="office-readonly">該当する確認対象はありません。条件を変えてお試しください。</p><?php endif; ?>
        <?php if ($offline && $section === 'migration'): foreach ($topics as $id=>$topic): ?>
          <details class="office-disclosure" id="<?=$id?>"><summary><?=$e($id.' '.$topic['title'])?></summary><div class="info-inset"><?php $detail($id, $topic); ?></div></details>
        <?php endforeach; else: ?>
          <ul class="info-grid"><?php foreach ($topics as $id=>$topic): ?>
            <li data-topic="<?=$id?>"><article><p class="info-meta"><?=$e($categoryLabel($topic))?> · <?=$e($topic['page'])?></p><h3><a href="<?=$e($url(array_filter(['section'=>$section,'item'=>$id,'category'=>$id==='HP-20'?$selected['category']:''])))?>"><?=$e($topic['title'])?></a></h3><p><?=$e($topic['summary'])?></p><p class="info-card-foot"><?=$e($id)?> <span><?=$topic['state']==='excluded'?'移行対象外':'公開前の確認用'?></span></p></article></li>
          <?php endforeach; ?></ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
  <section class="office-disclosure"><h2 class="info-inset info-notes-heading">事務局で追加確認いただきたいこと</h2><ul><li>2027年度の規則・クラス一覧、結果の取込元・形式</li><li>公開する原稿・名簿の項目、カレンダーの公開範囲</li><li>正式な移管先・部会トップURLと、旧ページ約1年の残置方法</li></ul><p>追加回答は上の「10月31日の追加回答を確認する」へ反映しました。残る条件への補足は既存の回答表で共有してください。画面を確認しても、公開承認や受付開始にはなりません。</p><p><a class="office-button secondary" href="https://docs.google.com/spreadsheets/d/1z0WuTupTRBSXgzMNBRjosTNgOi13HyVPYtn1knIsato/edit#gid=202610271&amp;range=A15:I28" target="_blank" rel="noopener noreferrer">回答・追加確認表を開く</a></p></section>
</main><footer class="portal-footer"><div class="portal-container"><small>2026年10月31日版 · JMRC近畿ポータル／情報ページ確認用 · 一般公開・受付開始は保留</small></div></footer>
</body></html>
