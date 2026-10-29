<?php
// Path: src/Auth/SharedAuth.php
// 機能: usersとPHPセッションをentry/manageで共有し、DBの状態・権限を毎要求で確認する。
declare(strict_types=1);

namespace Jmrc\Auth;

use PDO;

final class SharedAuth
{
    private const PERMISSIONS = [
        'club.members.read', 'club.affiliations.approve', 'billing.confirm',
        'billing.correct', 'portal.content.write', 'accounts.manage', 'temporary.approve',
        'club.registration.write', 'club.registration.approve',
        'identifiers.migrate',
        'insurance.export',
        'billing.accounts.manage',
    ];
    private const ROLE_PATHS = [1 => '/entry/admin/events.php', 2 => '/entry/club/events.php', 3 => '/entry/entrant/events.php'];
    private const RETURNS = ['dashboard' => '/manage/'];

    public function __construct(private readonly PDO $db, array $session)
    {
        // 異なるbootstrapが開始済みなら混在させず停止する。保存先作成/権限変更は運用側で行う。
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new \LogicException('Shared session must start before output and legacy bootstrap');
        }
        $path = $session['save_path'] ?? '';
        if (!is_string($path) || !is_dir($path) || !is_writable($path) || !is_readable($path)) {
            throw new \RuntimeException('Shared session directory unavailable');
        }
        $lifetime = $session['lifetime'] ?? 2592000;
        if (!is_int($lifetime) || $lifetime < 1 || !is_bool($session['secure'] ?? null)) {
            throw new \InvalidArgumentException('Explicit session lifetime/secure setting required');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.save_handler', 'files');
        ini_set('session.serialize_handler', 'php');
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        session_name($session['name'] ?? 'PHPSESSID');
        session_save_path($path);
        session_set_cookie_params([
            'lifetime' => $lifetime, 'path' => '/', 'secure' => $session['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Shared session unavailable');
        }
        header('Cache-Control: no-store');
        // 旧uidだけのセッションもここで検証し、portal側だけ失効する抜けを作らない。
        $this->identity();
    }

    /** パスワードと有効アカウントを確認し、旧互換キーを保持してセッションを新しくする。 */
    public function login(string $email, string $password): bool
    {
        $statement = $this->db->prepare('SELECT u.id,u.role_id,u.email_verified,u.password_hash,c.status,c.auth_version
            FROM users u LEFT JOIN portal_account_controls c ON c.user_id=u.id WHERE u.email=?');
        $statement->execute([$email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        // 未登録メールでもハッシュ照合を実行する。これは公開のダミーハッシュで実資格情報ではない。
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $valid = password_verify($password, $hash);
        if (!$valid || !$user || $user['status'] !== 'active' || (int)$user['email_verified'] !== 1
            || !isset(self::ROLE_PATHS[(int)$user['role_id']])) {
            $this->audit('AUTH_LOGIN_DENIED', $user ? (int)$user['id'] : null);
            $this->clear();
            return false;
        }
        $this->audit('AUTH_LOGIN', (int)$user['id']);
        $this->clear();
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['rid'] = (int)$user['role_id'];
        $_SESSION['verified'] = true;
        $_SESSION['portal'] = [
            'auth_version' => (int)$user['auth_version'],
            // 既存reset_handlerが直接ハッシュを更新しても次要求で旧セッションを失効させる。
            'credential_stamp' => hash('sha256', $user['password_hash']),
        ];
        $this->csrfToken();
        return true;
    }

    /** セッションのrole/verifiedを信用せず、現在のDB行・認証版・メール状態を確認する。 */
    public function identity(): ?array
    {
        if (!isset($_SESSION['uid'])) {
            return null;
        }
        if (!is_int($_SESSION['uid']) || $_SESSION['uid'] < 1) {
            $this->clear();
            return null;
        }
        $statement = $this->db->prepare('SELECT u.id,u.email,u.role_id,u.email_verified,u.password_hash,u.pending_email,u.created_at,u.updated_at,c.status,c.auth_version
            FROM users u LEFT JOIN portal_account_controls c ON c.user_id=u.id WHERE u.id=?');
        $statement->execute([$_SESSION['uid']]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        $version = $_SESSION['portal']['auth_version'] ?? null;
        $stamp = $_SESSION['portal']['credential_stamp'] ?? null;
        if (!$user || $user['status'] !== 'active' || (int)$user['email_verified'] !== 1
            || !is_int($version) || $version !== (int)$user['auth_version']
            || !is_string($stamp) || !hash_equals(hash('sha256', $user['password_hash']), $stamp)
            || !isset(self::ROLE_PATHS[(int)$user['role_id']])) {
            $this->clear();
            return null;
        }
        $_SESSION['rid'] = (int)$user['role_id'];
        $_SESSION['verified'] = true;
        return ['id' => (int)$user['id'], 'email' => $user['email'], 'role_id' => (int)$user['role_id'],
            'email_verified' => 1, 'pending_email' => $user['pending_email'],
            'created_at' => $user['created_at'], 'updated_at' => $user['updated_at']];
    }

    /** 保護経路の本人確認。HTML誘導は呼出側、JSON経路は401として処理する。 */
    public function requireIdentity(): array
    {
        return $this->identity() ?? throw new HttpFailure(401, 'Authentication required');
    }

    /** portalの明示権限だけを照会する。旧JMRC_ADMINや自己申告のclub_idから昇格しない。 */
    public function can(string $permission, ?int $clubId = null): bool
    {
        $user = $this->identity();
        if (!$user || !in_array($permission, self::PERMISSIONS, true) || ($clubId !== null && $clubId < 1)) {
            return false;
        }
        $statement = $this->db->prepare("SELECT 1 FROM portal_user_role_grants g
            JOIN portal_roles r ON r.id=g.role_id
            JOIN portal_role_permissions p ON p.role_id=g.role_id
            WHERE g.user_id=? AND p.permission_code=? AND g.status='active'
              AND g.approved_by IS NOT NULL AND g.revoked_at IS NULL
              AND g.valid_from<=UTC_TIMESTAMP(6)
              AND (g.valid_until IS NULL OR UTC_TIMESTAMP(6)<g.valid_until)
              AND ((g.scope_type='global' AND g.scope_club_id IS NULL AND r.code<>'CLUB_REP' AND p.permission_code<>'temporary.approve')
                OR (g.scope_type='club' AND g.scope_club_id=?)) LIMIT 1");
        $statement->execute([$user['id'], $permission, $clubId]);
        return $statement->fetchColumn() !== false;
    }

    /** 全ダウンロード/書込経路もこのチェックを使用し、対象行の所属は業務サービスで再確認する。 */
    public function requirePermission(string $permission, ?int $clubId = null): void
    {
        $this->requireIdentity();
        if (!$this->can($permission, $clubId)) {
            throw new HttpFailure(403, 'Forbidden');
        }
    }

    /** 匿名フォームを含めセッション固有トークンを生成し、ログイン時に必ず更新する。 */
    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf']) || strlen($_SESSION['_csrf']) !== 64) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** 欠落・配列・空文字・異なるセッションのPOSTを拒否する。 */
    public function verifyCsrf(mixed $token): void
    {
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($token) || !is_string($expected) || $token === '' || $expected === '' || !hash_equals($expected, $token)) {
            throw new HttpFailure(400, 'Invalid CSRF token');
        }
    }

    /** 副作用はPOST+CSRFに限定。監査書込に失敗してもログアウトの失効処理は行う。 */
    public function logout(string $method, mixed $token): void
    {
        if ($method !== 'POST') {
            throw new HttpFailure(405, 'POST required');
        }
        $this->verifyCsrf($token);
        try {
            $user = $this->identity();
            if ($user) {
                $this->audit('AUTH_LOGOUT', $user['id']);
            }
        } finally {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => '/', 'secure' => $params['secure'],
                'httponly' => true, 'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    /** app/keyはサーバー内の固定対応表のみ。entryのnextも外部/相対逸脱を拒否する。 */
    public function afterLogin(mixed $app, mixed $key, mixed $next): string
    {
        $user = $this->requireIdentity();
        if ($app !== null || $key !== null) {
            if ($app !== 'manage' || !is_string($key) || !isset(self::RETURNS[$key])) {
                throw new HttpFailure(400, 'Invalid return destination');
            }
            return self::RETURNS[$key];
        }
        // 従来のnext優先はENTRANTのみを維持。パスを固定のentryディレクトリへ閉じる。
        if ($next !== null && $next !== '') {
            if (!is_string($next) || (!preg_match('~^/?(?:entrant|club|admin)/[a-zA-Z0-9_-]+\.php$~D', $next)
                && !preg_match('~^entrant/entries\.php\?action=create&event_id=[1-9][0-9]*$~D', $next))) {
                throw new HttpFailure(400, 'Invalid return destination');
            }
            if ($user['role_id'] === 3 && str_starts_with(ltrim($next, '/'), 'entrant/')) {
                return '/entry/' . ltrim($next, '/');
            }
        }
        return self::ROLE_PATHS[$user['role_id']];
    }

    /** 旧セッションIDを破棄し、認証/選択scope/CSRFをまとめて消す。 */
    private function clear(): void
    {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Session renewal failed');
        }
    }

    /** 監査にはパスワード・メール・セッションID・個人番号を記録しない。 */
    private function audit(string $action, ?int $userId): void
    {
        $statement = $this->db->prepare("INSERT INTO portal_audit_events
            (actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at)
            VALUES (?,?,'account',?,?, '{}',UTC_TIMESTAMP(6))");
        $statement->execute([$userId, $action, $userId ?? 0, bin2hex(random_bytes(16))]);
    }
}
