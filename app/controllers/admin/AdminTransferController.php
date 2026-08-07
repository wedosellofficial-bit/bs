<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Mailer;
use App\Lib\Request;
use App\Nft;
use InvalidArgumentException;

/**
 * The manual transfer queue.
 *
 * This is where TRANSFER MODE = manual actually lives. An admin claims an
 * item, sends the inscription from the project wallet using their own
 * wallet software, and pastes the resulting txid back here. No signing
 * key exists anywhere in this application, which is the entire security
 * argument for starting manual: a compromise of this host cannot move a
 * single inscription, because this host cannot sign.
 */
final class AdminTransferController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $filter = Request::query('status', 'open');

        $this->view('admin/transfers', [
            'title'  => 'Transfer queue',
            'items'  => Nft::transferQueue($filter),
            'counts' => Nft::queueCounts(),
            'filter' => $filter,
        ], 'layout/admin');
    }

    public function claim(array $params): void
    {
        $admin = Auth::requireAdmin();
        $queueId = $this->id($params);

        $claimed = Nft::claimTransfer($queueId, (int) $admin['id']);

        $this->back(
            '/admin/transfers',
            $claimed ? 'success' : 'error',
            $claimed
                ? 'Claimed. Send the inscription, then record the transaction id here.'
                : 'Another admin already has that one.'
        );
    }

    public function release(array $params): void
    {
        $admin = Auth::requireAdmin();

        Nft::releaseTransfer($this->id($params), (int) $admin['id']);

        $this->back('/admin/transfers', 'success', 'Released back to the queue.');
    }

    public function markSent(array $params): void
    {
        $admin = Auth::requireAdmin();
        $queueId = $this->id($params);

        try {
            Nft::markTransferSent($queueId, (int) $admin['id'], Request::post('txid'));
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/transfers', 'error', $e->getMessage());
        }

        $this->back('/admin/transfers', 'success', 'Recorded as sent. Mark it complete once it confirms.');
    }

    public function markComplete(array $params): void
    {
        $admin = Auth::requireAdmin();
        $queueId = $this->id($params);

        try {
            Nft::markTransferComplete($queueId, (int) $admin['id']);
        } catch (InvalidArgumentException $e) {
            $this->back('/admin/transfers', 'error', $e->getMessage());
        }

        // Tell the buyer. Failure to send is logged inside Mailer and must
        // not roll back a completed transfer.
        $row = Database::first(
            'SELECT u.email, n.name, o.tx_hash
               FROM transfer_queue q
               JOIN orders o ON o.id = q.order_id
               JOIN users u ON u.id = o.user_id
               JOIN nfts n ON n.id = o.nft_id
              WHERE q.id = ?',
            [$queueId]
        );

        if ($row !== null) {
            Mailer::sendTransferComplete(
                (string) $row['email'],
                (string) $row['name'],
                (string) ($row['tx_hash'] ?? '')
            );
        }

        $this->back('/admin/transfers', 'success', 'Marked complete and the buyer has been emailed.');
    }

    public function markFailed(array $params): void
    {
        $admin = Auth::requireAdmin();
        $reason = Request::post('reason');

        if ($reason === '') {
            $this->back('/admin/transfers', 'error', 'Say what went wrong - the reason is shown to whoever picks this up next.');
        }

        Nft::markTransferFailed($this->id($params), (int) $admin['id'], $reason);

        $this->back('/admin/transfers', 'success', 'Marked failed. Refund the order from the Orders screen if the buyer should get their money back.');
    }
}
