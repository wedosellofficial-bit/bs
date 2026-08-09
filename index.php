<?php

declare(strict_types=1);

/**
 * Front controller. The only PHP file meant to be requested directly.
 *
 * This whole tree - index.php, app/, bin/, storage/, resources/ - is
 * deployed as one unit, because some hosting deploy tools (Hostinger's
 * "deploy from GitHub" among them) clone a repository straight into the
 * document root with no option to point at a subfolder. There is no
 * filesystem boundary putting app/ or storage/ outside what Apache can
 * reach, the way there would be with a manual upload that keeps them as
 * siblings of a separate public_html/.
 *
 * The boundary here is enforced entirely by .htaccess instead:
 * app/.htaccess, bin/.htaccess, storage/.htaccess and
 * resources/.htaccess each carry `Require all denied`, and the root
 * .htaccess denies every dotfile (.env included) and every .php file
 * except this one. If you ever find a second working .php file
 * reachable over HTTP, or an .htaccess missing from one of those four
 * directories, something has gone wrong with the deploy.
 */

require __DIR__ . '/app/bootstrap.php';

use App\Controllers\Admin\AdminAnnouncementController;
use App\Controllers\Admin\AdminDepositController;
use App\Controllers\Admin\AdminHomeController;
use App\Controllers\Admin\AdminInventoryController;
use App\Controllers\Admin\AdminOrderController;
use App\Controllers\Admin\AdminTransferController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\CollectionController;
use App\Controllers\CronController;
use App\Controllers\HomeController;
use App\Controllers\MediaController;
use App\Controllers\OrderController;
use App\Controllers\PageController;
use App\Controllers\WalletController;
use App\Controllers\WebhookController;
use App\Lib\Router;

$router = new Router();

//---------------------------------------------------------------------
// Public
//---------------------------------------------------------------------
$router->get('/', [HomeController::class, 'index']);
$router->get('/collection', [CollectionController::class, 'index']);
// AJAX: returns the grid fragment plus the result count. The browser
// pushes the same query string into the URL, so the view stays shareable.
$router->get('/collection/results', [CollectionController::class, 'results']);
$router->get('/nft/{id}', [CollectionController::class, 'show']);
$router->get('/about', [PageController::class, 'about']);
$router->get('/faq', [PageController::class, 'faq']);
$router->get('/terms', [PageController::class, 'terms']);
$router->get('/privacy', [PageController::class, 'privacy']);

// NFT media, streamed from outside the web root.
$router->get('/media/{variant}/{file}', [MediaController::class, 'show']);

//---------------------------------------------------------------------
// Authentication
//---------------------------------------------------------------------
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('/verify-email/resend', [AuthController::class, 'resendVerification']);

$router->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
$router->post('/forgot-password', [AuthController::class, 'sendPasswordReset']);
$router->get('/reset-password', [AuthController::class, 'showResetPassword']);
$router->post('/reset-password', [AuthController::class, 'resetPassword']);

// Second factor, prompted after a correct password.
$router->get('/login/2fa', [AuthController::class, 'showTwoFactor']);
$router->post('/login/2fa', [AuthController::class, 'verifyTwoFactor']);

//---------------------------------------------------------------------
// Account
//---------------------------------------------------------------------
$router->get('/account', [AccountController::class, 'dashboard']);
$router->get('/account/nfts', [AccountController::class, 'myNfts']);
$router->get('/account/orders', [AccountController::class, 'orders']);
$router->get('/account/orders/{id}', [AccountController::class, 'order']);
$router->get('/account/settings', [AccountController::class, 'settings']);
$router->post('/account/settings/profile', [AccountController::class, 'updateProfile']);
$router->post('/account/settings/password', [AccountController::class, 'updatePassword']);
$router->post('/account/settings/payout-address', [AccountController::class, 'updatePayoutAddress']);

// Two-factor enrolment
$router->get('/account/settings/2fa', [AccountController::class, 'showTwoFactorSetup']);
$router->post('/account/settings/2fa/enable', [AccountController::class, 'enableTwoFactor']);
$router->post('/account/settings/2fa/disable', [AccountController::class, 'disableTwoFactor']);

