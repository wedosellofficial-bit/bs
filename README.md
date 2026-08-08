# Billions Store

A custom PHP storefront for Bitcoin Ordinals inscriptions. Buyers top up a
store balance with BTC and spend it on inscriptions the store holds; an
admin transfers each one by hand from the project wallet.

Built for shared hosting (Hostinger): plain PHP 8.2+, PDO, no framework,
no build step on the server, no long-running processes.

| | |
|---|---|
| **Chain** | Bitcoin Ordinals (inscriptions on sats, no contract address) |
| **Custody** | Store holds the inscriptions, transfers to buyer after purchase |
| **Payment** | Site balance, topped up in BTC |
| **Provider** | Coinbase Commerce |
| **Transfer mode** | Manual — admin queue, no signing key on the server |

---

## The three things worth knowing before you read the code

**1. Money is created in exactly one place.** `Payments::handleWebhook()`,
after an HMAC signature check against the raw request body. Not on a page
load, not on the redirect back from the provider, not from a client-side
callback. The deposit page polls a status endpoint while you wait, and
that endpoint is strictly read-only — it reports what the webhook already
recorded and cannot itself credit anything.

**2. Balance is `SUM(amount_minor)`, every time it matters.** `wallet_entries`
is append-only: no `UPDATE`, no `DELETE`. A refund is a new positive entry
sitting next to the original charge; a correction is an `adjustment`. There
*is* a `wallet_balance_cache`, but it is only ever read for bulk display
(the admin user list), it carries `last_entry_id` so staleness is
detectable, and `bin/reconcile.php` rebuilds it. No money decision reads
the cache.

**3. There is no private key in this codebase.** Transfer mode is manual by
design. An admin claims a queue item, sends the inscription from a wallet
held elsewhere, and pastes the txid back. A full compromise of this host
cannot move a single inscription, because this host cannot sign. That is
the whole security argument for starting manual — automate later behind a
spending cap and an address allowlist, not before.

---

## Layout

```
public_html/            <- the document root, and the ONLY web-reachable part
├── index.php             front controller; the only .php file in here
├── .htaccess             rewrites, security headers, CSP, deny rules
└── assets/               compiled CSS, JS, self-hosted fonts, images

app/                    <- ABOVE the web root
├── config.php            reads ../.env, returns a flat config array
├── bootstrap.php         autoloader, error handling
├── Database.php          PDO singleton, transactions with SAVEPOINT nesting
├── Auth.php              sessions, Argon2id, CSRF, TOTP, guards
├── Wallet.php            the ledger
├── Payments.php          Coinbase Commerce charges + webhook handling
├── Nft.php               catalog, filters, transfer queue
├── Orders.php            the purchase transaction
├── Ordinals.php          taproot payout policy, inscription ids, explorers
├── lib/                  Bech32, Base58Check, Totp, RateLimiter, Mailer,
│                         ImageStore, Router, View, Logger, Config, ...
├── controllers/
├── views/
└── migrations/

bin/                    <- CLI entry points (some also exposed over HTTP)
├── migrate.php           apply migrations      (also /cron/migrate)
├── cron.php              scheduled maintenance (also /cron/run)
├── seed.php              sample data for local development
├── reconcile.php         ledger vs cache reconciliation
└── test.php              self-tests, no dependencies

storage/                <- ABOVE the web root; uploaded media, logs
resources/              <- build sources (Tailwind input, font/JS copy scripts)
```

If `app/` ends up inside `public_html`, the deploy is wrong. The
`.htaccess` has hard deny rules for exactly that case, but they are a
backstop, not the design.

### No Composer dependencies

There are none — zero third-party PHP packages, so there is no `vendor/`
to upload and nothing to `composer install`. Bech32/bech32m, Base58Check,
TOTP and the SMTP client are all written out in `app/lib/` (each is under
150 lines, each is covered by `bin/test.php`).

---

## Local development

```bash
git clone <this repo> && cd bs
cp .env.example .env          # then fill in DB credentials
chmod 600 .env

php -r "echo 'APP_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;"   >> .env
php -r "echo 'CRON_TOKEN=' . bin2hex(random_bytes(24)) . PHP_EOL;"      >> .env

php bin/doctor.php             # confirms the environment can actually run this
php bin/migrate.php
php bin/seed.php              # 60 generated inscriptions, admin + test buyer

php -S 127.0.0.1:8080 -t public_html public_html/index.php
```

Set `MAIL_TRANSPORT=log` locally and verification/reset emails are written
to `storage/logs/mail/` instead of being sent — open the file and click the
link.

