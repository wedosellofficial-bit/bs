<?php

declare(strict_types=1);

use App\Lib\Config;
use App\Lib\Fmt;
?>
<div class="mx-auto w-full max-w-2xl px-4 py-14 sm:px-6 lg:px-8">
    <h1 class="font-display text-5xl text-ink-100">Support</h1>

    <p class="mt-4 leading-relaxed text-ink-300">
        Most questions are answered on the <a href="/faq" class="text-ember-500 hover:underline">FAQ</a> page -
        deposits, purchases, and how transfers work.
    </p>

    <p class="mt-4 leading-relaxed text-ink-300">
        Anything else, including order or account issues, email
        <span class="ident text-ink-200"><?= Fmt::e(Config::string('mail.from_addr')) ?></span>.
        Include your account email and, if it's about an order, the order number.
    </p>
</div>
