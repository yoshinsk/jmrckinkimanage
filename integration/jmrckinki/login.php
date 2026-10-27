<?php
// Path: integration/jmrckinki/login.php → entry/login.php
// 機能: 共通認証と許可済み戻り先を使うログイン。既存header/footerとrole別既定先を維持する。
declare(strict_types=1);
require_once __DIR__ . '/common/csrf.php';

$app = $_POST['app'] ?? $_GET['app'] ?? null;
$key = $_POST['return_key'] ?? $_GET['return_key'] ?? null;
$next = $_POST['next'] ?? $_GET['next'] ?? null;
// 未ログインでも任意URLや配列をフォームへ反映しない。
foreach ([$app, $key, $next] as $argument) {
    if ($argument !== null && !is_string($argument)) {
        throw new \Jmrc\Auth\HttpFailure(400, 'Invalid return destination');
    }
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!is_string($email) || !is_string($password) || !auth_login(trim($email), $password)) {
        http_response_code(401);
        $error = 'サインインに失敗しました';
    }
}
if (auth_user()) {
    header('Location: ' . jmrc_auth()->afterLogin($app, $key, $next), true, 303);
    exit;
}
require_once __DIR__ . '/common/header.php';
?>
<h1 class="h4 mb-3">サインイン</h1>
<?php if ($error): ?><p role="alert"><?=h($error)?></p><?php endif; ?>
<form method="post">
  <?=csrf_field()?>
  <?php foreach (['app'=>$app,'return_key'=>$key,'next'=>$next] as $name=>$value): ?>
    <?php if ($value !== null): ?><input type="hidden" name="<?=h($name)?>" value="<?=h($value)?>"><?php endif; ?>
  <?php endforeach; ?>
  <label>メールアドレス<input type="email" name="email" autocomplete="username" required></label>
  <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit">サインイン</button>
</form>
<p><a href="/entry/forgot.php">パスワード再設定</a></p>
<?php require_once __DIR__ . '/common/footer.php'; ?>
