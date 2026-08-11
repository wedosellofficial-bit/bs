<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AccountActivation;
use App\Auth;
use App\Lib\Config;
use App\Lib\Request;
use App\Wallet;

/**
 * Balance and statement.
 *
 * Deposits are manual: the wallet page shows one BTC address the
 * operator controls, with a QR code, and asks the customer to wait for
 * an admin to credit them after checking the deposit on a block
 * explorer. There is no address generation, no webhook, and nothing in
 * this controller ever writes to the ledger - crediting happens
 * exclusively through AdminUserController::manualCredit(), which goes
 * through Wallet::credit() like every other credit, so a manually
 * credited deposit shows up on the customer's statement indistinguishable
 * in mechanism from any other ledger entry.
 *
 * This used to also handle Coinbase Commerce charge creation and
 * per-deposit status polling, because Coinbase Commerce is not reachable
 * from this store's operating country. The controller that received its
 * webhook (WebhookController) has been removed along with the route to
 * it; App\Payments, the class with the actual logic, is kept but
 * disabled - see the comment at the top of that file - since restoring
 * it is more involved than restoring a thin controller.
 */
final class WalletController extends Controller
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $userId = (int) $user['id'];

        $address = Config::string('manual_deposit.btc_address');
        $balance = Wallet::balance($userId);

        $this->view('account/wallet', [
            'title'      => 'Wallet',
            // Renders the QR client-side (qrcode.min.js), so the address
            // never goes anywhere but this page and the wallet software
            // that reads it - no third-party QR image service.
            'needsQr'    => $address !== '',
            'balance'    => $balance,
            'totals'     => Wallet::totals($userId),
            'statement'  => Wallet::statement($userId, 8),
            'depositAddress' => $address,
            'isActive'   => AccountActivation::isActive($user),
            'minActivationMinor' => AccountActivation::minActivationMinor(),
        ]);
    }

    /** Full paged statement. */
    public function statement(): void
    {
        $user = Auth::requireLogin();
        $userId = (int) $user['id'];

        $perPage = 40;
        $total = Wallet::statementCount($userId);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, Request::queryInt('page', 1) ?? 1));

        $this->view('account/statement', [
            'title'   => 'Statement',
            'entries' => Wallet::statement($userId, $perPage, ($page - 1) * $perPage),
            'balance' => Wallet::balance($userId),
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ]);
    }
}
