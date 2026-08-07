<?php
declare(strict_types=1);

use App\Auth;
use App\Lib\Fmt;
?>
<h1 class="font-display text-3xl text-ink-100">Announcements</h1>

<div class="mt-6 grid gap-6 lg:grid-cols-[22rem_1fr]">

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-ink-100">New announcement</h2>

        <form method="post" action="/admin/announcements" class="mt-4 space-y-3">
            <?= Auth::csrfField() ?>

            <div>
                <label class="label" for="title">Title</label>
                <input class="field" type="text" id="title" name="title" required maxlength="160">
            </div>

            <div>
                <label class="label" for="body">Body</label>
                <textarea class="field" id="body" name="body" rows="5" required></textarea>
                <p class="hint mt-1.5">
                    Plain text only. Rendered escaped, so markup will not be interpreted &mdash;
                    an announcement appears on every signed-in dashboard, and HTML here would be
                    stored XSS if an admin account were ever compromised.
                </p>
            </div>

            <div>
                <label class="label" for="level">Level</label>
                <select class="field" id="level" name="level">
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="critical">Critical</option>
                </select>
            </div>

            <div>
                <label class="label" for="expires_at">Expires (optional)</label>
                <input class="field field-mono" type="datetime-local" id="expires_at" name="expires_at">
            </div>

            <label class="check-row">
                <input type="checkbox" name="publish" value="1" checked>
                <span>Publish immediately</span>
            </label>

            <button class="btn btn-primary w-full" type="submit">Save</button>
        </form>
    </section>

    <section class="card">
        <h2 class="border-b border-ink-800 px-5 py-3.5 text-sm font-semibold text-ink-100">Published and drafts</h2>

        <?php if ($items === []): ?>
            <p class="px-5 py-12 text-center text-sm text-ink-500">Nothing yet.</p>
        <?php else: ?>
            <ul class="divide-y divide-ink-800">
                <?php foreach ($items as $announcement): ?>
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-medium text-ink-100"><?= Fmt::e((string) $announcement['title']) ?></h3>
                                    <span class="badge <?= $announcement['level'] === 'critical' ? 'badge-failed' : ($announcement['level'] === 'warning' ? 'badge-pending' : 'badge-muted') ?>">
                                        <?= Fmt::e((string) $announcement['level']) ?>
                                    </span>
                                    <?php if ($announcement['published_at'] === null): ?>
                                        <span class="badge badge-muted">draft</span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-1 whitespace-pre-line text-sm text-ink-400"><?= Fmt::e((string) $announcement['body']) ?></p>
                                <p class="mt-1.5 text-xs text-ink-600">
                                    <?= $announcement['published_at'] !== null
                                        ? Fmt::e('Published ' . Fmt::relative((string) $announcement['published_at']))
                                        : 'Not published' ?>
                                    <?php if (($announcement['author_email'] ?? null) !== null): ?>
                                        &middot; <?= Fmt::e((string) $announcement['author_email']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <form method="post" action="/admin/announcements/<?= (int) $announcement['id'] ?>/delete"
                                  onsubmit="return confirm('Delete this announcement?');">
                                <?= Auth::csrfField() ?>
                                <button class="btn btn-ghost btn-sm shrink-0" type="submit">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
