<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth;
use App\Controllers\Controller;
use App\Database;
use App\Lib\Request;
use App\ProductOrders;
use RuntimeException;

final class AdminProductOrderController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $status = Request::query('status', 'all');

        $this->view('admin/product-orders', [
            'title'  => 'Product orders',
            'orders' => ProductOrders::recent($status, 200),
            'status' => $status,
            'stats'  => ProductOrders::stats(),
        ], 'layout/admin');
    }

    public function show(array $params): void
    {
        Auth::requireAdmin();
        $order = ProductOrders::find($this->id($params));

        if ($order === null) {
            $this->notFound('No such order.');
        }

        $this->view('admin/product-order-detail', [
            'title'  => 'Order #' . (int) $order['id'],
            'order'  => $order,
            'buyer'  => Database::first('SELECT id, email, status FROM users WHERE id = ?', [(int) $order['user_id']]),
            'ledger' => Database::all(
                'SELECT * FROM wallet_entries WHERE reference_type = ? AND reference_id = ? ORDER BY id ASC',
                ['product_order', (int) $order['id']]
            ),
            'downloads' => Database::all(
                'SELECT * FROM download_tokens WHERE product_order_id = ? ORDER BY id DESC',
                [(int) $order['id']]
            ),
        ], 'layout/admin');
    }

    public function refund(array $params): void
    {
        $admin = Auth::requireAdmin();
        $orderId = $this->id($params);

        $reason = Request::post('reason');
        if (mb_strlen($reason) < 5) {
            $this->back('/admin/product-orders/' . $orderId, 'error', 'Give a reason for the refund - it is recorded against the order.');
        }

        try {
            ProductOrders::refund($orderId, (int) $admin['id'], $reason);
        } catch (RuntimeException $e) {
            $this->back('/admin/product-orders/' . $orderId, 'error', $e->getMessage());
        }

        $this->back('/admin/product-orders/' . $orderId, 'success', 'Refunded. The credit shows on the buyer\'s statement alongside the original charge.');
    }
}
