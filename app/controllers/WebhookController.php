<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Logger;
use App\Lib\Request;
use App\Payments;
use Throwable;

/**
 * Payment provider webhooks.
 *
 * This endpoint is CSRF-exempt because it has no session to carry a
 * token. Its authentication is the HMAC signature over the raw body,
 * verified in Payments::verifyWebhookSignature() before the body is
 * parsed - an unsigned or badly signed request is rejected without ever
 * being interpreted as JSON.
 */
final class WebhookController extends Controller
{
    public function coinbase(): void
    {
        // Read the body exactly as received. Anything that re-encodes it
        // before verification (json_decode/json_encode, trimming, charset
        // conversion) will produce a different byte sequence and a
        // different HMAC.
        $rawBody = Request::rawBody();
        $signature = Request::header('X-CC-Webhook-Signature');

        // A body this large is not a charge notification.
        if (strlen($rawBody) > 512_000) {
            http_response_code(413);
            echo 'payload too large';

            return;
        }

        try {
            $result = Payments::handleWebhook($rawBody, $signature);
        } catch (Throwable $e) {
            Logger::write('webhook.unhandled', $e->getMessage());

            // 500 so the provider retries; the delivery has not been
            // durably recorded if we got here.
            http_response_code(500);
            echo 'error';

            return;
        }

        http_response_code($result['http']);

        // The provider only reads the status code. Keep the body minimal -
        // echoing anything about our state to an unauthenticated caller is
        // free information.
        echo $result['http'] >= 200 && $result['http'] < 300 ? 'ok' : 'rejected';
    }
}
