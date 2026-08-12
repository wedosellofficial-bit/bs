<?php

declare(strict_types=1);

namespace App;

use App\Lib\Config;
use App\Lib\Logger;
use App\Lib\RateLimiter;
use App\Lib\Request;
use App\Lib\Totp;
use RuntimeException;
use SensitiveParameter;

/**
 * Sessions, credentials, CSRF, and two-factor authentication.
 *
 * Session design notes
 * --------------------
 * Cookies are httponly + secure + SameSite=Lax. Lax rather than Strict so
 * that following a link from a verification email lands the user logged
 * in; the CSRF token is what protects state-changing requests, not the
 * cookie policy.
 *
 * Two timeouts are enforced, because they answer different questions:
 * `idle` bounds an unattended browser, `absolute` bounds a stolen session
 * cookie regardless of how actively it is used.
 *
 * The session id is regenerated on login and on privilege change, which
 * is what defeats session fixation - an attacker who plants a known
 * session id before login finds it invalid afterwards.
 */
final class Auth
{
    private const CSRF_KEY = '_csrf';
    private const PENDING_2FA_KEY = '_pending_2fa_user';

    private static ?array $cachedUser = null;

    //-----------------------------------------------------------------
    // Session lifecycle
    //-----------------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
            || strtolower(Request::header('X-Forwarded-Proto')) === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            // In development over plain http the secure flag would stop the
            // cookie being set at all, making the app unusable locally. In
            // production it is always on.
            'secure'   => $isHttps || !Config::bool('app.debug'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name(Config::string('session.name', 'bsid'));

        // Only accept session ids this server issued. Without this, a
        // crafted cookie can make PHP create a session with an
        // attacker-chosen id.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        // session.sid_length / sid_bits_per_character are deliberately not
        // set: both are deprecated as of PHP 8.4, and PHP's own default
        // (32 characters over a 4-bit alphabet = 128 bits of entropy) is
        // already past the point where guessing is the weak link.

        session_start();

        self::enforceTimeouts();
    }

    /**
     * Drop sessions that have gone stale, and bind the session to the
     * client it was issued to.
     */
    private static function enforceTimeouts(): void
    {
        $now = time();

        $createdAt = (int) ($_SESSION['_created_at'] ?? 0);
        $seenAt = (int) ($_SESSION['_last_seen'] ?? 0);

        if ($createdAt === 0) {
            $_SESSION['_created_at'] = $now;
            $_SESSION['_last_seen'] = $now;
            $_SESSION['_ua_hash'] = self::clientFingerprint();

            return;
        }

        $absolute = Config::int('session.absolute_timeout', 28800);
        $idle = Config::int('session.idle_timeout', 7200);

        $expired = ($now - $createdAt) > $absolute
            || ($seenAt > 0 && ($now - $seenAt) > $idle);

        // A session whose user-agent changed mid-flight is either a stolen
        // cookie or a browser update. Both are cheap to recover from with a
        // fresh login, and only one of them is safe to ignore.
        $moved = isset($_SESSION['_ua_hash'])
            && !hash_equals((string) $_SESSION['_ua_hash'], self::clientFingerprint());

        if ($expired || $moved) {
            self::destroySession();
            self::startFreshSession();

            return;
        }

        $_SESSION['_last_seen'] = $now;
    }

    private static function startFreshSession(): void
    {
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_seen'] = time();
        $_SESSION['_ua_hash'] = self::clientFingerprint();
    }

    /**
     * A weak binding, deliberately. The IP is excluded: mobile networks
     * change it constantly and binding to it logs real users out all day,
     * which trains them to expect random logouts.
     */
    private static function clientFingerprint(): string
    {
        return hash('sha256', Request::userAgent());
    }

