<?php
declare(strict_types=1);

use App\Auth;
use App\Controllers\Admin\AdminDepositController;
use App\Controllers\WalletController;
use App\Lib\Fmt;
use App\Lib\View;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="font-display text-3xl text-ink-100">Deposits</h1>
        <p class="mt-1 text-sm text-ink-500">
            Crediting happens automatically in the webhook. Manual credit is for the cases the
            webhook deliberately refuses &mdash; underpayments and deliveries that never arrived.
        </p>
    </div>

    <nav class="flex flex-wrap gap-1" aria-label="Deposit filter">
        <?php foreach (['all', 'pending', 'credited', 'underpaid', 'expired', 'failed'] as $value): ?>
            <a href="/admin/deposits?status=<?= Fmt::e($value) ?>"
               class="rounded-md px-2.5 py-1.5 text-sm <?= $status === $value ? 'bg-ink-800 text-ink-100' : 'text-ink-400 hover:text-ink-100' ?>">
                <?= Fmt::e($value) ?>
                <?php if ($value !== 'all' && ($counts[$value] ?? 0) > 0): ?>
                    <span class="price text-xs text-ink-600"><?= (int) $counts[$value] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if ($stuckWebhooks !== []): ?>
    <section class="card mt-6 border-rose-600">
        <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-rose-400">
            Accepted but unprocessed webhooks
        </h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Received</th><th scope="col">Event</th><th scope="col">Error</th></tr></thead>
                <tbody>
                <?php foreach ($stuckWebhooks as $hook): ?>
                    <tr>
                        <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::relative((string) $hook['received_at'])) ?></td>
                        <td class="ident"><?= Fmt::e((string) $hook['event_type']) ?></td>
                        <td class="text-rose-400"><?= Fmt::e((string) ($hook['process_error'] ?? 'no error recorded')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<div class="card mt-6">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th scope="col">Date</th><th scope="col">User</th><th scope="col">Requested</th>
                <th scope="col">Credited</th><th scope="col">Tx</th><th scope="col">Status</th><th scope="col"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($deposits as $deposit): ?>
                <?php
                $s = (string) $deposit['status'];
                $txid = is_string($deposit['txid'] ?? null) ? (string) $deposit['txid'] : '';
                ?>
                <tr>
                    <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::date((string) $deposit['created_at'])) ?></td>
                    <td class="ident max-w-[13rem] truncate">
                        <a href="/admin/users/<?= (int) $deposit['user_id'] ?>" class="text-ink-200 hover:text-ember-500">
                            <?= Fmt::e((string) $deposit['user_email']) ?>
                        </a>
                    </td>
                    <td class="price"><?= Fmt::e(Fmt::money((int) $deposit['amount_minor_requested'])) ?></td>
                    <td class="price <?= $deposit['amount_minor_credited'] !== null ? 'text-mint-400' : 'text-ink-600' ?>">
                        <?= $deposit['amount_minor_credited'] !== null
                            ? Fmt::e(Fmt::money((int) $deposit['amount_minor_credited']))
                            : Fmt::e(Fmt::EM_DASH) ?>
                    </td>
                    <td><?= View::partial('partials/ident', [
                            'value' => $txid, 'head' => 6, 'tail' => 5,
                            'label' => 'transaction id', 'href' => AdminDepositController::explorerTx($txid),
                        ]) ?></td>
                    <td>
                        <span class="badge <?= $s === 'credited' ? 'badge-ok' : ($s === 'underpaid' ? 'badge-pending' : ($s === 'pending' ? 'badge-pending' : 'badge-muted')) ?>">
                            <?= Fmt::e(WalletController::statusLabel($s)) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($s !== 'credited'): ?>
                            <details>
                                <summary class="cursor-pointer text-xs text-ember-500">Credit manually</summary>
                                <form method="post" action="/admin/deposits/<?= (int) $deposit['id'] ?>/credit"
                                      class="mt-2 w-56 space-y-2">
                                    <?= Auth::csrfField() ?>
                                    <input class="field field-mono text-xs" type="text" name="amount"
                                           placeholder="<?= Fmt::e(Fmt::money((int) $deposit['amount_minor_requested'], false)) ?>"
                                           inputmode="decimal"
                                           aria-label="Amount to credit, blank for the requested amount">
                                    <input class="field text-xs" type="text" name="reason" required minlength="5"
                                           placeholder="Reason" aria-label="Reason">
                                    <button class="btn btn-secondary btn-sm w-full" type="submit">Credit</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($deposits === []): ?>
                <tr><td colspan="7" class="py-12 text-center text-ink-500">No deposits in this view.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
