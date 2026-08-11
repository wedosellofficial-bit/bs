<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Lib\Fmt;
use App\Lib\Logger;
use App\Lib\RateLimiter;
use App\Membership;
use App\Wallet;
use RuntimeException;
use Throwable;

final class MembershipController extends Controller
{
    public function show(): void
    {
        $user = Auth::user();

        $this->view('public/membership', [
            'title'     => 'Billions Membership',
            'feeMinor'  => Membership::feeMinor(),
            'isMember'  => Membership::isMember($user),
            'balance'   => $user !== null ? Wallet::balance((int) $user['id']) : null,
        ]);
    }

    public function join(): void
    {
        $user = Auth::requireVerified();
        $userId = (int) $user['id'];

        if (!RateLimiter::attempt('membership_join', (string) $userId)) {
            $this->back('/membership', 'error', RateLimiter::waitMessage('membership_join', (string) $userId));
        }

        try {
            $result = Membership::join($userId);
        } catch (RuntimeException $e) {
            $this->back('/membership', 'error', $e->getMessage());
        } catch (Throwable $e) {
            Logger::write('membership.join_failed', $e->getMessage(), ['user_id' => $userId]);

            $this->back('/membership', 'error', 'Membership could not be activated. Your balance has not been charged.');
        }

        $this->back(
            '/account',
            'success',
            $result['fee_minor'] > 0
                ? sprintf('Welcome to Billions Membership. %s was debited from your balance.', Fmt::money($result['fee_minor']))
                : 'Welcome to Billions Membership.'
        );
    }
}
