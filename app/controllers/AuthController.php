<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Lib\Config;
use App\Lib\Logger;
use App\Lib\Mailer;
use App\Lib\RateLimiter;
use App\Lib\Request;
use App\Lib\Totp;
use Throwable;

/**
 * Registration, sign-in, verification, password reset, and the second
 * factor prompt.
 *
 * A recurring theme: none of these responses reveal whether an email
 * address has an account. "Wrong password" and "no such user" produce the
 * same message, and "we sent a reset link" is shown whether or not one
 * was actually sent. Otherwise the forms become a membership oracle,
 * which for a store holding customer balances is worth avoiding.
 */
final class AuthController extends Controller
{
    private const VERIFY_TTL = 86400;   // 24 hours
    private const RESET_TTL = 3600;     // 60 minutes

    //-----------------------------------------------------------------
    // Registration
    //-----------------------------------------------------------------

    public function showRegister(): void
    {
        if (Auth::check()) {
            $this->redirect('/account');
        }

        $this->view('auth/register', ['title' => 'Create an account'], 'layout/auth');
    }

    public function register(): void
    {
        if (Auth::check()) {
            $this->redirect('/account');
        }

        $email = Auth::normalizeEmail(Request::post('email'));
        $password = (string) ($_POST['password'] ?? '');
        $displayName = Request::post('display_name');

        if (!RateLimiter::attempt('register', Request::clientIp())) {
            $this->back('/register', 'error', RateLimiter::waitMessage('register', Request::clientIp()));
        }

        $errors = [];

        if (!Auth::isValidEmail($email)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!Request::postBool('accept_terms')) {
            $errors[] = 'You need to accept the terms of sale to open an account.';
        }

        foreach (Auth::passwordProblems($password, $email) as $problem) {
            $errors[] = $problem;
        }

        if ($displayName !== '' && mb_strlen($displayName) > 60) {
            $errors[] = 'Display name must be 60 characters or fewer.';
        }

        if ($errors !== []) {
            Auth::flash('error', implode(' ', $errors));
            $this->view('auth/register', [
                'title'   => 'Create an account',
                'old'     => ['email' => $email, 'display_name' => $displayName],
            ], 'layout/auth');

            return;
        }

        // Registering an address that already exists returns the same
        // response as a successful signup. Telling the visitor "that email
        // is taken" confirms the account exists to anyone who asks.
        if (Auth::emailExists($email)) {
            Logger::write('auth.register_existing', 'Registration attempted for an existing address');

            try {
                $existing = Database::first('SELECT id, email_verified_at FROM users WHERE email = ?', [$email]);
                if ($existing !== null && $existing['email_verified_at'] === null) {
                    Mailer::sendVerification($email, Auth::issueToken((int) $existing['id'], 'email_verify', self::VERIFY_TTL));
                }
            } catch (Throwable $e) {
                Logger::write('auth.register_existing_mail_failed', $e->getMessage());
            }

            $this->redirect('/verify-email?sent=1');
        }

        $userId = Auth::register($email, $password, $displayName === '' ? null : $displayName);

        Mailer::sendVerification($email, Auth::issueToken($userId, 'email_verify', self::VERIFY_TTL));

        $this->redirect('/verify-email?sent=1');
    }

