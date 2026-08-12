<?php

declare(strict_types=1);

/**
 * Show/hide button for a password field, positioned inside a `relative`
 * wrapper around the input (see auth/register.php and auth/login.php).
 * Pure presentation - toggling `type` between password/text client-side
 * changes nothing about what gets submitted or how it's hashed.
 *
 * @var string $for The input id this button controls.
 */

use App\Lib\Fmt;
?>
<button type="button"
        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-ink-500 transition-colors hover:text-ink-200"
        data-toggle-password="<?= Fmt::e($for) ?>"
        aria-label="Show password"
        aria-pressed="false">
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true" data-toggle-password-icon-shown>
        <path d="M1.5 9s2.75-5.25 7.5-5.25S16.5 9 16.5 9s-2.75 5.25-7.5 5.25S1.5 9 1.5 9Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
        <circle cx="9" cy="9" r="2.25" stroke="currentColor" stroke-width="1.4"/>
    </svg>
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true" class="hidden" data-toggle-password-icon-hidden>
        <path d="M1.5 9s2.75-5.25 7.5-5.25S16.5 9 16.5 9s-2.75 5.25-7.5 5.25S1.5 9 1.5 9Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
        <circle cx="9" cy="9" r="2.25" stroke="currentColor" stroke-width="1.4"/>
        <path d="M2.5 15 15.5 3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
</button>
