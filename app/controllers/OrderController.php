<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\RateLimiter;
use App\Lib\Request;
use App\Nft;
use App\Orders;
use App\Ordinals;
use App\Wallet;
use RuntimeException;
use Throwable;

/**
 * The purchase flow: confirm, then buy.
 *
 * The confirmation screen shows the balance before and after, and asks
 * for the payout address explicitly every time - even when one is saved
 * on the account. Sending an inscription is irreversible, and a stored
 * address that was correct last month is not evidence that it is correct
 * today.
 */
final class OrderController extends Controller
{
    public function confirm(array $params): void
    {
        $user = Auth::requireVerified();
        $nft = Nft::find($this->id($params));

        if ($nft === null || $nft['status'] === Nft::STATUS_RESERVED) {
            $this->notFound('That item is not available.');
        }

        if ($nft['status'] !== Nft::STATUS_LISTED) {
            $this->back('/nft/' . (int) $nft['id'], 'error', 'That item has already been sold.');
        }

        $balance = Wallet::balance((int) $user['id']);
        $price = (int) $nft['price_minor'];

        $this->view('account/confirm-purchase', [
            'title'          => 'Confirm purchase',
            'nft'            => $nft,
            'balance'        => $balance,
            'price'          => $price,
            'balanceAfter'   => $balance - $price,
            'canAfford'      => $balance >= $price,
            'shortfall'      => max(0, $price - $balance),
            'savedAddress'   => $user['default_payout_address'],
            'attributes'     => Nft::attributes($nft),
        ]);
    }

    public function purchase(array $params): void
    {
        $user = Auth::requireVerified();
        $userId = (int) $user['id'];
        $nftId = $this->id($params);

        if (!RateLimiter::attempt('purchase', (string) $userId)) {
            $this->back('/buy/' . $nftId, 'error', RateLimiter::waitMessage('purchase', (string) $userId));
        }

        $address = Request::post('payout_address');

        // An explicit acknowledgement that the address is final. This is
        // the last point at which a typo is recoverable, and the whole
        // transfer is one-way after it.
        if (!Request::postBool('confirm_address')) {
            $this->back('/buy/' . $nftId, 'error', 'Confirm that the payout address is correct before buying.');
        }

        $validation = Ordinals::validatePayoutAddress($address);
        if (!$validation['ok']) {
            $this->back('/buy/' . $nftId, 'error', $validation['error']);
        }

        try {
            $result = Orders::purchase($userId, $nftId, $address);
        } catch (RuntimeException $e) {
            // Domain errors carry messages written for the buyer.
            $this->back('/buy/' . $nftId, 'error', $e->getMessage());
        } catch (Throwable $e) {
            Logger::write('order.purchase_failed', $e->getMessage(), [
                'user_id' => $userId,
                'nft_id'  => $nftId,
            ]);

            $this->back('/buy/' . $nftId, 'error', 'The purchase could not be completed. Your balance has not been charged.');
        }

        if (Request::postBool('remember_address')) {
            Database::run(
                'UPDATE users SET default_payout_address = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$validation['normalized'], $userId]
            );
        }

        Auth::flash('success', sprintf(
            'Bought. %s debited, balance now %s. The transfer is queued.',
            Fmt::money($result['price_minor']),
            Fmt::money($result['balance_after'])
        ));

        $this->redirect('/account/orders/' . $result['order_id']);
    }
}
