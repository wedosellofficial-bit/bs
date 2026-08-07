<?php
declare(strict_types=1);

/** @var list<array<string,mixed>> $users @var array<int,array{balance_minor:int,stale:bool}> $balances @var string $search */

use App\Lib\Fmt;
?>
<div class="flex flex-wrap items-end justify-between gap-4">
    <h1 class="font-display text-3xl text-ink-100">Users</h1>

    <form method="get" action="/admin/users" class="flex gap-2">
        <label class="sr-only-focusable" for="q">Search by email</label>
        <input class="field w-56 text-sm" type="search" id="q" name="q" placeholder="Search email"
               value="<?= Fmt::e($search) ?>">
        <button class="btn btn-secondary btn-sm" type="submit">Search</button>
    </form>
</div>

<div class="card mt-6">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th scope="col">User</th>
                <th scope="col">Balance</th>
                <th scope="col">Status</th>
                <th scope="col">2FA</th>
                <th scope="col">Joined</th>
                <th scope="col">Last seen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php $balance = $balances[(int) $user['id']] ?? ['balance_minor' => 0, 'stale' => false]; ?>
                <tr>
                    <td>
                        <a href="/admin/users/<?= (int) $user['id'] ?>" class="ident text-ink-100 hover:text-ember-500">
                            <?= Fmt::e((string) $user['email']) ?>
                        </a>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="badge badge-listed ml-1">admin</span>
                        <?php endif; ?>
                        <?php if ($user['email_verified_at'] === null): ?>
                            <span class="badge badge-pending ml-1">unverified</span>
                        <?php endif; ?>
                    </td>
                    <td class="price"><?= Fmt::e(Fmt::money($balance['balance_minor'])) ?></td>
                    <td>
                        <span class="badge <?= $user['status'] === 'active' ? 'badge-ok' : 'badge-failed' ?>">
                            <?= Fmt::e((string) $user['status']) ?>
                        </span>
                    </td>
                    <td class="text-ink-400"><?= $user['twofa_confirmed_at'] !== null ? 'on' : Fmt::e(Fmt::EM_DASH) ?></td>
                    <td class="whitespace-nowrap text-ink-400"><?= Fmt::e(Fmt::date((string) $user['created_at'])) ?></td>
                    <td class="whitespace-nowrap text-ink-400">
                        <?= $user['last_login_at'] !== null ? Fmt::e(Fmt::relative((string) $user['last_login_at'])) : Fmt::e(Fmt::EM_DASH) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
