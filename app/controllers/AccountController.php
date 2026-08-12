<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AccountActivation;
use App\Auth;
use App\Database;
use App\Lib\Config;
use App\Lib\Logger;
use App\Lib\Request;
use App\Lib\Totp;
use App\Orders;
use App\Ordinals;
use App\ProductOrders;
use App\Wallet;

final class AccountController extends Controller
{
    public function dashboard(): void
    {
        $user = Auth::requireLogin();
        $userId = (int) $user['id'];

        $this->view('account/dashboard', [
            'title'      => 'Dashboard',
            'balance'    => Wallet::balance($userId),
            'orders'     => Orders::forUser($userId, 5),
            'owned'      => Orders::ownedByUser($userId),
            'productOrders' => ProductOrders::forUser($userId, 5),
            'statement'  => Wallet::statement($userId, 5),
            'isActive'   => AccountActivation::isActive($user),
            'minActivationMinor' => AccountActivation::minActivationMinor(),
            'announcements' => Database::all(
                'SELECT title, body, level, published_at FROM announcements
                  WHERE published_at IS NOT NULL AND published_at <= UTC_TIMESTAMP()
                    AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                  ORDER BY published_at DESC LIMIT 3'
            ),
        ]);
    }

    //-----------------------------------------------------------------
    // Digital-product orders and downloads
    //-----------------------------------------------------------------

    public function productOrders(): void
    {
        $user = Auth::requireLogin();

        $this->view('account/product-orders', [
            'title'  => 'Orders',
            'orders' => ProductOrders::forUser((int) $user['id'], 100),
        ]);
    }

    /**
     * Mint a fresh download token for an order the user owns, then
     * redirect straight to it. A separate mint step (rather than
     * downloading directly from this URL) is what makes the actual file
     * URL single-use and short-lived - see DownloadController.
     */
    public function requestProductDownload(array $params): void
    {
        $user = Auth::requireLogin();
        $orderId = $this->id($params);

        $order = ProductOrders::find($orderId);

        if ($order === null || (int) $order['user_id'] !== (int) $user['id']) {
            $this->notFound('That order does not exist.');
        }

        if ($order['status'] !== 'paid') {
            $this->back('/account/product-orders', 'error', 'That order is not in a downloadable state.');
        }

        $token = ProductOrders::issueDownloadToken($orderId, (int) $user['id']);

        $this->redirect('/download/' . $token);
    }

    public function myNfts(): void
    {
        $user = Auth::requireLogin();

        $this->view('account/my-nfts', [
            'title' => 'My inscriptions',
            'items' => Orders::ownedByUser((int) $user['id']),
        ]);
    }

    public function orders(): void
    {
        $user = Auth::requireLogin();

        $this->view('account/orders', [
            'title'  => 'Orders',
            'orders' => Orders::forUser((int) $user['id'], 100),
        ]);
    }

    public function order(array $params): void
    {
        $user = Auth::requireLogin();
        $order = Orders::find($this->id($params));

        if ($order === null || (int) $order['user_id'] !== (int) $user['id']) {
            $this->notFound('That order does not exist.');
        }

        $this->view('account/order-detail', [
            'title'      => 'Order #' . (int) $order['id'],
            'order'      => $order,
            'explorerTx' => is_string($order['tx_hash'] ?? null) && $order['tx_hash'] !== ''
                ? Ordinals::explorerTxUrl((string) $order['tx_hash'])
                : '',
            'explorerInscription' => Ordinals::explorerInscriptionUrl((string) $order['token_id']),
        ]);
    }

    //-----------------------------------------------------------------
    // Settings
    //-----------------------------------------------------------------

    public function settings(): void
    {
        $user = Auth::requireLogin();

        $this->view('account/settings', [
            'title'        => 'Settings',
            'user'         => $user,
            'hasTwoFactor' => Auth::hasTwoFactor($user),
        ]);
    }

    public function updateProfile(): void
    {
        $user = Auth::requireLogin();
        $displayName = Request::post('display_name');

        if (mb_strlen($displayName) > 60) {
            $this->back('/account/settings', 'error', 'Display name must be 60 characters or fewer.');
        }

        Database::run(
            'UPDATE users SET display_name = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$displayName === '' ? null : $displayName, (int) $user['id']]
        );

        $this->back('/account/settings', 'success', 'Profile updated.');
    }

