<?php

declare(strict_types=1);

/** @var string $type @var string $message */

use App\Lib\Fmt;

$class = match ($type ?? 'info') {
    'success' => 'flash-success',
    'error'   => 'flash-error',
    default   => 'flash-info',
};

$icon = match ($type ?? 'info') {
    'success' => 'M3.5 8.5 6.5 11.5 12.5 5',
    'error'   => 'M8 4.5v4.5M8 11.5h.01',
    default   => 'M8 7.5v4M8 4.5h.01',
};
?>
<div class="flash <?= Fmt::e($class) ?>" role="<?= ($type ?? '') === 'error' ? 'alert' : 'status' ?>">
    <svg class="mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <?php if (($type ?? '') !== 'success'): ?>
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/>
        <?php endif; ?>
        <path d="<?= Fmt::e($icon) ?>" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <p><?= Fmt::e($message ?? '') ?></p>
</div>
