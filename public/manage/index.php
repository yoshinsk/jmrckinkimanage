<?php
// Path: public/manage/index.php
// 機能: 共通認証のマイページ。利用可能な導線・準備中の機能を分け、小画面と明示権限に対応する。
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$auth = jmrc_auth();
$identity = $auth->identity();
if (!$identity) {
    header('Location: /manage/login.php');
    exit;
}
$config = jmrc_auth_config();
$db = jmrc_db();
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// 導線の表示だけを判断する。年度の切替や受付日・資格・権限の業務判定は各遷移先が引き続き行う。
// shadowの年度しかない間は、会員登録・会計・会員証の導線を準備中として扱う。
$portalReady = (bool)$db->query("SELECT EXISTS(SELECT 1 FROM portal_years WHERE source_mode='portal')")->fetchColumn();
$scopeQuery = $db->prepare("SELECT DISTINCT scope_club_id FROM portal_user_role_grants WHERE user_id=? AND scope_type='club' AND scope_club_id IS NOT NULL");
$scopeQuery->execute([$identity['id']]);
$clubIds = $scopeQuery->fetchAll(PDO::FETCH_COLUMN);
// 旧entryのrole_idから管理権限を推定せず、既存のcanで承認・失効・期間を確認する。
$canForAnyClub = static function (string $permission) use ($auth, $clubIds): bool {
    if ($auth->can($permission)) { return true; }
    foreach ($clubIds as $clubId) {
        if ($auth->can($permission, (int)$clubId)) { return true; }
    }
    return false;
};
$groups = ['会員情報' => [
    ['title'=>'個人会員の申請','url'=>'memberships.php','description'=>'個人会員の申請内容と状況を確認します。','enabled'=>$portalReady],
    ['title'=>'Generalデジタル会員証','url'=>'card.php','description'=>'会員証と現在の資格を確認します。','enabled'=>$portalReady],
    ['title'=>'ライセンス情報','url'=>'licenses.php','description'=>'JAFライセンスの登録・更新を行います。','enabled'=>($config['license_self_service_enabled']??false)===true],
    ['title'=>'スポーツ安全保険','url'=>'insurance.php','description'=>'加入希望の申請と確認用Excelを扱います。','enabled'=>($config['insurance_intents_enabled']??false)===true],
], '会費・クラブ' => [
    ['title'=>'会費・入金状況','url'=>'billing.php','description'=>'会費の請求・入金状況を確認します。','enabled'=>$portalReady],
], '事務局の設定' => []];
if ($canForAnyClub('club.registration.write')) {
    $groups['会費・クラブ'][] = ['title'=>'正会員クラブの年度登録','url'=>'clubs.php','description'=>'担当クラブの年度登録・年会費を確認します。','enabled'=>$portalReady];
}
if ($canForAnyClub('temporary.approve')) {
    $groups['会費・クラブ'][] = ['title'=>'Temporaryの主催クラブ承認','url'=>'temporary-approve.php','description'=>'担当する主催クラブの申請を確認・承認します。','enabled'=>$portalReady];
}
if ($auth->can('billing.confirm')) {
    $groups['会費・クラブ'][] = ['title'=>'ゆうちょ銀行CSV・入金照合','url'=>'bank.php','description'=>'銀行CSVを取り込み、入金内容を照合します。','enabled'=>($config['yucho_import_enabled']??false)===true];
}
if ($auth->can('billing.accounts.manage')) {
    $groups['事務局の設定'][] = ['title'=>'入金先・決済URLの設定','url'=>'bank-settings.php','description'=>'用途別口座・ペイメントコアURL・CSV取込元を管理します。','enabled'=>($config['bank_master_enabled']??false)===true];
}
if ($auth->can('profiles.correct')) {
    $groups['事務局の設定'][] = ['title'=>'会員情報の訂正・削除候補','url'=>'profile-correct.php','description'=>'氏名・生年月日の訂正と、削除候補の確認を行います。','enabled'=>($config['profile_corrections_enabled']??false)===true];
    // 付与済みの事務局権限で架空画面を説明する。情報公開など新たな実業務権限は付与しない。
    $groups['事務局の設定'][] = ['title'=>'機能の操作確認','url'=>'review.php','description'=>'架空データで登録・会計・公開情報の流れを確認します。受付は開始しません。','enabled'=>true];
}
$pending = [];
foreach ($groups as $items) {
    foreach ($items as $item) { if (!$item['enabled']) { $pending[] = $item; } }
}
$notice = $config['deployment_notice'] ?? null;
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>マイページ | JMRC近畿ポータル</title>
  <link rel="stylesheet" href="/manage/assets/dashboard.css?v=20261030">
</head>
<body>
<a class="skip-link" href="#main">本文へ移動</a>
<header class="portal-header">
  <div class="portal-container portal-header-inner">
    <a class="portal-brand" href="/manage/" aria-label="JMRC近畿ポータル マイページ">JMRC近畿ポータル</a>
    <form method="post" action="/manage/logout.php">
      <input type="hidden" name="_csrf" value="<?=$e($auth->csrfToken())?>">
      <button class="signout" type="submit">サインアウト</button>
    </form>
  </div>
</header>
<main id="main" class="portal-container" tabindex="-1">
  <h1>マイページ</h1>
  <p class="account"><span>サインイン中</span><span class="account-email"><?=$e($identity['email'])?></span></p>
  <?php if (is_string($notice) && $notice !== ''): ?>
    <p class="portal-notice" role="status"><?=$e($notice)?></p>
  <?php endif; ?>
  <section class="entry-panel" aria-labelledby="entry-heading">
    <div><h2 id="entry-heading">オンラインエントリー</h2><p>競技会の確認・申込は、既存のエントリー画面から行えます。</p></div>
    <a class="entry-link" href="/entry/login.php">オンラインエントリーへ</a>
  </section>
  <?php foreach ($groups as $heading => $items): $available = array_filter($items, static fn(array $item): bool => $item['enabled']); ?>
    <?php if ($available): ?>
      <section class="menu-section" aria-label="<?=$e($heading)?>">
        <h2><?=$e($heading)?></h2>
        <ul class="menu-list">
          <?php foreach ($available as $item): ?>
            <li><a class="menu-link" href="/manage/<?=$e($item['url'])?>"><span class="menu-title"><?=$e($item['title'])?></span><span class="menu-description"><?=$e($item['description'])?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($pending): ?>
    <details class="pending-features">
      <summary>準備中の機能 <span class="pending-count"><?=count($pending)?>件</span></summary>
      <p class="pending-note">次の機能は利用開始前です。受付条件などの確認が終わるまでお待ちください。</p>
      <ul>
        <?php foreach ($pending as $item): ?>
          <li><span><?=$e($item['title'])?></span><span class="pending-badge">準備中</span></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
</main>
<footer class="portal-footer"><div class="portal-container"><small>&copy; <?=gmdate('Y')?> JMRC近畿ポータル</small></div></footer>
</body>
</html>