    //-----------------------------------------------------------------
    // Sign in
    //-----------------------------------------------------------------

    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/account');
        }

        $this->view('auth/login', [
            'title' => 'Sign in',
            'next'  => Request::query('next'),
        ], 'layout/auth');
    }

    public function login(): void
    {
        $email = Auth::normalizeEmail(Request::post('email'));
        $password = (string) ($_POST['password'] ?? '');
        $next = Request::safeRedirect(Request::post('next'), '/account');

        $ip = Request::clientIp();

        // Requirement: 5 attempts per 15 minutes per IP+email. The wider
        // per-IP bucket is checked too, so one source cannot spray many
        // accounts at 5 attempts each.
        $subject = $ip . '|' . $email;

        if (RateLimiter::isBlocked('login', $subject) || RateLimiter::isBlocked('login_ip', $ip)) {
            Auth::logFailedLogin($email, 'rate limited');
            $this->back('/login', 'error', RateLimiter::waitMessage('login', $subject));
        }

        RateLimiter::attempt('login', $subject);

        $user = Auth::attemptLogin($email, $password);

        if ($user === null) {
            Auth::logFailedLogin($email, 'bad credentials');
            $this->back('/login', 'error', 'That email and password do not match an account.');
        }

        if ($user['status'] !== 'active') {
            Auth::logFailedLogin($email, 'account ' . (string) $user['status']);
            $this->back('/login', 'error', 'That account is not available. Contact support if you think this is a mistake.');
        }

        RateLimiter::clear('login', $subject);

        if (Auth::hasTwoFactor($user)) {
            // Password verified, but no authenticated session yet - the
            // pending id is all that is stored until the code checks out.
            Auth::beginTwoFactor((int) $user['id']);
            $_SESSION['_2fa_next'] = $next;

            $this->redirect('/login/2fa');
        }

        Auth::completeLogin($user);
        $this->redirect($next);
    }

    public function logout(): void
    {
        Auth::logout();
        $this->back('/', 'success', 'Signed out.');
    }

    //-----------------------------------------------------------------
    // Second factor
    //-----------------------------------------------------------------

    public function showTwoFactor(): void
    {
        if (Auth::pendingTwoFactorUserId() === null) {
            $this->redirect('/login');
        }

        $this->view('auth/two-factor', ['title' => 'Two-step verification'], 'layout/auth');
    }

    public function verifyTwoFactor(): void
    {
        $userId = Auth::pendingTwoFactorUserId();

        if ($userId === null) {
            $this->back('/login', 'error', 'That took too long. Sign in again.');
        }

        $subject = Request::clientIp() . '|2fa|' . $userId;

        if (!RateLimiter::attempt('twofa', $subject)) {
            $this->back('/login/2fa', 'error', RateLimiter::waitMessage('twofa', $subject));
        }

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user === null || !Auth::verifyTwoFactor($user, Request::post('code'))) {
            Logger::audit('auth.2fa_failed', 'Two-factor code rejected', $userId, 'user', $userId);
            $this->back('/login/2fa', 'error', 'That code is not valid. Codes change every 30 seconds - try the current one.');
        }

        RateLimiter::clear('twofa', $subject);

        $next = Request::safeRedirect((string) ($_SESSION['_2fa_next'] ?? '/account'), '/account');
        unset($_SESSION['_2fa_next']);

        Auth::completeLogin($user);
        $this->redirect($next);
    }

    //-----------------------------------------------------------------
    // Email verification
    //-----------------------------------------------------------------

    public function verifyEmail(): void
    {
        $token = Request::query('token');

        if ($token === '') {
            $this->view('auth/verify-email', [
                'title'    => 'Confirm your email',
                'sent'     => Request::query('sent') === '1',
                'required' => Request::query('required') === '1',
            ], 'layout/auth');

            return;
        }

        $userId = Auth::consumeToken($token, 'email_verify');

        if ($userId === null) {
            $this->view('auth/verify-email', [
                'title'   => 'Confirm your email',
                'expired' => true,
            ], 'layout/auth');

            return;
        }

        Database::run(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [$userId]
        );

        Logger::audit('user.email_verified', 'Email address confirmed', $userId, 'user', $userId);

        $user = Database::first('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user !== null && $user['status'] === 'active' && !Auth::hasTwoFactor($user)) {
            // Verifying from the emailed link signs the user in. The token
            // was single-use and short-lived, and requiring a second
            // sign-in here only teaches people to ignore the email.
            Auth::completeLogin($user);
            $this->back('/account', 'success', 'Email confirmed. Welcome.');
        }

        $this->back('/login', 'success', 'Email confirmed. Sign in to continue.');
    }

    public function resendVerification(): void
    {
        $email = Auth::normalizeEmail(Request::post('email'));

        if (!RateLimiter::attempt('verify_resend', Request::clientIp() . '|' . $email)) {
            $this->back('/verify-email', 'error', RateLimiter::waitMessage('verify_resend', Request::clientIp() . '|' . $email));
        }

        $user = Database::first('SELECT id, email_verified_at FROM users WHERE email = ?', [$email]);

        if ($user !== null && $user['email_verified_at'] === null) {
            Mailer::sendVerification($email, Auth::issueToken((int) $user['id'], 'email_verify', self::VERIFY_TTL));
        }

        // Same message either way.
        $this->back('/verify-email?sent=1', 'success', 'If that address needs confirming, a new link is on its way.');
    }

    //-----------------------------------------------------------------
    // Password reset
    //-----------------------------------------------------------------

    public function showForgotPassword(): void
    {
        $this->view('auth/forgot-password', ['title' => 'Reset your password'], 'layout/auth');
    }

    public function sendPasswordReset(): void
    {
        $email = Auth::normalizeEmail(Request::post('email'));
        $subject = Request::clientIp() . '|' . $email;

        if (!RateLimiter::attempt('password_reset', $subject)) {
            $this->back('/forgot-password', 'error', RateLimiter::waitMessage('password_reset', $subject));
        }

        $user = Database::first('SELECT id FROM users WHERE email = ? AND status = ?', [$email, 'active']);

        if ($user !== null) {
            Mailer::sendPasswordReset($email, Auth::issueToken((int) $user['id'], 'password_reset', self::RESET_TTL));
            Logger::audit('auth.reset_requested', 'Password reset requested', (int) $user['id'], 'user', (int) $user['id']);
        }

        $this->back(
            '/forgot-password?sent=1',
            'success',
            'If there is an account for that address, a reset link is on its way. It expires in an hour.'
        );
    }

    public function showResetPassword(): void
    {
        $token = Request::query('token');

        $this->view('auth/reset-password', [
            'title' => 'Choose a new password',
            'token' => $token,
        ], 'layout/auth');
    }

    public function resetPassword(): void
    {
        $token = Request::post('token');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $confirm) {
            $this->back('/reset-password?token=' . rawurlencode($token), 'error', 'Those two passwords do not match.');
        }

        $problems = Auth::passwordProblems($password);
        if ($problems !== []) {
            $this->back('/reset-password?token=' . rawurlencode($token), 'error', implode(' ', $problems));
        }

        $userId = Auth::consumeToken($token, 'password_reset');

        if ($userId === null) {
            $this->back('/forgot-password', 'error', 'That reset link has expired or was already used. Request a new one.');
        }

        Database::run(
            'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [Auth::hashPassword($password), $userId]
        );

        // A password reset is the recovery path for a compromised account,
        // so it must not leave the attacker's existing session working.
        // Destroying this browser's session too is a small cost.
        Auth::destroySession();

        Logger::audit('auth.password_reset', 'Password changed via reset link', $userId, 'user', $userId);

        $this->back('/login', 'success', 'Password updated. Sign in with your new password.');
    }

    /** Exposed for the account settings screen's enrolment QR. */
    public static function provisioningUri(string $secret, string $email): string
    {
        return Totp::provisioningUri($secret, $email, Config::string('app.name', 'Billions Store'));
    }
}
