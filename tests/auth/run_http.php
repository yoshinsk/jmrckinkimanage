<?php
// Path: tests/auth/run_http.php
// 機能: 内部HTTPS経由で既存entryコピーとmanageの認証/認可を検証するCLIテスト。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }

final class Browser
{
    public array $cookies = [];
    public array $lastHeaders = [];

    /** リダイレクトを自動追従せず、Cookie/遷移/状態コードをそのまま観測する。 */
    public function request(string $path, ?array $form = null, string $method = 'GET'): array
    {
        $headers = ['Connection: close'];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k,$v)=>$k.'='.$v, array_keys($this->cookies), $this->cookies));
        }
        if ($form !== null) {
            $method = 'POST';
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create([
            'http'=>['method'=>$method,'header'=>implode("\r\n", $headers),
                'content'=>$form === null ? '' : http_build_query($form),'ignore_errors'=>true,
                'follow_location'=>0,'timeout'=>10],
            'ssl'=>['cafile'=>'/srv/tests/tls.crt','verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $body = file_get_contents('https://web'.$path, false, $context);
        $response = $http_response_header ?? [];
        $this->lastHeaders = $response;
        $status = (int)explode(' ', $response[0] ?? 'HTTP/1.1 0')[1];
        $location = null;
        foreach ($response as $line) {
            if (stripos($line,'Set-Cookie: ') === 0 && preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$line,$match)) {
                if ($match[2] === '' || preg_match('/Max-Age=0/i',$line)) { unset($this->cookies[$match[1]]); }
                else { $this->cookies[$match[1]] = $match[2]; }
            }
            if (stripos($line,'Location: ') === 0) { $location = substr($line,10); }
        }
        return ['status'=>$status,'location'=>$location,'body'=>$body === false ? '' : $body];
    }

    public function token(string $path = '/entry/login.php'): string
    {
        $page = $this->request($path);
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/',$page['body'],$match)) {
            throw new RuntimeException('CSRF form not found at '.$path.': HTTP '.$page['status']);
        }
        return $match[1];
    }

    public function login(int $id, array $return = [], string $password = 'lab-password-only'): array
    {
        $token = $this->token();
        return $this->request('/entry/login.php', ['_csrf'=>$token,'email'=>"u{$id}@example.invalid",'password'=>$password]+$return);
    }
}

/** 失敗時は固定のケース名とHTTP情報だけを返し、パスワード/セッションは出さない。 */
function check(string $name, bool $condition): void
{
    global $results;
    if (!$condition) { throw new RuntimeException($name); }
    $results[] = ['case'=>$name,'result'=>'PASS'];
}

function identity(Browser $browser, string $app = 'manage'): array
{
    $result = $browser->request('/'.$app.'/identity.php');
    if ($result['status'] !== 200) { throw new RuntimeException('identity HTTP '.$result['status']); }
    return json_decode($result['body'],true,512,JSON_THROW_ON_ERROR);
}

