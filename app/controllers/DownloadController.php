<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Lib\DeliverableStore;
use App\Lib\Response;
use App\ProductOrders;

/**
 * Serves a purchased deliverable from a single-use, short-lived token.
 *
 * The token itself is the authentication - it is bound to one order and
 * one user at mint time (AccountController::requestProductDownload()) and
 * is spent the instant it is looked up (ProductOrders::consumeDownloadToken()),
 * inside a row lock, so two simultaneous requests for the same link
 * cannot both succeed. This route still requires a session on top of
 * that: a token found in, say, a referrer log is useless without also
 * being signed in as the user it was minted for.
 */
final class DownloadController extends Controller
{
    public function show(array $params): void
    {
        $user = Auth::requireLogin();
        $token = (string) ($params['token'] ?? '');

        $consumed = ProductOrders::consumeDownloadToken($token);

        if ($consumed === null) {
            $this->notFound('This download link has expired or was already used. Go back to your orders and generate a new one.');
        }

        if ($consumed['user_id'] !== (int) $user['id']) {
            // The token was valid but minted for someone else's session.
            // Logged rather than just 404'd - a mismatch here is worth
            // knowing about, unlike an expired link, which is routine.
            \App\Lib\Logger::audit(
                'download.user_mismatch',
                'Download token redeemed by a different user than it was issued to',
                (int) $user['id'],
                'download_token',
                $consumed['product_order_id']
            );
            $this->notFound();
        }

        $order = Database::first(
            'SELECT o.id, o.status, p.deliverable_path, p.deliverable_mime, p.deliverable_original_name
               FROM product_orders o
               JOIN products p ON p.id = o.product_id
              WHERE o.id = ?',
            [$consumed['product_order_id']]
        );

        if ($order === null || $order['status'] !== 'paid') {
            $this->notFound('That order is not available for download.');
        }

        $path = DeliverableStore::absolutePath((string) $order['deliverable_path']);
        if ($path === null) {
            $this->notFound('The file for this order is missing. Contact support.');
        }

        Response::download(
            $path,
            (string) $order['deliverable_mime'],
            (string) $order['deliverable_original_name']
        );
    }
}
