<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Nft;
use App\Orders;

final class AdminHomeController extends Controller
{
    public function dashboard(): void
    {
        Auth::requireAdmin();

        $this->view('admin/dashboard', [
            'title'       => 'Admin',
            'orderStats'  => Orders::stats(),
            'queueCounts' => Nft::queueCounts(),
            'inventory'   => Database::first(
                "SELECT
                    SUM(CASE WHEN status = 'listed' THEN 1 ELSE 0 END) AS listed,
                    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved,
                    SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) AS sold,
                    SUM(CASE WHEN status = 'transferred' THEN 1 ELSE 0 END) AS transferred,
                    COUNT(*) AS total
                   FROM nfts"
            ) ?? [],
            'deposits'    => Database::first(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'underpaid' THEN 1 ELSE 0 END) AS needs_review,
                    CAST(COALESCE(SUM(CASE WHEN status = 'credited' THEN amount_minor_credited ELSE 0 END), 0) AS SIGNED) AS credited_total
                   FROM deposits"
            ) ?? [],
            'userCount'   => (int) Database::scalar('SELECT COUNT(*) FROM users', [], 0),
            // Surfaced prominently: an unprocessed webhook is money the
            // provider thinks it delivered and we have not acted on.
            'failedWebhooks' => Database::all(
                'SELECT id, event_type, process_error, received_at
                   FROM webhook_events
                  WHERE processed_at IS NULL AND signature_ok = 1
                  ORDER BY received_at DESC LIMIT 10'
            ),
            'lastReconciliation' => Database::first(
                'SELECT * FROM reconciliation_runs ORDER BY id DESC LIMIT 1'
            ),
            'recentAudit' => Database::all(
                'SELECT a.event, a.message, a.created_at, u.email AS actor_email
                   FROM audit_log a
                   LEFT JOIN users u ON u.id = a.actor_user_id
                  ORDER BY a.id DESC LIMIT 15'
            ),
        ], 'layout/admin');
    }
}
