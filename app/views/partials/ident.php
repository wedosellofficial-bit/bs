<?php

declare(strict_types=1);

/**
 * A click-to-copy on-chain identifier.
 *
 * Always mono, always middle-truncated, never wrapped. The full value
 * goes in data-copy (for the clipboard) and in title (for a hover
 * check), while the visible text stays a single unbreakable line.
 *
 * @var string $value      Full identifier.
 * @var int    $head       Leading characters to show.
 * @var int    $tail       Trailing characters to show.
 * @var string $label      Accessible description, e.g. "payout address".
 * @var string $href       Optional explorer link.
 */

use App\Lib\Fmt;

$value = (string) ($value ?? '');
$head = (int) ($head ?? 8);
$tail = (int) ($tail ?? 6);
$label = (string) ($label ?? 'identifier');
$href = (string) ($href ?? '');

if ($value === '') {
    echo '<span class="ident text-ink-600">' . Fmt::e(Fmt::EM_DASH) . '</span>';

    return;
}

$short = Fmt::truncateMiddle($value, $head, $tail);
?>
<span class="inline-flex items-center gap-1">
    <button type="button"
            class="ident ident-copy"
            data-copy="<?= Fmt::e($value) ?>"
            title="<?= Fmt::e($value) ?>"
            aria-label="Copy <?= Fmt::e($label) ?>: <?= Fmt::e($value) ?>">
        <span aria-hidden="true"><?= Fmt::e($short) ?></span>
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" class="shrink-0 opacity-50">
            <rect x="3.5" y="3.5" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.2"/>
            <path d="M2.5 8V2.5H8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
    </button>

    <?php if ($href !== ''): ?>
        <a href="<?= Fmt::e($href) ?>" target="_blank" rel="noopener noreferrer nofollow"
           class="text-ink-600 transition-colors hover:text-ember-500"
           aria-label="View <?= Fmt::e($label) ?> on a block explorer (opens in a new tab)">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <path d="M4.5 2.5H2.5v7h7V7.5M7 2.5h2.5V5M9.5 2.5 5.5 6.5"
                      stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
    <?php endif; ?>
</span>