    public function updatePassword(): void
    {
        $user = Auth::requireLogin();

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        // Re-authenticate. Without this, a borrowed unlocked browser is a
        // permanent account takeover rather than a temporary one.
        if (!password_verify($current, (string) $user['password_hash'])) {
            Logger::audit('auth.password_change_failed', 'Wrong current password', (int) $user['id'], 'user', (int) $user['id']);
            $this->back('/account/settings', 'error', 'Your current password is not correct.');
        }

        if ($new !== $confirm) {
            $this->back('/account/settings', 'error', 'The two new passwords do not match.');
        }

        $problems = Auth::passwordProblems($new, (string) $user['email']);
        if ($problems !== []) {
            $this->back('/account/settings', 'error', implode(' ', $problems));
        }

        Database::run(
            'UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [Auth::hashPassword($new), (int) $user['id']]
        );

        Logger::audit('auth.password_changed', 'Password changed from settings', (int) $user['id'], 'user', (int) $user['id']);

        $this->back('/account/settings', 'success', 'Password updated.');
    }

    public function updatePayoutAddress(): void
    {
        $user = Auth::requireLogin();
        $address = Request::post('default_payout_address');

        if ($address === '') {
            Database::run(
                'UPDATE users SET default_payout_address = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [(int) $user['id']]
            );

            $this->back('/account/settings', 'success', 'Saved payout address removed.');
        }

        $validation = Ordinals::validatePayoutAddress($address);

        if (!$validation['ok']) {
            $this->back('/account/settings', 'error', $validation['error']);
        }

        Database::run(
            'UPDATE users SET default_payout_address = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
            [$validation['normalized'], (int) $user['id']]
        );

        Logger::audit('user.payout_address_saved', 'Default payout address updated', (int) $user['id'], 'user', (int) $user['id'], [
            'address' => $validation['normalized'],
        ]);

        $this->back('/account/settings', 'success', 'Payout address saved. You will still confirm it on every purchase.');
    }

    //-----------------------------------------------------------------
    // Two-factor enrolment
    //-----------------------------------------------------------------

    public function showTwoFactorSetup(): void
    {
        $user = Auth::requireLogin();

        if (Auth::hasTwoFactor($user)) {
            $this->view('account/two-factor', [
                'title'   => 'Two-step verification',
                'enabled' => true,
            ]);

            return;
        }

        // Hold the candidate secret in the session until a valid code
        // proves the authenticator actually has it. Writing it to the user
        // row first would lock people out of their own accounts whenever
        // they abandoned the setup halfway.
        $secret = is_string($_SESSION['_2fa_setup_secret'] ?? null)
            ? $_SESSION['_2fa_setup_secret']
            : Totp::generateSecret();

        $_SESSION['_2fa_setup_secret'] = $secret;

        $this->view('account/two-factor', [
            'title'    => 'Two-step verification',
            'enabled'  => false,
            'needsQr'  => true,
            'secret'   => $secret,
            'secretDisplay' => Totp::formatSecretForDisplay($secret),
            'otpauth'  => Totp::provisioningUri($secret, (string) $user['email'], Config::string('app.name')),
        ]);
    }

    public function enableTwoFactor(): void
    {
        $user = Auth::requireLogin();

        $secret = $_SESSION['_2fa_setup_secret'] ?? null;

        if (!is_string($secret) || $secret === '') {
            $this->back('/account/settings/2fa', 'error', 'That setup session expired. Start again.');
        }

        if (!Totp::verify($secret, Request::post('code'))) {
            $this->back('/account/settings/2fa', 'error', 'That code did not match. Check your device clock and try the current code.');
        }

        Database::run(
            'UPDATE users SET twofa_secret = ?, twofa_confirmed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [Auth::encryptSecret($secret), (int) $user['id']]
        );

        unset($_SESSION['_2fa_setup_secret']);

        $codes = Auth::generateRecoveryCodes((int) $user['id']);

        Logger::audit('auth.2fa_enabled', 'Two-factor enabled', (int) $user['id'], 'user', (int) $user['id']);

        $this->view('account/two-factor-codes', [
            'title' => 'Save your recovery codes',
            'codes' => $codes,
        ]);
    }

    public function disableTwoFactor(): void
    {
        $user = Auth::requireLogin();

        // Turning off a second factor is a security downgrade, so it needs
        // the password - the same bar as changing it.
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
            $this->back('/account/settings/2fa', 'error', 'Enter your current password to turn off two-step verification.');
        }

        Database::run(
            'UPDATE users SET twofa_secret = NULL, twofa_confirmed_at = NULL, twofa_recovery = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = ?',
            [(int) $user['id']]
        );

        Logger::audit('auth.2fa_disabled', 'Two-factor disabled', (int) $user['id'], 'user', (int) $user['id']);

        $this->back('/account/settings', 'success', 'Two-step verification turned off.');
    }
}
