<?php
declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $entries
 * @var int $balance @var int $page @var int $pages @var int $total
 */

use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    <a href="/account/wallet" class="text-sm text-ink-500 hover:text-ink-200">&larr; Wallet</a>

    <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl text-ink-100">Statement</h1>
            <p class="mt-1 text-sm text-ink-500">
                <?= Fmt::e(number_format($total)) ?> entries. This ledger is append-only &mdash;
                corrections appear as new lines, never as edits.
            </p>
        </div>
        <div class="text-right">
            <p class="text-xs uppercase tracking-wider text-ink-500">Balance</p>
            <p class="price text-3xl text-ink-100"><?= Fmt::e(Fmt::money($balance)) ?></p>
        </div>
    </div>

    <div class="card mt-6">
        <?php if ($entries === []): ?>
            <p class="px-5 py-14 text-center text-sm text-ink-500">No entries yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Description</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="text-right">Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php $amount = (int) $entry['amount_minor']; ?>
                        <tr>
                            <td class="whitespace-nowrap text-ink-400">
                                <time datetime="<?= Fmt::e(Fmt::iso((string) $entry['created_at'])) ?>">
                                    <?= Fmt::e(Fmt::dateTime((string) $entry['created_at'])) ?>
                                </time>
                            </td>
                            <td>
                                <?= Fmt::e((string) ($entry['memo'] ?? Fmt::EM_DASH)) ?>
                                <?php if (($entry['reference_type'] ?? null) === 'order' && $entry['reference_id'] !== null): ?>
                                    <a href="/account/orders/<?= (int) $entry['reference_id'] ?>"
                                       class="ml-1 text-xs text-ember-500 hover:underline">order</a>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-muted"><?= Fmt::e((string) $entry['type']) ?></span></td>
                            <td class="price text-right <?= $amount >= 0 ? 'text-mint-400' : 'text-ink-200' ?>">
                                <?= Fmt::e(Fmt::moneySigned($amount)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-6 flex items-center justify-center gap-2" aria-label="Statement pages">
            <?php if ($page > 1): ?>
                <a href="/account/wallet/statement?page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm" rel="prev">Previous</a>
            <?php endif; ?>
            <span class="px-3 text-sm text-ink-500">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?>
                <a href="/account/wallet/statement?page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm" rel="next">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