    public static function destroySession(): void
    {
        self::$cachedUser = null;
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_start();
        }
    }

    //-----------------------------------------------------------------
    // CSRF
    //-----------------------------------------------------------------

    /** The per-session token. Generated once, reused for the session. */
    public static function csrfToken(): string
    {
        self::startSession();

        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    /** Hidden input for forms. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_KEY . '" value="'
            . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Verify a submitted token.
     *
     * hash_equals, not ===: a token compared with a short-circuiting
     * operator leaks its prefix through timing. AJAX requests may send it
     * in a header instead of the body.
     */
    public static function verifyCsrf(?string $submitted = null): bool
    {
        self::startSession();

        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $token = $submitted
            ?? (isset($_POST[self::CSRF_KEY]) && is_string($_POST[self::CSRF_KEY]) ? $_POST[self::CSRF_KEY] : '')
            ?: Request::header('X-CSRF-Token');

        return is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Verify or stop the request. Called by the router for every
     * state-changing method, so an individual controller cannot forget.
     */
    public static function requireCsrf(): void
    {
        if (self::verifyCsrf()) {
            return;
        }

        Logger::audit('csrf.failed', 'CSRF token missing or invalid', self::userId(), null, null, [
            'path'   => Request::path(),
            'method' => Request::method(),
        ]);

        http_response_code(419);

        if (Request::isAjax()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
            exit;
        }

        $view = APP_PATH . '/views/errors/419.php';
        if (is_file($view)) {
            require $view;
        } else {
            echo 'Your session expired. Go back, reload, and try again.';
        }

        exit;
    }

    //-----------------------------------------------------------------
    // Registration and login
    //-----------------------------------------------------------------

    /**
     * Hash a password with Argon2id.
     *
     * Explicit cost parameters rather than PASSWORD_DEFAULT's: shared
     * hosting has tight memory limits, and 64 MiB per hash is a real
     * constraint when several logins land at once. 64 MiB / 4 passes is
     * still well above the OWASP floor.
     */
    public static function hashPassword(#[SensitiveParameter] string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);

        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    /**
     * Validate a password against the store's policy.
     *
     * Length over composition rules. Mandatory symbol classes push people
     * towards Password1! and no further; a 12-character minimum with a
     * check against the obvious choices does more.
     *
     * @return list<string> Problems, empty when acceptable.
     */
    public static function passwordProblems(#[SensitiveParameter] string $password, string $email = ''): array
    {
        $problems = [];
        $length = mb_strlen($password);

        if ($length < 12) {
            $problems[] = 'Use at least 12 characters.';
        }
        if ($length > 200) {
            $problems[] = 'Keep it under 200 characters.';
        }

        $lower = mb_strtolower($password);

        $obvious = ['password', 'qwerty', '12345678', 'letmein', 'bitcoin', 'ordinals', 'billions'];
        foreach ($obvious as $needle) {
            if (str_contains($lower, $needle)) {
                $problems[] = 'That contains a very common word or sequence. Pick something less guessable.';
                break;
            }
        }

        if ($email !== '') {
            $localPart = mb_strtolower(explode('@', $email)[0]);
            if ($localPart !== '' && mb_strlen($localPart) > 2 && str_contains($lower, $localPart)) {
                $problems[] = 'Do not use your email address in your password.';
            }
        }

        // A single repeated character passes a length check but is not a
        // password.
        if ($length > 0 && count(array_unique(mb_str_split($password))) < 5) {
            $problems[] = 'Use a wider mix of characters.';
        }

        return $problems;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 190;
    }

    /**
     * Create an account. Returns the new user id.
     *
     * The caller is responsible for rate limiting and for sending the
     * verification email.
     */
    public static function register(string $email, #[SensitiveParameter] string $password, ?string $displayName = null): int
    {
        $email = self::normalizeEmail($email);

        Database::run(
            'INSERT INTO users (email, password_hash, display_name, role, status, account_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$email, self::hashPassword($password), $displayName, 'user', 'active', \App\AccountActivation::STATUS_PENDING]
        );

        $userId = Database::lastInsertId();

        Logger::audit('user.registered', 'Account created', $userId, 'user', $userId);

        return $userId;
    }

    public static function emailExists(string $email): bool
    {
        return Database::first('SELECT id FROM users WHERE email = ?', [self::normalizeEmail($email)]) !== null;
    }

    /**
     * Verify credentials.
     *
     * Returns the user row on success, null on failure - without saying
     * which half was wrong, and after spending the same work either way.
     *
     * @return array<string,mixed>|null
     */
    public static function attemptLogin(string $email, #[SensitiveParameter] string $password): ?array
    {
        $email = self::normalizeEmail($email);
        $user = Database::first('SELECT * FROM users WHERE email = ?', [$email]);

        if ($user === null) {
            // Hash anyway. Skipping it makes "no such account" measurably
            // faster than "wrong password", which turns the login form into
            // an account enumeration oracle.
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$RFVNTVlEVU1NWURVTU1Z$0000000000000000000000000000000000000000000');

            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        // Transparently upgrade a hash whose parameters have since changed.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ])) {
            Database::run(
                'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [self::hashPassword($password), (int) $user['id']]
            );
        }

        return $user;
    }

    /**
     * Establish an authenticated session.
     *
     * @param array<string,mixed> $user
     */
    public static function completeLogin(array $user): void
    {
        self::startSession();

        // Session fixation defence: the id the client arrived with is
        // discarded and a new one issued.
        session_regenerate_id(true);
        self::startFreshSession();

        unset($_SESSION[self::PENDING_2FA_KEY]);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = (string) $user['role'];

        // A fresh CSRF token for the new privilege level.
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));

        self::$cachedUser = null;

        Database::run(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?',
            [@inet_pton(Request::clientIp()) ?: null, (int) $user['id']]
        );

        Logger::audit('auth.login', 'Signed in', (int) $user['id'], 'user', (int) $user['id']);
    }

    public static function logout(): void
    {
        $userId = self::userId();
        self::destroySession();

        if ($userId !== null) {
            Logger::audit('auth.logout', 'Signed out', $userId, 'user', $userId);
        }
    }

    //-----------------------------------------------------------------
    // Two-factor
    //-----------------------------------------------------------------

    /** Park a half-authenticated login until the TOTP code is supplied. */
    public static function beginTwoFactor(int $userId): void
    {
        self::startSession();
        session_regenerate_id(true);

        // Only the pending id is stored. `user_id` is deliberately NOT set,
        // so a half-finished login has no authenticated access to anything.
        $_SESSION[self::PENDING_2FA_KEY] = ['user_id' => $userId, 'at' => time()];
    }

    public static function pendingTwoFactorUserId(): ?int
    {
        self::startSession();

        $pending = $_SESSION[self::PENDING_2FA_KEY] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'], $pending['at'])) {
            return null;
        }

        // A 2FA prompt left open for ten minutes has to be restarted.
        if (time() - (int) $pending['at'] > 600) {
            unset($_SESSION[self::PENDING_2FA_KEY]);

            return null;
        }

        return (int) $pending['user_id'];
    }

    public static function hasTwoFactor(array $user): bool
    {
        return !empty($user['twofa_secret']) && !empty($user['twofa_confirmed_at']);
    }

    /**
     * Encrypt a TOTP secret for storage.
     *
     * XChaCha20-Poly1305 via libsodium, with a key derived from APP_KEY.
     * A database dump then yields password hashes (expensive to attack)
     * but not working second factors (free to use).
     */
    public static function encryptSecret(#[SensitiveParameter] string $plaintext): string
    {
        $key = self::secretKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    public static function decryptSecret(string $ciphertext): ?string
    {
        $key = self::secretKey();

        if (strlen($ciphertext) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $body = substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($body, $nonce, $key);

        return $plain === false ? null : $plain;
    }

    private static function secretKey(): string
    {
        $appKey = Config::string('app.key');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not set; two-factor secrets cannot be encrypted.');
        }

        // Domain-separated from any other use of APP_KEY.
        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'totp-secret-v1');
    }

    /** Verify a TOTP code for a user, or one of their recovery codes. */
    public static function verifyTwoFactor(array $user, string $code): bool
    {
        $secret = is_string($user['twofa_secret'] ?? null)
            ? self::decryptSecret($user['twofa_secret'])
            : null;

        if ($secret !== null && Totp::verify($secret, $code)) {
            return true;
        }

        return self::consumeRecoveryCode((int) $user['id'], $code);
    }

    /**
     * Generate recovery codes. Returned in clear once, stored hashed.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $userId, int $count = 8): array
    {
        $codes = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            // 10 chars from an unambiguous alphabet - no O/0 or I/1, since
            // these get written down and read back.
            $code = '';
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $formatted = substr($code, 0, 5) . '-' . substr($code, 5);
            $codes[] = $formatted;
            $hashes[] = hash('sha256', $code);
        }

        Database::run(
            'UPDATE users SET twofa_recovery = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [implode("\n", $hashes), $userId]
        );

        return $codes;
    }

    private static function consumeRecoveryCode(int $userId, string $submitted): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $submitted) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }

        $user = Database::first('SELECT twofa_recovery FROM users WHERE id = ?', [$userId]);
        $stored = is_string($user['twofa_recovery'] ?? null) ? (string) $user['twofa_recovery'] : '';

        if ($stored === '') {
            return false;
        }

        $candidate = hash('sha256', $normalized);
        $remaining = [];
        $matched = false;

        foreach (explode("\n", $stored) as $hash) {
            $hash = trim($hash);
            if ($hash === '') {
                continue;
            }

            // Single-use: a matching code is dropped rather than kept.
            if (!$matched && hash_equals($hash, $candidate)) {
                $matched = true;
                continue;
            }

            $remaining[] = $hash;
        }

        if ($matched) {
            Database::run(
                'UPDATE users SET twofa_recovery = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [implode("\n", $remaining), $userId]
            );

            Logger::audit('auth.recovery_code_used', 'Recovery code consumed', $userId, 'user', $userId, [
                'codes_remaining' => count($remaining),
            ]);
        }

        return $matched;
    }

    //-----------------------------------------------------------------
    // Current user and guards
    //-----------------------------------------------------------------

    public static function userId(): ?int
    {
        self::startSession();
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $id = self::userId();
        if ($id === null) {
            return null;
        }

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$id]);

        // An account suspended mid-session loses it on the next request,
        // rather than at next login.
        if ($user === null || $user['status'] !== 'active') {
            self::destroySession();

            return null;
        }

        self::$cachedUser = $user;

        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && $user['role'] === 'admin';
    }

    public static function isVerified(): bool
    {
        $user = self::user();

        return $user !== null && $user['email_verified_at'] !== null;
    }

    /** Redirect to login unless signed in. */
    public static function requireLogin(): array
    {
        $user = self::user();

        if ($user === null) {
            $next = rawurlencode(Request::path() . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
            Lib\Response::redirect('/login?next=' . $next);
        }

        return $user;
    }

    /**
     * Admin guard.
     *
     * Called at the top of every admin route, not just where the menu is
     * rendered. Hiding a link is not access control - the URL is still
     * there, and it is the first thing anyone tries.
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();

        if ($user['role'] !== 'admin') {
            Logger::audit('admin.denied', 'Non-admin attempted an admin route', (int) $user['id'], null, null, [
                'path' => Request::path(),
            ]);

            http_response_code(404);

            // 404, not 403: an admin area that announces itself to
            // unauthorised users is an invitation to probe it.
            $view = APP_PATH . '/views/errors/404.php';
            if (is_file($view)) {
                require $view;
            }

            exit;
        }

        return $user;
    }

    /** Verified-email guard, for anything that moves money. */
    public static function requireVerified(): array
    {
        $user = self::requireLogin();

        if ($user['email_verified_at'] === null) {
            Lib\Response::redirect('/verify-email?required=1');
        }

        return $user;
    }


    //-----------------------------------------------------------------
    // Flash messages
    //-----------------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        self::startSession();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlashes(): array
    {
        self::startSession();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($flashes) ? $flashes : [];
    }

    //-----------------------------------------------------------------
    // Single-use tokens (email verification, password reset)
    //-----------------------------------------------------------------

    /**
     * Issue a token. The plaintext is returned for the email; only its
     * hash is stored, so a database leak cannot be replayed into a
     * password reset.
     */
    public static function issueToken(int $userId, string $type, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));

        // Any outstanding token of the same type is invalidated, so a
        // second "reset my password" email retires the first link.
        Database::run(
            'UPDATE user_tokens SET used_at = UTC_TIMESTAMP()
              WHERE user_id = ? AND type = ? AND used_at IS NULL',
            [$userId, $type]
        );

        Database::run(
            'INSERT INTO user_tokens (user_id, type, token_hash, expires_at, created_at, created_ip)
             VALUES (?, ?, ?, (UTC_TIMESTAMP() + INTERVAL ? SECOND), UTC_TIMESTAMP(), ?)',
            [$userId, $type, hash('sha256', $token), $ttlSeconds, @inet_pton(Request::clientIp()) ?: null]
        );

        return $token;
    }

    /**
     * Redeem a token, marking it used. Returns the user id or null.
     *
     * The lookup is by hash, which is unique and indexed, so this is a
     * constant-shape query regardless of the submitted value.
     */
    public static function consumeToken(string $token, string $type): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return Database::transaction(static function () use ($token, $type): ?int {
            $row = Database::first(
                'SELECT id, user_id FROM user_tokens
                  WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                  FOR UPDATE',
                [hash('sha256', $token), $type]
            );

            if ($row === null) {
                return null;
            }

            Database::run('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['id']]);

            return (int) $row['user_id'];
        });
    }

    /** Record a failed sign-in. Requirement: every failed login is logged. */
    public static function logFailedLogin(string $email, string $reason): void
    {
        RateLimiter::attempt('login_ip', Request::clientIp());

        Logger::audit('auth.login_failed', 'Sign-in attempt failed: ' . $reason, null, null, null, [
            // The email is hashed rather than stored: the audit table
            // should not become a list of addresses someone tried.
            'email_hash' => substr(hash('sha256', self::normalizeEmail($email)), 0, 16),
            'reason'     => $reason,
        ]);
    }
}
