<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\Request;
use App\Ordinals;
use App\Payments;
use App\Wallet;
use InvalidArgumentException;

final class AdminDepositController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $status = Request::query('status', 'all');

        $params = [];
        $where = '1 = 1';

        if (in_array($status, ['pending', 'confirmed', 'credited', 'expired', 'underpaid', 'failed'], true)) {
            $where = 'd.status = ?';
            $params[] = $status;
        }

        $this->view('admin/deposits', [
            'title'    => 'Deposits',
            'deposits' => Database::all(
                "SELECT d.*, u.email AS user_email
                   FROM deposits d
                   JOIN users u ON u.id = d.user_id
                  WHERE {$where}
                  ORDER BY d.id DESC
                  LIMIT 200",
                $params
            ),
            'status'   => $status,
            'counts'   => $this->counts(),
            // An unprocessed signed webhook is a delivery we accepted and
            // then failed to act on. It belongs on this screen because the
            // fix is usually a manual credit.
            'stuckWebhooks' => Database::all(
                'SELECT id, event_id, event_type, process_error, received_at
                   FROM webhook_events
                  WHERE signature_ok = 1 AND processed_at IS NULL
                  ORDER BY received_at DESC LIMIT 25'
            ),
        ], 'layout/admin');
    }

    /**
     * Credit a deposit by hand.
     *
     * For the cases the webhook path deliberately refuses: an underpayment
     * the store decides to honour, or a delivery that never arrived. It
     * goes through the same Wallet lock and the same uniqueness constraint
     * as the automatic path, so it cannot double-credit a deposit the
     * webhook later resolves.
     */
    public function manualCredit(array $params): void
    {
        $admin = Auth::requireAdmin();
        $depositId = $this->id($params);

        $deposit = Payments::findDeposit($depositId);

        if ($deposit === null) {
            $this->notFound('No such deposit.');
        }

        if ($deposit['status'] === 'credited') {
            $this->back('/admin/deposits', 'error', 'That deposit has already been credited.');
        }

        $reason = Request::post('reason');
        if (mb_strlen($reason) < 5) {
            $this->back('/admin/deposits', 'error', 'Give a reason - manual credits are audited.');
        }

        try {
            $amountMinor = Request::post('amount') === ''
                ? (int) $deposit['amount_minor_requested']
                : Fmt::parseMoneyToMinor(Request::post('amount'));
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/deposits', 'error', $e->getMessage());
        }

        if ($amountMinor <= 0) {
            $this->back('/admin/deposits', 'error', 'Enter an amount greater than zero.');
        }

        $userId = (int) $deposit['user_id'];

        Wallet::withUserLock($userId, static function () use ($depositId, $userId, $amountMinor, $admin): void {
            // Re-read under the lock. If a webhook credited this deposit
            // between the page load and the click, stop.
            $fresh = Database::first('SELECT status FROM deposits WHERE id = ? FOR UPDATE', [$depositId]);

            if ($fresh === null || $fresh['status'] === 'credited') {
                return;
            }

            Wallet::credit(
                $userId,
                $amountMinor,
                Wallet::TYPE_DEPOSIT,
                'deposit',
                $depositId,
                'Deposit credited manually',
                (int) $admin['id']
            );

            Database::run(
                "UPDATE deposits
                    SET status = 'credited', amount_minor_credited = ?, credited_at = UTC_TIMESTAMP()
                  WHERE id = ?",
                [$amountMinor, $depositId]
            );
        });

        Logger::audit(
            'admin.deposit_manual_credit',
            sprintf('Manually credited %s: %s', Fmt::money($amountMinor), mb_substr($reason, 0, 150)),
            (int) $admin['id'],
            'deposit',
            $depositId,
            ['user_id' => $userId, 'amount_minor' => $amountMinor]
        );

        $this->back('/admin/deposits', 'success', sprintf('Credited %s.', Fmt::money($amountMinor)));
    }

    private function counts(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM deposits GROUP BY status');

        $out = ['pending' => 0, 'confirmed' => 0, 'credited' => 0, 'expired' => 0, 'underpaid' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /** Explorer link helper for the view. */
    public static function explorerTx(?string $txid): string
    {
        return is_string($txid) && $txid !== '' ? Ordinals::explorerTxUrl($txid) : '';
    }
}
