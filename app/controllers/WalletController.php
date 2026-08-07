<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Lib\Config;
use App\Lib\Fmt;
use App\Lib\RateLimiter;
use App\Lib\Request;
use App\Lib\Response;
use App\Ordinals;
use App\Payments;
use App\Wallet;
use RuntimeException;
use Throwable;

/**
 * Balance, top-up, and deposit tracking.
 *
 * Nothing in this controller credits the ledger. Crediting happens in
 * Payments::handleWebhook() and nowhere else - see the note at the top of
 * that class. In particular depositStatus() is a read-only reflection of
 * what the webhook has already recorded, even though it is the endpoint a
 * user's browser polls while waiting.
 */
final class WalletController extends Controller
{
    public function index(): void
    {
        $user = Auth::requireLogin();
        $userId = (int) $user['id'];

        $active = Payments::activeDeposit($userId);

        $this->view('account/wallet', [
            'title'        => 'Wallet',
            'balance'      => Wallet::balance($userId),
            'totals'       => Wallet::totals($userId),
            'deposits'     => Payments::depositsForUser($userId, 20),
            'activeDeposit' => $active,
            'rate'         => Payments::indicativeRate(),
            'statement'    => Wallet::statement($userId, 8),
            'limits'       => [
                'min' => Config::int('ledger.topup_min', 2500),
                'max' => Config::int('ledger.topup_max', 2500000),
            ],
            'requiredConfirmations' => Config::int('payments.required_confs', 2),
            'feeBps'       => Config::int('payments.fee_bps', 0),
            'networkFee'   => Config::int('payments.network_fee', 0),
            'asset'        => Config::string('payments.asset', 'BTC'),
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

    /**
     * Generate a fresh deposit address.
     *
     * A new charge - and therefore a new address - per request. Reusing an
     * address across top-ups makes two deposits indistinguishable at the
     * provider and links the customer's payments together on-chain for
     * anyone watching.
     */
    public function createTopUp(): void
    {
        $user = Auth::requireVerified();
        $userId = (int) $user['id'];

        $subject = (string) $userId;

        if (!RateLimiter::attempt('topup_address', $subject)) {
            $this->back('/account/wallet', 'error', RateLimiter::waitMessage('topup_address', $subject));
        }

        try {
            $amountMinor = Fmt::parseMoneyToMinor(Request::post('amount'));
        } catch (\InvalidArgumentException $e) {
            $this->back('/account/wallet', 'error', $e->getMessage());
        }

        try {
            $deposit = Payments::createDeposit($userId, $amountMinor);
        } catch (RuntimeException $e) {
            $this->back('/account/wallet', 'error', $e->getMessage());
        } catch (Throwable $e) {
            \App\Lib\Logger::write('wallet.topup_failed', $e->getMessage(), ['user_id' => $userId]);
            $this->back('/account/wallet', 'error', 'Could not reach the payment provider. Try again in a moment.');
        }

        $this->redirect('/account/wallet/deposit/' . (int) $deposit['id']);
    }

    /** The send-payment screen: address, QR, amount, rate, expiry. */
    public function showDeposit(array $params): void
    {
        $user = Auth::requireLogin();
        $deposit = Payments::findDeposit($this->id($params));

        // Ownership check. A deposit id is a small integer, and without
        // this any logged-in user could read another's address and amount
        // by incrementing it.
        if ($deposit === null || (int) $deposit['user_id'] !== (int) $user['id']) {
            $this->notFound('That deposit does not exist.');
        }

        $this->view('account/deposit', [
            'title'   => 'Send ' . Config::string('payments.asset', 'BTC'),
            // Loads qrcode.min.js in the layout. Set here rather than in
            // the template, because template locals do not reach the layout.
            'needsQr' => true,
            'deposit' => $deposit,
            'balance' => Wallet::balance((int) $user['id']),
            'explorerAddressUrl' => is_string($deposit['address'] ?? null)
                ? sprintf(Config::string('chain.explorer_addr'), (string) $deposit['address'])
                : '',
            'explorerTxUrl' => is_string($deposit['txid'] ?? null) && $deposit['txid'] !== ''
                ? Ordinals::explorerTxUrl((string) $deposit['txid'])
                : '',
            'networkFee' => Config::int('payments.network_fee', 0),
        ]);
    }

    /**
     * Status poll for the deposit page.
     *
     * Strictly read-only. It reports the row as the webhook left it; it
     * does not contact the provider and it cannot change a status. If this
     * endpoint could credit, then anyone who could reach it could mint
     * balance.
     */
    public function depositStatus(array $params): void
    {
        $user = Auth::requireLogin();
        $deposit = Payments::findDeposit($this->id($params));

        if ($deposit === null || (int) $deposit['user_id'] !== (int) $user['id']) {
            Response::jsonError('Not found', 404);
        }

        $status = (string) $deposit['status'];

        Response::json([
            'ok'             => true,
            'status'         => $status,
            'label'          => self::statusLabel($status),
            'confirmations'  => (int) $deposit['confirmations'],
            'required'       => (int) $deposit['required_confirmations'],
            'txid'           => $deposit['txid'],
            'txid_short'     => is_string($deposit['txid'] ?? null) && $deposit['txid'] !== ''
                ? Fmt::truncateMiddle((string) $deposit['txid'], 10, 8)
                : null,
            'explorer_url'   => is_string($deposit['txid'] ?? null) && $deposit['txid'] !== ''
                ? Ordinals::explorerTxUrl((string) $deposit['txid'])
                : null,
            'credited_minor' => $deposit['amount_minor_credited'] === null
                ? null
                : (int) $deposit['amount_minor_credited'],
            'credited_display' => $deposit['amount_minor_credited'] === null
                ? null
                : Fmt::money((int) $deposit['amount_minor_credited']),
            'balance'        => Fmt::money(Wallet::balance((int) $user['id'])),
            // Tells the page to stop polling.
            'final'          => in_array($status, ['credited', 'expired', 'failed', 'underpaid'], true),
        ]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'   => 'Waiting for payment',
            'confirmed' => 'Confirming',
            'credited'  => 'Credited',
            'expired'   => 'Quote expired',
            'underpaid' => 'Needs review',
            'failed'    => 'Failed',
            default     => ucfirst($status),
        };
    }
}