### Preflight check

```bash
php bin/doctor.php
```

Checks PHP version and extensions, that `app/`, `storage/` and `.env`
are outside the web root, that the compiled assets exist, that storage is
writable, that `.env` is filled in, and that the database is reachable
with migrations applied. Run it after first setup and again after
uploading to Hostinger — most "it doesn't work" reports are one of these,
and this finds which one in a few seconds instead of one page load at a
time.

### Testing on XAMPP before Hostinger

XAMPP's own document root only serves what is inside `htdocs/`, but this
app needs `app/`, `bin/` and `storage/` to sit *next to* `public_html/`,
not inside it — see [Layout](#layout). Two ways to satisfy that:

**Option A — clone outside `htdocs`, point Apache at `public_html/`.**
Keeps the layout identical to what you will upload to Hostinger, so
nothing behaves differently between local and production.

1. Clone the repo anywhere *outside* `htdocs`, e.g. `C:\dev\billions-store`
   (Windows) or `~/dev/billions-store` (mac/Linux).
2. XAMPP → **Apache → Config → httpd-vhosts.conf**, add:
   ```apache
   <VirtualHost *:8080>
       DocumentRoot "C:/dev/billions-store/public_html"
       <Directory "C:/dev/billions-store/public_html">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   (Adjust the path; on mac/Linux XAMPP it's usually under
   `/opt/lampp/apache2/conf/extra/`.) Also add `Listen 8080` near the top
   of `httpd.conf` if 8080 isn't already listened on.
3. Restart Apache from the XAMPP control panel.
4. Visit `http://localhost:8080/`.

**Option B — clone straight into `htdocs`.** Faster to set up, no vhost
editing, but note the URL includes the folder name:

```
htdocs/
└── billions-store/
    ├── public_html/
    ├── app/
    ├── bin/
    └── storage/
```

Visit `http://localhost/billions-store/public_html/`. This works because
`app/`/`storage/` are still outside `public_html/` — just both under
`billions-store/` rather than directly under `htdocs/`. Do **not** put
`app/` or `storage/` directly inside `htdocs/` itself, or Apache can serve
them.

**Either way, then:**

1. Start **Apache** and **MySQL** from the XAMPP control panel.
2. phpMyAdmin (`http://localhost/phpmyadmin`) → create a database, e.g.
   `billions`. XAMPP's default MySQL user is `root` with **no password**.
3. `cp .env.example .env`, then set:
   ```
   APP_ENV=development
   APP_URL=http://localhost:8080          (or .../billions-store/public_html for Option B)
   DB_HOST=127.0.0.1
   DB_NAME=billions
   DB_USER=root
   DB_PASS=
   MAIL_TRANSPORT=log
   ```
   `APP_ENV=development` matters here specifically: with it set to
   `production` (or left blank), a broken page shows only a generic
   "Something went wrong" screen with a reference code — correct behaviour
   for a live store, useless while you're debugging. In development the
   real exception, file and line print directly to the page.
4. `php bin/doctor.php` — if XAMPP's PHP isn't on your PATH, run it via
   XAMPP's own binary instead (Windows: `C:\xampp\php\php.exe bin\doctor.php`;
   mac: `/Applications/XAMPP/xamppfiles/bin/php bin/doctor.php`).
5. `php bin/migrate.php` then `php bin/seed.php`.
6. Reload the site.

If something still won't load, `storage/logs/app-YYYY-MM-DD.log` has the
exact error and reference code even when `APP_ENV=production` hides it
from the browser.

### Front-end build (local only)

```bash
npm install
npm run build     # fonts + qrcode bundle + Tailwind, all into public_html/assets
```

The **output is committed** because Hostinger cannot run npm. Re-run
`npm run build` and commit the result whenever you change a template's
classes.

- `npm run css` — Tailwind v4, scanning `app/views`
- `npm run fonts` — copies woff2 out of `@fontsource/*`
- `npm run vendor` — esbuild-bundles the `qrcode` package into an IIFE

Everything is self-hosted deliberately. The wallet page displays a deposit
address; a CDN font or a third-party QR image URL would hand that address
(and the referrer identifying this store) to someone else's access log. The
CSP in `.htaccess` blocks external origins outright, so a stray CDN tag
fails loudly rather than silently leaking.

### Tests

```bash
php bin/test.php
```

106 assertions over the logic that must not be wrong: bech32/bech32m
against the published BIP-173 and BIP-350 vectors, taproot payout policy,
Base58Check, inscription id parsing, money parsing and BTC/satoshi
round-tripping, webhook signature verification, and deposit fee
arithmetic. No PHPUnit — it runs on a bare PHP install.

