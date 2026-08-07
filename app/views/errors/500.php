<?php

declare(strict_types=1);

/**
 * Shown when an unhandled exception reaches the top level in production.
 *
 * Deliberately standalone HTML with inline styles: whatever failed might
 * have been the database or the config, so this page cannot depend on
 * the view helpers, the layout, or anything else that could fail again.
 *
 * @var string $reference Correlates with the log line. Safe to show.
 */
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong</title>
<style>
  body{background:#08080a;color:#e8e8ef;font:15px/1.6 ui-sans-serif,system-ui,sans-serif;
       display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}
  .box{max-width:32rem;text-align:center}
  h1{font-size:1.5rem;margin:0 0 .5rem;font-weight:500}
  p{color:#a8a8b8;margin:0 0 1rem}
  code{font-family:ui-monospace,monospace;background:#14141a;border:1px solid #2a2a35;
       padding:.2rem .5rem;border-radius:.3rem;color:#f2a03d;font-size:.85rem}
  a{display:inline-block;margin-top:1rem;background:#f2a03d;color:#1a1206;padding:.65rem 1.25rem;
    border-radius:.5rem;text-decoration:none;font-weight:600}
</style>
</head><body>
<div class="box">
    <h1>Something went wrong</h1>
    <p>
        The error has been logged and nothing has been charged. If you were part-way through a
        purchase or a deposit, check your wallet page before trying again &mdash; it will show the
        current state.
    </p>
    <p>Quote this reference if you contact support:<br>
        <code><?= htmlspecialchars($reference ?? 'unknown', ENT_QUOTES, 'UTF-8') ?></code>
    </p>
    <a href="/">Back to the store</a>
</div>
</body></html>
