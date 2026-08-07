<?php
declare(strict_types=1);

use App\Lib\Fmt;
?>
<h1 class="font-display text-3xl text-ink-100">Overview</h1>

<?php if ($failedWebhooks !== []): ?>
    <div class="flash flash-error mt-6">
        <div>
            <p class="font-medium"><?= count($failedWebhooks) ?> webhook deliveries were accepted but not processed.</p>
            <p class="mt-1 text-sm opacity-90">
                Each one is a payment the provider believes it told us about. Check the
                <a href="/admin/deposits" class="underline">deposits screen</a> and credit manually if needed.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if (($queueCounts['pending'] ?? 0) > 0 || ($queueCounts['failed'] ?? 0) > 0): ?>
    <div class="flash flash-info mt-6">
        <div>
            <p class="font-medium">
                <?= (int) $queueCounts['pending'] ?> transfer<?= $queueCounts['pending'] === 1 ? '' : 's' ?> waiting to be sent<?php
                if (($queueCounts['failed'] ?? 0) > 0): ?>, <?= (int) $queueCounts['failed'] ?> failed<?php endif; ?>.
            </p>
            <a href="/admin/transfers" class="mt-1 inline-block text-sm underline">Open the queue</a>
        </div>
    </div>
<?php endif; ?>

<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?php
    $tiles = [
        ['Revenue', Fmt::money((int) $orderStats['revenue_minor']), 'excluding refunds'],
        ['Orders', number_format((int) $orderStats['total']), (int) $orderStats['open_orders'] . ' still open'],
        ['Listed', number_format((int) ($inventory['listed'] ?? 0)), number_format((int) ($inventory['total'] ?? 0)) . ' in inventory'],
        ['Users', number_format($userCount), number_format((int) ($deposits['pending'] ?? 0)) . ' deposits pending'],
    ];
    foreach ($tiles as [$label, $value, $sub]):
        ?>
        <div class="card p-5">
            <p class="text-xs uppercase tracking-wider text-ink-500"><?= Fmt::e($label) ?></p>
            <p class="price mt-1 text-2xl text-ink-100"><?= Fmt::e($value) ?></p>
            <p class="mt-1 text-xs text-ink-600"><?= Fmt::e($sub) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">

    <section class="card">
        <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-ink-100">Ledger reconciliation</h2>
        <div class="p-5">
            <?php if ($lastReconciliation === null): ?>
                <p class="text-sm text-ink-500">
                    Has not run yet. It runs nightly from the cron endpoint &mdash; if this stays
                    empty, the cron job is not configured.
                </p>
            <?php else: ?>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Last run</dt>
                        <dd class="text-ink-200"><?= Fmt::e(Fmt::relative((string) $lastReconciliation['started_at'])) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Users checked</dt>
                        <dd class="price text-ink-200"><?= (int) $lastReconciliation['users_checked'] ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Cache drift</dt>
                        <dd class="price <?= (int) $lastReconciliation['drift_count'] > 0 ? 'text-amber-400' : 'text-mint-400' ?>">
                            <?= (int) $lastReconciliation['drift_count'] ?>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-500">Negative balances</dt>
                        <dd class="price <?= (int) $lastReconciliation['negative_balances'] > 0 ? 'text-rose-400' : 'text-mint-400' ?>">
                            <?= (int) $lastReconciliation['negative_balances'] ?>
                        </dd>
                    </div>
                </dl>

                <?php if ((int) $lastReconciliation['negative_balances'] > 0): ?>
                    <p class="mt-3 text-sm text-rose-400">
                        A negative balance should be impossible. Investigate before taking more orders.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-ink-100">Recent activity</h2>
        <ul class="divide-y divide-ink-800">
            <?php foreach ($recentAudit as $entry): ?>
                <li class="px-5 py-2.5">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="ident text-xs text-ember-500"><?= Fmt::e((string) $entry['event']) ?></span>
                        <span class="shrink-0 text-xs text-ink-600"><?= Fmt::e(Fmt::relative((string) $entry['created_at'])) ?></span>
                    </div>
                    <p class="mt-0.5 text-sm text-ink-300"><?= Fmt::e((string) $entry['message']) ?></p>
                    <?php if (($entry['actor_email'] ?? null) !== null): ?>
                        <p class="text-xs text-ink-600"><?= Fmt::e((string) $entry['actor_email']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
