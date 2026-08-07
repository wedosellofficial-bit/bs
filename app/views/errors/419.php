<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Session expired</title>
<style>
  body{background:#08080a;color:#e8e8ef;font:15px/1.6 ui-sans-serif,system-ui,sans-serif;
       display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}
  .box{max-width:30rem;text-align:center}
  h1{font-size:1.5rem;margin:0 0 .5rem;font-weight:500}
  p{color:#a8a8b8;margin:0 0 1.5rem}
  a{display:inline-block;background:#f2a03d;color:#1a1206;padding:.65rem 1.25rem;
    border-radius:.5rem;text-decoration:none;font-weight:600}
</style>
</head><body>
<div class="box">
    <h1>Your session expired</h1>
    <p>
        For your security, the form you submitted is no longer valid. This usually means the
        page was open for a while. Nothing was changed &mdash; go back, reload, and try again.
    </p>
    <a href="/">Back to the store</a>
</div>
</body></html>
