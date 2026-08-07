<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Request;
use App\Orders;
use App\Ordinals;
use RuntimeException;

final class AdminOrderController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $status = Request::query('status', 'all');

        $this->view('admin/orders', [
            'title'  => 'Orders',
            'orders' => Orders::recent($status, 200),
            'status' => $status,
            'stats'  => Orders::stats(),
        ], 'layout/admin');
    }

    public function show(array $params): void
    {
        Auth::requireAdmin();
        $order = Orders::find($this->id($params));

        if ($order === null) {
            $this->notFound('No such order.');
        }

        $this->view('admin/order-detail', [
            'title'  => 'Order #' . (int) $order['id'],
            'order'  => $order,
            'buyer'  => Database::first('SELECT id, email, status FROM users WHERE id = ?', [(int) $order['user_id']]),
            'ledger' => Database::all(
                'SELECT * FROM wallet_entries WHERE reference_type = ? AND reference_id = ? ORDER BY id ASC',
                ['order', (int) $order['id']]
            ),
            'explorerTx' => is_string($order['tx_hash'] ?? null) && $order['tx_hash'] !== ''
                ? Ordinals::explorerTxUrl((string) $order['tx_hash'])
                : '',
        ], 'layout/admin');
    }

    public function refund(array $params): void
    {
        $admin = Auth::requireAdmin();
        $orderId = $this->id($params);

        $reason = Request::post('reason');

        if (mb_strlen($reason) < 5) {
            $this->back('/admin/orders/' . $orderId, 'error', 'Give a reason for the refund - it is recorded against the order.');
        }

        try {
            Orders::refund($orderId, (int) $admin['id'], $reason, Request::postBool('relist'));
        } catch (RuntimeException $e) {
            $this->back('/admin/orders/' . $orderId, 'error', $e->getMessage());
        }

        $this->back('/admin/orders/' . $orderId, 'success', 'Refunded. The credit shows on the buyer\'s statement alongside the original charge.');
    }
}
