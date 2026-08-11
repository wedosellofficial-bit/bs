<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $posts */

use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">News</h1>

    <?php if ($posts === []): ?>
        <p class="mt-6 text-ink-400">Nothing posted yet. Check back soon.</p>
    <?php else: ?>
        <div class="mt-8 space-y-6">
            <?php foreach ($posts as $post): ?>
                <?php
                $badge = match ((string) $post['level']) {
                    'critical' => 'badge-failed',
                    'warning'  => 'badge-pending',
                    default    => 'badge-muted',
                };
                ?>
                <article class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-display text-xl text-ink-100"><?= Fmt::e((string) $post['title']) ?></h2>
                        <span class="badge <?= Fmt::e($badge) ?>"><?= Fmt::e((string) $post['level']) ?></span>
                    </div>
                    <p class="mt-1 text-xs text-ink-500"><?= Fmt::e(Fmt::date((string) $post['published_at'])) ?></p>
                    <p class="mt-3 whitespace-pre-line leading-relaxed text-ink-300"><?= Fmt::e((string) $post['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
