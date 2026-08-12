<?php

declare(strict_types=1);

/**
 * Reminder toast for a signed-in visitor whose wallet has not yet
 * reached the activation threshold. Included from layout/app.php only
 * when that condition holds - never for a guest, never for an activated
 * account. See the "Under-$50 activation reminder" section of
 * assets/js/app.js for the show/hide cadence and the once-dismissed
 * sessionStorage flag. This is a status toast, not a modal: it never
 * grants access to anything by itself - Router::marketplaceGateApplies()
 * is what actually keeps the marketplace closed.
 */

use App\AccountActivation;
use App\Lib\Fmt;

$minActivation = Fmt::money(AccountActivation::minActivationMinor());
?>
<div id="activation-reminder" class="activation-reminder" role="status" aria-live="polite" hidden>
    <button type="button" class="activation-reminder-close" data-activation-reminder-close aria-label="Dismiss">
        <svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true">
            <path d="M2 2 12 12M12 2 2 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
    </button>

    <p class="activation-reminder-title">Fund your wallet to enable marketplace</p>
    <p class="activation-reminder-text">
        Add at least <?= Fmt::e($minActivation) ?> to your wallet to activate marketplace access.
    </p>

    <a href="/account/wallet" class="btn btn-primary btn-sm mt-3">Fund wallet</a>
</div>