---

## Deploying to Hostinger

### 1. Database

hPanel → **Databases → MySQL Databases**. Create a database and a user,
note the credentials. Hostinger prefixes both with your account id
(`u123456789_`).

### 2. Upload

Over FTP/SFTP or the hPanel File Manager, so the account root looks like:

```
/home/u123456789/
├── public_html/      <- contents of public_html/ from this repo
├── app/
├── bin/
├── storage/
└── .env
```

`app/`, `bin/`, `storage/` and `.env` are **siblings of** `public_html`,
never inside it. Skip `node_modules/` and `resources/` — neither is needed
at runtime.

### 3. Permissions

```
.env                 600
app/  bin/           755 dirs, 644 files
storage/             755   (must be writable by PHP)
storage/nft/         755
storage/logs/        755
```

### 4. Configure

Copy `.env.example` to `.env` and fill in:

- `APP_ENV=production`, `APP_URL=https://your-domain`
- `APP_KEY` — `base64_encode(random_bytes(32))`
- `DB_*` — from step 1
- `COINBASE_COMMERCE_API_KEY` and `COINBASE_COMMERCE_WEBHOOK_SECRET`
- `MAIL_*` — a mailbox created in hPanel → Emails
- `CRON_TOKEN` — `bin2hex(random_bytes(24))`

Set PHP to 8.2 or newer in hPanel → **PHP Configuration**, with `pdo_mysql`,
`gd`, `curl`, `mbstring`, `openssl` and `sodium` enabled (all are on by
default on current Hostinger plans).

### 5. Run migrations

With SSH (Business plans and up):

```bash
php bin/migrate.php
php bin/migrate.php --status
```

Without SSH, the same runner is exposed over HTTP behind the cron token:

```
https://your-domain/cron/migrate?token=YOUR_CRON_TOKEN
https://your-domain/cron/migrate?token=YOUR_CRON_TOKEN&status=1
```

It returns JSON. Without a valid token it returns 404 — the endpoint does
not announce itself.

### 6. Create the first admin

Either run `php bin/seed.php` on a staging copy, or register normally
through the site and then promote yourself:

```sql
UPDATE users SET role = 'admin', email_verified_at = UTC_TIMESTAMP()
 WHERE email = 'you@example.com';
```

### 7. Cron

hPanel → **Advanced → Cron Jobs**. Add a job that fetches the maintenance
endpoint. Every 15 minutes is a reasonable cadence:

```
*/15 * * * *  curl -fsS "https://your-domain/cron/run?token=YOUR_CRON_TOKEN" > /dev/null
```

If your plan's cron only offers a PHP command rather than a shell command:

```
*/15 * * * *  /usr/bin/php /home/u123456789/bin/cron.php
```

The job does all of this, each task isolated so one failure does not stop
the rest:

| Task | What it does |
|---|---|
| `expired_quotes` | Marks abandoned deposit quotes expired |
| `released_claims` | Frees transfer claims an admin walked away from (30 min) |
| `pruned_rate_limits` | Drops rate-limit rows outside the longest window |
| `pruned_tokens` | Deletes verification/reset tokens expired over 7 days ago |
| `pruned_webhook_payloads` | Clears webhook bodies after 90 days (keeps the event ids) |
| `reconciliation` | Ledger vs cached balance; logs drift and any negative balance |

Everything is idempotent and safe to run more often than scheduled.

### 8. Register the webhook

Coinbase Commerce dashboard → **Settings → Webhook subscriptions**:

```
Endpoint URL:  https://your-domain/webhooks/coinbase
```

Then **Show shared secret** and put that value in
`COINBASE_COMMERCE_WEBHOOK_SECRET`. It is not the API key — the handler
verifies `X-CC-Webhook-Signature` as `HMAC-SHA256(raw_body, shared_secret)`,
and with the wrong secret every delivery is rejected with a 400.

Subscribe to at least:

- `charge:pending` — payment seen, records the txid
- `charge:confirmed` and `charge:resolved` — settles and credits
- `charge:failed`
- `charge:delayed` — underpaid or paid late; flagged for review, never
  auto-credited

Test it with the dashboard's "Send test webhook", then check
**Admin → Deposits** — accepted-but-unprocessed deliveries are listed at
the top of that screen, because each one is a payment the provider thinks
it told you about.

### 9. Check the deploy