$results = [];
$db = new PDO('mysql:host=db;dbname=jmrckinki_auth_lab;charset=utf8mb4','lab_fixture','lab_fixture_only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("SET time_zone='+00:00'");
try {
    $fresh = new Browser();
    check('manage未ログインはloginへ', $fresh->request('/manage/')['location']==='/manage/login.php');
    check('manageから共通loginへ', $fresh->request('/manage/login.php')['location']==='/entry/login.php?app=manage&return_key=dashboard');
    check('未認証JSONは401', $fresh->request('/manage/identity.php')['status']===401);
    check('非公開コードはWebから取得不可', $fresh->request('/src/bootstrap.php')['status']===404);
    check('非公開設定はWebから取得不可', $fresh->request('/private/auth.config.php')['status']===404);
    foreach ([1=>'/entry/admin/events.php',2=>'/entry/club/events.php',3=>'/entry/entrant/events.php'] as $id=>$destination) {
        $browser = new Browser();
        $preToken = $browser->token(); $oldSid = $browser->cookies['PHPSESSID'];
        $result = $browser->login($id);
        check("role{$id} 旧既定先を維持", $result['status']===303 && $result['location']===$destination);
        check("role{$id} セッションID更新", $oldSid!==$browser->cookies['PHPSESSID']);
        $entry = identity($browser,'entry'); $manage = identity($browser);
        check("role{$id} 両アプリで同じuid/rid", $entry['uid']===$id && $entry['rid']===$id && $entry===$manage && $entry['verified']===true);
        check("role{$id} HTTPの実FPM版確認",$entry['sapi']==='fpm-fcgi' && $entry['php']==='8.4.27');
        check("role{$id} ログイン時CSRF更新", $manage['csrf']!==$preToken);
        $page = $browser->request($destination);
        check("role{$id} 既存競技会GET実コード", $page['status']===200 && str_contains($page['body'],'競技会'));
        $result = $browser->request('/entry/login.php?app=manage&return_key=dashboard');
        check("role{$id} ログイン済みmanage復帰",$result['status']===303 && $result['location']==='/manage/');
        check("role{$id} manage HTML表示",$browser->request('/manage/')['status']===200);
        $browser2 = new Browser();
        $result = $browser2->login($id,['app'=>'manage','return_key'=>'dashboard']);
        check("role{$id} 新ログインmanage復帰",$result['status']===303 && $result['location']==='/manage/');
    }
    $member = new Browser(); $member->login(3); $memberIdentity = identity($member);
    check('既存管理画面への昇格なし',$member->request('/entry/admin/events.php')['status']===403);
    check('旧主催クラブ画面への昇格なし',$member->request('/entry/club/events.php')['status']===403);
    check('担当クラブの閲覧許可',$member->request('/manage/permission.php?club_id=1')['status']===200);
    check('他クラブ閲覧を拒否',$member->request('/manage/permission.php?club_id=2')['status']===403);
    check('club scopeは全体権限にならない',$member->request('/manage/permission.php')['status']===403);
    check('未知permission拒否',$member->request('/manage/permission.php?permission=unknown')['status']===403);
    check('未決CSV権限を拒否',$member->request('/manage/permission.php?permission=personal.export&club_id=1')['status']===403);
    check('不正club_id拒否',$member->request('/manage/permission.php?club_id=0')['status']===400);
    check('CSRFなしPOST拒否',$member->request('/manage/permission.php?club_id=1',[])['status']===400);
    check('CSRF配列POST拒否',$member->request('/manage/permission.php?club_id=1',['_csrf'=>[]])['status']===400);
    check('CSRF付きPOST許可',$member->request('/manage/permission.php?club_id=1',['_csrf'=>$memberIdentity['csrf']])['status']===200);
    $admin = new Browser(); $admin->login(1);
    check('旧JMRC_ADMINもportal自動昇格なし',$admin->request('/manage/permission.php?club_id=1')['status']===403);
    $office = new Browser(); $office->login(7);
    check('事務局の明示全体権限',$office->request('/manage/permission.php?club_id=2')['status']===200);
    check('事務局兼財務の複数ロール',$office->request('/manage/permission.php?permission=billing.correct')['status']===200);
    check('portal付与で旧rid不変',identity($office,'entry')['rid']===3);
    $invalidRep = new Browser(); $invalidRep->login(8);
    check('CLUB_REPの誤ったglobal grant拒否',$invalidRep->request('/manage/permission.php?club_id=2')['status']===403);
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=UTC_TIMESTAMP(),status='revoked' WHERE user_id=3");
    check('権限取消が次HTTPで反映',$member->request('/manage/permission.php?club_id=1')['status']===403);
    $db->exec("UPDATE portal_user_role_grants SET revoked_at=NULL,status='active',valid_from=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE user_id=3");
    check('未来の付与を拒否',$member->request('/manage/permission.php?club_id=1')['status']===403);
    $db->exec("UPDATE portal_user_role_grants SET valid_from=UTC_TIMESTAMP()-INTERVAL 2 DAY,valid_until=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE user_id=3");
    check('期限切れ付与を拒否',$member->request('/manage/permission.php?club_id=1')['status']===403);
    $db->exec("UPDATE portal_user_role_grants SET valid_until=NULL,approved_by=NULL WHERE user_id=3");
    check('未承認付与を拒否',$member->request('/manage/permission.php?club_id=1')['status']===403);
    $db->exec("UPDATE portal_user_role_grants SET approved_by=1 WHERE user_id=3");
    foreach ([4=>'メール未認証',5=>'停止',6=>'controls欠落'] as $id=>$label) {
        $browser = new Browser();
        check($label.'ログイン拒否',$browser->login($id)['status']===401);
        check($label.'認証は成立しない',$browser->request('/manage/identity.php')['status']===401);
    }
    $wrong = new Browser();
    check('誤パスワード拒否',$wrong->login(3,[],'wrong')['status']===401);
    check('SQL文字列メールで認証不可',$wrong->request('/entry/login.php',['_csrf'=>$wrong->token(),'email'=>"' OR 1=1 --",'password'=>'wrong'])['status']===401);
    check('ログインCSRF欠落拒否',(new Browser())->request('/entry/login.php',['email'=>'u3@example.invalid','password'=>'lab-password-only'])['status']===400);
    $anonymous = new Browser(); $anonymous->token();
    check('Cookie secure/HttpOnly/SameSite/path',preg_match('/PHPSESSID=.*;.*path=\/;.*secure;.*HttpOnly;.*SameSite=Lax/i',implode("\n",$anonymous->lastHeaders))===1);
    check('HTTPS匿名トークンで認証済みsessionを操作不可',$member->request('/manage/logout.php',['_csrf'=>$anonymous->token()])['status']===400);
    check('manage GET logoutは405',$member->request('/manage/logout.php')['status']===405);
    check('GET logoutでもログインを保持',$member->request('/entry/identity.php')['status']===200);
    check('旧ナビGET logoutは確認のみ',$member->request('/entry/logout.php')['status']===200 && $member->request('/manage/identity.php')['status']===200);
    $oldCookies = $member->cookies;
    $result = $member->request('/manage/logout.php',['_csrf'=>identity($member)['csrf']]);
    check('manage POST logout成功',$result['status']===303 && !isset($member->cookies['PHPSESSID']));
    check('manage logoutでentry失効',$member->request('/entry/identity.php')['status']===401);
    $replay = new Browser(); $replay->cookies=$oldCookies;
    check('破棄したsession IDの再送拒否',$replay->request('/manage/identity.php')['status']===401);
    $member->login(3); $oldCookies=$member->cookies;
    $member->request('/entry/logout.php',['_csrf'=>$member->token('/entry/logout.php')]);
    check('entry POST logoutでmanage失効',$member->request('/manage/identity.php')['status']===401);
    $replay->cookies=$oldCookies;
    check('entry logout後も旧session再送拒否',$replay->request('/entry/identity.php')['status']===401);
    $member->login(3); $second = new Browser(); $second->login(3);
    $db->exec('UPDATE portal_account_controls SET auth_version=auth_version+1 WHERE user_id=3');
    check('認証版更新でentry失効',$member->request('/entry/identity.php')['status']===401);
    check('認証版更新で別ブラウザmanage失効',$second->request('/manage/identity.php')['status']===401);
    $member->login(3);$db->exec("UPDATE users SET email_verified=0 WHERE id=3");
    check('認証済み状態取消を再確認',$member->request('/manage/identity.php')['status']===401);
    $db->exec('UPDATE users SET email_verified=1 WHERE id=3');
    $member->login(3);$db->exec("UPDATE portal_account_controls SET status='blocked' WHERE user_id=3");
    check('停止はentryにも即時反映',$member->request('/entry/identity.php')['status']===401);
    $db->exec("UPDATE portal_account_controls SET status='active' WHERE user_id=3");
    $member->login(3);
    foreach (['//evil.invalid','https://evil.invalid', '../manage/', '%2f%2fevil.invalid', "entrant/events.php\r\nLocation: https://evil.invalid", 'entrant/../admin/events.php', 'entrant/events.php?redirect=evil'] as $bad) {
        check('危険nextを拒否 '.count($results),$member->request('/entry/login.php?next='.rawurlencode($bad))['status']===400);
    }
    check('不明return key拒否',$member->request('/entry/login.php?app=manage&return_key=unknown')['status']===400);
    check('app配列拒否',$member->request('/entry/login.php?app%5B%5D=manage')['status']===400);
    check('既存競技会からの申込復帰互換',$member->request('/entry/login.php?next='.rawurlencode('entrant/entries.php?action=create&event_id=10'))['location']==='/entry/entrant/entries.php?action=create&event_id=10');
    $otherSession = new Browser(); $otherSession->login(3);
    $change = $db->prepare('UPDATE users SET password_hash=? WHERE id=3');
    $change->execute([password_hash('lab-password-only',PASSWORD_DEFAULT)]);
    check('既存方式のパスワード更新でentry失効',$member->request('/entry/identity.php')['status']===401);
    check('既存方式のパスワード更新で別manage失効',$otherSession->request('/manage/identity.php')['status']===401);
    $member->login(3);
    // 旧セッション/改竄互換キーを直接作るのは検証CLIだけ。Web経路には公開しない。
    $sid = 'laboldsession12345678901234567890';
    file_put_contents('/srv/sessions/sess_'.$sid,'uid|i:3;rid|i:1;verified|b:1;');
    $old = new Browser();$old->cookies=['PHPSESSID'=>$sid];
    check('版なし旧sessionは再ログイン',$old->request('/entry/identity.php')['status']===401);
    $sid = $member->cookies['PHPSESSID'];
    $path = '/srv/sessions/sess_'.$sid;
    file_put_contents($path,str_replace('rid|i:3;','rid|i:1;',file_get_contents($path)));
    check('旧ridキャッシュで昇格不可',identity($member,'entry')['rid']===3);
    $db->exec('RENAME TABLE portal_user_role_grants TO lab_unavailable_grants');
    check('権限DB障害は503',$member->request('/manage/permission.php?club_id=1')['status']===503);
    $db->exec('RENAME TABLE lab_unavailable_grants TO portal_user_role_grants');
    $db->exec('RENAME TABLE portal_account_controls TO lab_unavailable_controls');
    check('認証DB障害でentryは503',$member->request('/entry/identity.php')['status']===503);
    check('認証DB障害でmanageは503',$member->request('/manage/identity.php')['status']===503);
    $db->exec('RENAME TABLE lab_unavailable_controls TO portal_account_controls');
    check('DB復旧後既存identityを確認',$member->request('/manage/identity.php')['status']===200);
    // 監査故障でも認証の成立やログアウト失効を曖昧にしない。
    $db->exec('RENAME TABLE portal_audit_events TO lab_unavailable_audit');
    $auditLogin = new Browser();
    check('監査書込失敗で新ログインは503',$auditLogin->login(3)['status']===503);
    check('監査書込失敗で認証は成立しない',$auditLogin->request('/manage/identity.php')['status']===401);
    $token = identity($member)['csrf'];
    check('監査故障時ログアウト結果は503',$member->request('/manage/logout.php',['_csrf'=>$token])['status']===503);
    check('監査故障時もログアウトは失効',$member->request('/entry/identity.php')['status']===401);
    $db->exec('RENAME TABLE lab_unavailable_audit TO portal_audit_events');
    $logs=$db->query('SELECT action,changes_redacted FROM portal_audit_events')->fetchAll(PDO::FETCH_ASSOC);
    check('ログイン/拒否/ログアウトの監査',count(array_unique(array_column($logs,'action')))===3);
    $serialized=json_encode($logs);
    check('監査に資格情報を含めない',!str_contains($serialized,'lab-password-only') && !str_contains($serialized,'example.invalid') && !str_contains($serialized,'PHPSESSID'));
    require '/srv/tests/membership_http.php';
    require '/srv/tests/billing_http.php';
    require '/srv/tests/club_http.php';
    require '/srv/tests/card_http.php';
    require '/srv/tests/license_http.php';
    require '/srv/tests/insurance_http.php';
    require '/srv/tests/bank_http.php';
    require '/srv/tests/bank_master_http.php';
    require '/srv/tests/profile_http.php';
    require '/srv/tests/registration_http.php';
    require '/srv/tests/dashboard_http.php';
    require '/srv/tests/member_layout_http.php';
    require '/srv/tests/review_http.php';
    require '/srv/tests/information_http.php';
    require '/srv/tests/answer_rules.php';
    $report=['status'=>'PASS','php'=>PHP_VERSION,'sapi_under_test'=>'fpm-fcgi over HTTPS',
        'database'=>$db->query('SELECT VERSION()')->fetchColumn(),'cases'=>$results,'card_qr_fixtures'=>$cardQrFixtures,
        'limits'=>['認証版/パスワード更新はDB操作で模擬。メールを伴うreset/registerの全フローは別工程',
            '競技会GETは既存実コード。POSTは会費追加拒否のみ検証、通常申込完走/メール/帳票は別工程','ブラウザーJS/画面外観は未検証']];
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");
    exit(1);
}
