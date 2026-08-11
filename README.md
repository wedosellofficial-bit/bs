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
| **Provider** | Manual — one operator-held address, credited by hand (Coinbase Commerce built in but disabled) |
| **Transfer mode** | Manual — admin queue, no signing key on the server |

---

## The three things worth knowing before you read the code

**1. Deposits are manual, and money is credited in exactly one place.**
Coinbase Commerce is disabled - not every country can reach it - so
there is no automatic crediting. The wallet page shows one BTC address
the operator holds, with a QR code; a customer sends BTC to it, and an
admin credits their balance by hand from the customer's page in
`Admin → Users`, after checking the deposit on a block explorer. That
manual credit is the only place a deposit ever becomes balance - it goes
through `Wallet::credit()`, the same call every other credit in the
system uses, so it is indistinguishable on the ledger from any other
credit. (The Coinbase Commerce integration itself, `App\Payments`, is
kept in the codebase but unrouted - see the comment at the top of that
file if a future market makes it usable again.)

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
/ (repo root - deployed as the web root, all of it)
├── index.php             front controller; the only .php file meant to be requested
├── .htaccess             rewrites, security headers, CSP, deny rules
├── assets/               compiled CSS, JS, self-hosted fonts, images - web-reachable
│
├── app/                   application source - NOT reachable, see below
│   ├── .htaccess           Require all denied
│   ├── config.php          reads ../.env, returns a flat config array
│   ├── bootstrap.php       autoloader, error handling
│   ├── Database.php        PDO singleton, transactions with SAVEPOINT nesting
│   ├── Auth.php            sessions, Argon2id, CSRF, TOTP, guards
│   ├── Wallet.php          the ledger
│   ├── Payments.php        Coinbase Commerce charges + webhooks (disabled, see file header)
│   ├── Nft.php             catalog, filters, transfer queue
│   ├── Orders.php          the purchase transaction
│   ├── Ordinals.php        taproot payout policy, inscription ids, explorers
│   ├── lib/                Bech32, Base58Check, Totp, RateLimiter, Mailer,
│   │                       ImageStore, Router, View, Logger, Config, ...
│   ├── controllers/
│   ├── views/
│   └── migrations/
│
├── bin/                   CLI entry points - NOT reachable
│   ├── .htaccess           Require all denied
│   ├── migrate.php         apply migrations      (also /cron/migrate)
│   ├── cron.php            scheduled maintenance (also /cron/run)
│   ├── seed.php            sample data for local development
│   ├── doctor.php          preflight check - run this after every deploy
│   ├── reconcile.php       ledger vs cache reconciliation
│   └── test.php            self-tests, no dependencies
│
├── storage/               uploaded media, logs - NOT reachable
│   └── .htaccess           Require all denied
│
├── resources/             build sources (Tailwind input, font/JS copy scripts) - NOT reachable, not needed at runtime
│   └── .htaccess           Require all denied
│
└── .env                   never committed, created on the server directly
```

Everything above is deployed as one tree, on purpose. Some hosting
deploy tools - Hostinger's "deploy from GitHub" among them - clone a
repository straight into the document root, with no option to keep part
of it outside. There is no *filesystem* boundary here putting `app/` or
`storage/` beyond what Apache can reach, the way a manual two-tier
upload (`app/` as a sibling of a separate `public_html/`) would give
you.

The boundary is enforced entirely by `.htaccess` instead, in two
independent layers:

1. `app/.htaccess`, `bin/.htaccess`, `storage/.htaccess` and
   `resources/.htaccess` each carry `Require all denied` - Apache's
   access-control directive, enforced before any rewriting or content
   handling runs.
2. The root `.htaccess` also hard-blocks the same four directories (and
   every dotfile, `.env` included) at the rewrite level, so losing one
   of the four files above by accident does not silently reopen it.

Both layers are verified in this repository against a real Apache
instance, not assumed from reading the directives: every path under
`app/`, `bin/`, `storage/` and `resources/` returns 403, `.env` returns
403, and static assets under `assets/` are still served directly. `php
bin/doctor.php` checks that all four `.htaccess` files exist and contain
the deny rule on every environment you run it in, including after a
fresh deploy.

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

php -S 127.0.0.1:8080 index.php
```

Note that PHP's built-in server, run this way, does not read `.htaccess`
at all - so it will happily serve `/app/config.php` or `/.env` directly,
which real Apache would refuse. That is expected for local development
and is not a gap in the app: the protection is Apache's `.htaccess`
mechanism specifically, which only Apache enforces. If you want to
verify the deny rules themselves rather than just the application logic,
run a real Apache instance against the repo root with `AllowOverride
All`, which is exactly how this was verified before release.

Set `MAIL_TRANSPORT=log` locally and verification/reset emails are written
to `storage/logs/mail/` instead of being sent — open the file and click the
link.

