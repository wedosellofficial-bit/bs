<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Nft;
use App\Orders;
use App\ProductOrders;

final class AdminHomeController extends Controller
{
    public function dashboard(): void
    {
        Auth::requireAdmin();

        $this->view('admin/dashboard', [
            'title'       => 'Admin',
            'orderStats'  => Orders::stats(),
            'productOrderStats' => ProductOrders::stats(),
            'activeAccountCount' => (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE account_status = 'active'", [], 0
            ),
            'pendingAccountCount' => (int) Database::scalar(
                "SELECT COUNT(*) FROM users WHERE account_status = 'pending'", [], 0
            ),
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
            'userCount'   => (int) Database::scalar('SELECT COUNT(*) FROM users', [], 0),
            // Manual BTC deposits are credited through the same path as
            // any other balance adjustment (AdminUserController::
            // manualCredit(), type=adjustment, reference_type=manual),
            // so this is the closest thing to a "deposits this week"
            // figure without a separate deposit-tracking table.
            'recentManualCredits' => Database::first(
                "SELECT COUNT(*) AS count,
                        CAST(COALESCE(SUM(amount_minor), 0) AS SIGNED) AS total_minor
                   FROM wallet_entries
                  WHERE type = 'adjustment' AND reference_type = 'manual'
                    AND amount_minor > 0
                    AND created_at > (UTC_TIMESTAMP() - INTERVAL 7 DAY)"
            ) ?? [],
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