//---------------------------------------------------------------------
// Wallet
//---------------------------------------------------------------------
$router->get('/account/wallet', [WalletController::class, 'index']);
$router->get('/account/wallet/statement', [WalletController::class, 'statement']);
// Generating an address calls the provider's API and costs rate-limit
// budget, so it is a POST with its own limiter - never a page load.
$router->post('/account/wallet/topup', [WalletController::class, 'createTopUp']);
$router->get('/account/wallet/deposit/{id}', [WalletController::class, 'showDeposit']);
// Polled by the deposit page to reflect confirmations as they arrive.
// Read-only: it reports what the webhook has already recorded and can
// never itself credit anything.
$router->get('/account/wallet/deposit/{id}/status', [WalletController::class, 'depositStatus']);

//---------------------------------------------------------------------
// Purchase
//---------------------------------------------------------------------
$router->get('/buy/{id}', [OrderController::class, 'confirm']);
$router->post('/buy/{id}', [OrderController::class, 'purchase']);

//---------------------------------------------------------------------
// Machine endpoints
//
// Both are CSRF-exempt (see Router::CSRF_EXEMPT) because neither has a
// session: the webhook is authenticated by HMAC signature over the raw
// body, the cron endpoint by a token in the query string.
//---------------------------------------------------------------------
$router->post('/webhooks/coinbase', [WebhookController::class, 'coinbase']);
$router->get('/cron/run', [CronController::class, 'run']);
$router->post('/cron/run', [CronController::class, 'run']);
$router->get('/cron/migrate', [CronController::class, 'migrate']);

//---------------------------------------------------------------------
// Admin
//
// Every one of these calls Auth::requireAdmin() in the controller. The
// grouping here is organisational only - it grants nothing.
//---------------------------------------------------------------------
$router->get('/admin', [AdminHomeController::class, 'dashboard']);

$router->get('/admin/users', [AdminUserController::class, 'index']);
$router->get('/admin/users/{id}', [AdminUserController::class, 'show']);
$router->post('/admin/users/{id}/status', [AdminUserController::class, 'updateStatus']);
$router->post('/admin/users/{id}/credit', [AdminUserController::class, 'manualCredit']);

$router->get('/admin/inventory', [AdminInventoryController::class, 'index']);
$router->get('/admin/inventory/new', [AdminInventoryController::class, 'create']);
$router->post('/admin/inventory', [AdminInventoryController::class, 'store']);
$router->get('/admin/inventory/{id}', [AdminInventoryController::class, 'edit']);
$router->post('/admin/inventory/{id}', [AdminInventoryController::class, 'update']);
$router->post('/admin/inventory/{id}/delete', [AdminInventoryController::class, 'destroy']);
$router->post('/admin/inventory/rarity', [AdminInventoryController::class, 'recomputeRarity']);

$router->get('/admin/orders', [AdminOrderController::class, 'index']);
$router->get('/admin/orders/{id}', [AdminOrderController::class, 'show']);
$router->post('/admin/orders/{id}/refund', [AdminOrderController::class, 'refund']);

$router->get('/admin/transfers', [AdminTransferController::class, 'index']);
$router->post('/admin/transfers/{id}/claim', [AdminTransferController::class, 'claim']);
$router->post('/admin/transfers/{id}/release', [AdminTransferController::class, 'release']);
$router->post('/admin/transfers/{id}/sent', [AdminTransferController::class, 'markSent']);
$router->post('/admin/transfers/{id}/complete', [AdminTransferController::class, 'markComplete']);
$router->post('/admin/transfers/{id}/failed', [AdminTransferController::class, 'markFailed']);

$router->get('/admin/deposits', [AdminDepositController::class, 'index']);
$router->post('/admin/deposits/{id}/credit', [AdminDepositController::class, 'manualCredit']);

$router->get('/admin/announcements', [AdminAnnouncementController::class, 'index']);
$router->post('/admin/announcements', [AdminAnnouncementController::class, 'store']);
$router->post('/admin/announcements/{id}/delete', [AdminAnnouncementController::class, 'destroy']);

$router->dispatch();