- `https://your-domain/.env` → 403 or 404, **never** file contents
- `https://your-domain/app/config.php` → 403 or 404
- `https://your-domain/` → the storefront
- **Admin → Overview** → reconciliation panel populates after the first
  cron run. If it stays empty, cron is not firing.

---

## Operating it

### Selling something

1. **Admin → Inventory → Add inscription.** Paste the inscription id
   (`<64-hex reveal txid>i<index>`), a price, and traits as JSON. Upload
   an image — it is validated by magic bytes, re-encoded through GD to
   strip metadata and payloads, and stored outside the web root.
2. **Recompute rarity** once the collection is complete. Rarity is stored
   and indexed so it can be a sort option.
3. A buyer purchases; the order lands in **Admin → Transfer queue**.

### Sending a transfer

1. **Claim** the item. The claim is conditional on the row still being
   unclaimed, so two admins clicking at once produce one winner — not two
   people both broadcasting the same inscription.
2. Send it from the project wallet, using your own wallet software.
3. Paste the txid back and **Record as sent**. It is validated as a
   64-character hex hash.
4. **Mark confirmed** once it settles. The order closes, the item becomes
   `transferred`, and the buyer is emailed.

If something goes wrong, **Report a problem** with a reason. The reason is
shown to whoever picks it up next, and to the buyer on their order page.
Refund from **Admin → Orders** if the buyer should get their money back.

### When a deposit needs a human

`underpaid` covers both underpayments and payments that arrived after the
quote expired. Neither is auto-credited: the amount may not match the
quote, and crediting a stale rate automatically is a dispute waiting to
happen. **Admin → Deposits → Credit manually** takes an amount and a
reason, and goes through the same wallet lock and the same uniqueness
constraint as the automatic path — so it cannot double-credit a deposit
the webhook later resolves.

---

## Security notes

Beyond the three points at the top:

- **Webhooks** — signature verified against the raw body before parsing.
  Idempotency is layered: `UNIQUE(provider, event_id)` on `webhook_events`,
  `UNIQUE(provider, provider_charge_id)` on `deposits` (re-read
  `FOR UPDATE` before crediting), and
  `UNIQUE(type, reference_type, reference_id)` on `wallet_entries`. Any one
  would usually do; all three are cheap, and the failure they prevent is
  "we gave away money and found out at reconciliation".
- **Purchases** — two different races, closed separately.
  `Wallet::withUserLock()` serialises per buyer so one balance cannot fund
  two purchases; a `SELECT ... FOR UPDATE` on the item row plus
  `UNIQUE(nft_id)` on `orders` stops two buyers taking the same
  inscription. Lock order is always user-then-item.
- **CSRF** — enforced in the router for every POST/PUT/PATCH/DELETE, with
  an explicit exemption list holding only the two endpoints that have no
  session (the webhook, authenticated by HMAC; cron, by bearer token). A
  per-controller check eventually gets forgotten on exactly one form.
- **Payout addresses** — full bech32m checksum validation, taproot only.
  A legacy or bc1q address is rejected with an error that says what to use
  instead, because "invalid address" reads like a typo and invites a
  second attempt at the same wrong thing.
- **Passwords** — Argon2id, explicit cost parameters (64 MiB / 4 passes),
  transparent rehash on login when the parameters change.
- **Uploads** — magic-byte type detection, GD re-encode, stored outside
  the web root, served through a PHP handler. SVG is rejected outright.
- **Rate limits** — sliding window (row per attempt, not a resettable
  counter): login 5/15min per IP+email plus 30/15min per IP, registration,
  password reset, top-up address generation, purchases, 2FA codes.
- **Sessions** — httponly, secure, SameSite=Lax, id regenerated on login,
  idle *and* absolute timeouts.
- **Admin** — `Auth::requireAdmin()` on every admin route, returning 404
  rather than 403 so the admin area does not announce itself.
- **Logging** — every ledger write, admin action and failed login. The
  logger redacts secrets by key name and middle-truncates addresses and
  txids, so it refuses to write them rather than relying on everyone
  remembering not to.

### Known trade-offs

- **Manual transfers** mean a delivery delay of up to a business day. That
  is the deliberate cost of having no signing key on the host.
- **Balances are not withdrawable** through the UI. Store credit is for
  buying; refunds go back to balance, and cashing out is a support
  conversation.
- **Facet counts** in the filter rail are computed against the status
  filter only, not the full filter set. Recomputing every facet against
  every other selection is a much heavier query, and the usual expectation
  is that an unselected option shows its standalone count.
- **`terms.php` and `privacy.php` are templates.** Have a lawyer in your
  jurisdiction review them before taking real money.