### Preflight check

```bash
php bin/doctor.php
```

Checks PHP version and extensions, that `index.php` and the root
`.htaccess` are present, that `app/`, `bin/`, `storage/` and `resources/`
each have their own `.htaccess` with a deny rule, that the compiled
assets exist, that storage is writable, that `.env` is filled in, and
that the database is reachable with migrations applied. Run it after
first setup and again after uploading to Hostinger — most "it doesn't
work" reports are one of these, and this finds which one in a few
seconds instead of one page load at a time.

### Testing on XAMPP before Hostinger

Since the whole repo deploys as one tree (see [Layout](#layout)), XAMPP
setup is just "put the repo where Apache can see it":

1. Clone the repo *into* `htdocs/`, e.g. `htdocs/billions-store/`, so
   `htdocs/billions-store/index.php` exists directly.
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. phpMyAdmin (`http://localhost/phpmyadmin`) → create a database, e.g.
   `billions`. XAMPP's default MySQL user is `root` with **no password**.
4. `cp .env.example .env` at the repo root (next to `index.php`), then set:
   ```
   APP_ENV=development
   APP_URL=http://localhost/billions-store
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
5. `php bin/doctor.php` — if XAMPP's PHP isn't on your PATH, run it via
   XAMPP's own binary instead (Windows: `C:\xampp\php\php.exe bin\doctor.php`;
   mac: `/Applications/XAMPP/xamppfiles/bin/php bin/doctor.php`).
6. `php bin/migrate.php` then `php bin/seed.php`.
7. Visit `http://localhost/billions-store/`.

If the page 404s or shows "not found," it's almost always
`AllowOverride All` missing for that directory in XAMPP's Apache config
(`httpd.conf` / `httpd-xampp.conf`), which stops `.htaccess` — and
therefore the rewrite that routes every request through `index.php` —
from taking effect at all. If it loads but something is broken,
`storage/logs/app-YYYY-MM-DD.log` has the exact error and reference code
even when `APP_ENV=production` hides it from the browser.

### Front-end build (local only)

```bash
npm install
npm run build     # fonts + qrcode bundle + Tailwind, all into assets/
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

Everything in this repository deploys as one tree, straight into
`public_html`, so this works the same way whether you upload manually or
use Hostinger's "deploy from GitHub" auto-deploy:

```
public_html/
├── index.php
├── .htaccess
├── assets/
├── app/            <- has its own .htaccess; not reachable over HTTP
├── bin/            <- has its own .htaccess; not reachable over HTTP
├── storage/        <- has its own .htaccess; not reachable over HTTP
├── resources/       <- not needed at runtime, harmless if it's there
└── .env             <- you create this; never comes from git
```

**Manually (FTP/SFTP or hPanel File Manager):** upload the whole repo
into `public_html`, then create `.env` there directly (step 4).

**Git auto-deploy:** point it at this repository and branch; it clones
straight into `public_html` and this layout is exactly what it expects.
`.env` still will not come from git (it never should - see below), so
create it the same way, in the same place, after the first deploy. If
the deploy tool has an **environment variables** panel instead of a
physical file, use that: `app/config.php` reads real process environment
variables first and falls back to a `.env` file, so either works and the
env-vars route survives a redeploy that wipes the filesystem, where a
manually-placed `.env` file would not.

Either way, skip `node_modules/` if you're doing this by hand - not
needed at runtime, and a manual upload doesn't need to bring it along.

### 3. Permissions

```
.env                 600
app/  bin/  resources/   755 dirs, 644 files
storage/             755   (must be writable by PHP)
storage/nft/         755
storage/logs/        755
```

### 4. Configure

Copy `.env.example` to `.env` and fill in:

- `APP_ENV=production`, `APP_URL=https://your-domain`
- `APP_KEY` — `base64_encode(random_bytes(32))`
- `DB_*` — from step 1
- `MANUAL_BTC_ADDRESS` — a BTC address you control, for customer deposits.
  Any address type works here (this is not the taproot-only rule that
  applies to buyer payout addresses) — use whatever your own wallet gives
  you as its receive address.
- `MAIL_*` — a mailbox created in hPanel → Emails
- `CRON_TOKEN` — `bin2hex(random_bytes(24))`

`COINBASE_COMMERCE_*` can be left blank — that integration is disabled by
default (Coinbase Commerce isn't reachable from every country). See
[Switching payment methods](#switching-payment-methods) below if you want
it back.

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

### 8. Confirm the deposit address is right

There is no webhook to register — deposits are manual. What matters here
is that `MANUAL_BTC_ADDRESS` in `.env` is genuinely an address you
control: `php bin/doctor.php` checks it is *structurally* a valid
Bitcoin address, which catches a typo but cannot catch "valid address
that isn't yours." Send a small test amount to it yourself and confirm
you can see it land in your own wallet before announcing the store is
live — every customer deposit goes to this one address, so a mistake
here affects all of them, not just one order.

### 9. Check the deploy

Run `php bin/doctor.php` over SSH if you have it - it checks all of the
below in one command, including that each of `app/`, `bin/`, `storage/`
and `resources/` actually has its `.htaccess` deny rule in place. If you
don't have SSH, check by hand:

- `https://your-domain/.env` → 403 or 404, **never** file contents
- `https://your-domain/app/config.php` → 403 or 404
- `https://your-domain/bin/seed.php` → 403 or 404
- `https://your-domain/assets/css/app.css` → 200 (this one *should* load)
- `https://your-domain/` → the storefront
- **Admin → Overview** → reconciliation panel populates after the first
  cron run. If it stays empty, cron is not firing.

---

## Operating it

### Selling something

1. **Admin → Inventory → Add inscription.** Paste the inscription id
   (`<64-hex reveal txid>i<index>`), a price, and traits as JSON. Upload
   an image — it is validated by magic bytes, re-encoded through GD to
   strip metadata and payloads, and stored in storage/nft, which denies
   direct access via its own .htaccess.
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

### Crediting a deposit

Every deposit needs a human — there is no automatic path. Once you can
see a customer's transaction to the store's BTC address on a block
explorer with enough confirmations for your comfort, go to
**Admin → Users**, find them, and use **Manual adjustment**: credit
direction, the amount in your ledger currency, and a reason — put the
transaction id in the reason so there is a record of which on-chain
payment this credit corresponds to. It goes through the same
`Wallet::credit()` call as every other credit, inside the same per-user
row lock, so it is exactly as safe as any other ledger write and shows
up on the customer's statement the same way.

There is no separate "deposits" queue to work through — a customer's
own message to you (with their transaction id) is what tells you a
credit is needed.

### Switching payment methods

Manual is the default because Coinbase Commerce isn't reachable from
every country. If that changes for you, or you'd rather run automatic
crediting from the start:

1. Fill in `COINBASE_COMMERCE_API_KEY` and `COINBASE_COMMERCE_WEBHOOK_SECRET`
   in `.env` (see the commented-out section in `.env.example`).
2. Re-add the webhook route in `index.php`:
   `$router->post('/webhooks/coinbase', [WebhookController::class, 'coinbase']);`
   — and restore `app/controllers/WebhookController.php` from git history
   (`git log --all --oneline -- app/controllers/WebhookController.php`
   finds the commit that removed it).
3. `App\Payments` (address generation, webhook handling, idempotency) was
   never removed, only unrouted — it needs no changes.
4. Bring back the "generate address" form and per-deposit status page on
   the wallet screen; `WalletController` and `account/wallet.php` as they
   stand now are the manual-flow versions, from before this repo's git
   history shows the switch to manual.
5. Register the webhook in the Coinbase Commerce dashboard: **Settings →
   Webhook subscriptions**, endpoint `https://your-domain/webhooks/coinbase`,
   subscribe to at least `charge:pending`, `charge:confirmed`,
   `charge:resolved`, `charge:failed`, `charge:delayed`.

Going the other way (automatic → manual) is what this store's config is
already set up for — just leave `COINBASE_COMMERCE_API_KEY` blank and
set `MANUAL_BTC_ADDRESS`, no code changes needed.

---

## Security notes

Beyond the three points at the top:

- **Manual deposits** — the only way a deposit becomes balance is an
  admin's own `Wallet::credit()` call from `Admin → Users`, inside that
  user's row lock. There is no automatic path to audit for double-crediting
  because there is no automatic path at all. (The Coinbase Commerce
  webhook handler is still in the codebase, disabled - its idempotency
  is layered three ways for when/if it's re-enabled: `UNIQUE(provider,
  event_id)` on `webhook_events`, `UNIQUE(provider, provider_charge_id)`
  on `deposits`, and `UNIQUE(type, reference_type, reference_id)` on
  `wallet_entries`.)
- **Purchases** — two different races, closed separately.
  `Wallet::withUserLock()` serialises per buyer so one balance cannot fund
  two purchases; a `SELECT ... FOR UPDATE` on the item row stops two
  buyers taking the same inscription — the second transaction blocks on
  that lock and sees the item already sold once it can proceed. There is
  deliberately no unique constraint on `orders.nft_id` backing this up,
  because that would also forbid ever reselling a refunded item (see
  migration 006). Lock order is always user-then-item.
- **CSRF** — enforced in the router for every POST/PUT/PATCH/DELETE, with
  an explicit exemption list holding only the one endpoint that has no
  session (cron, authenticated by a bearer or query token, not a
  webhook signature - the endpoint that used HMAC signing is currently
  unrouted, see above). A per-controller check eventually gets forgotten
  on exactly one form.
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
