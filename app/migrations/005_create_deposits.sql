-- Crypto top-ups.
--
-- `provider_charge_id` carries a UNIQUE constraint, and that constraint is
-- the primary idempotency mechanism for the webhook handler. Coinbase
-- Commerce retries a delivery until it gets a 2xx, and it can deliver the
-- same event more than once even after a success. Without the unique key,
-- every retry of `charge:confirmed` is another credit.
CREATE TABLE deposits (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                 INT UNSIGNED    NOT NULL,

    provider                VARCHAR(32)     NOT NULL DEFAULT 'coinbase_commerce',
    provider_charge_id      VARCHAR(100)    NOT NULL,
    -- Coinbase Commerce's short human code (e.g. "A1B2C3D4"), shown to
    -- the user so they can find the charge in a support conversation.
    provider_charge_code    VARCHAR(40)         DEFAULT NULL,

    -- The address the provider generated for this top-up. Fresh per
    -- request; never reused across deposits.
    address                 VARCHAR(120)        DEFAULT NULL,
    asset                   VARCHAR(10)     NOT NULL DEFAULT 'BTC',

    -- Amount requested, as a decimal string in the asset's own units.
    -- DECIMAL, not FLOAT: 0.1 + 0.2 must be 0.3 when money depends on it.
    amount_crypto           DECIMAL(24,8)       DEFAULT NULL,
    -- Amount actually received, once the provider reports it.
    amount_crypto_received  DECIMAL(24,8)       DEFAULT NULL,

    -- The fiat value the user asked to top up, and the exchange rate we
    -- quoted them, with the moment it was fetched. Stored so a dispute
    -- about "the rate moved" has an answer.
    amount_minor_requested  INT UNSIGNED    NOT NULL,
    quoted_rate             DECIMAL(20,8)       DEFAULT NULL,
    quoted_at               DATETIME            DEFAULT NULL,
    quote_expires_at        DATETIME            DEFAULT NULL,

    -- What we actually credited. NULL until the credit happens. Set in
    -- the same transaction as the ledger entry.
    amount_minor_credited   INT UNSIGNED        DEFAULT NULL,
    fee_minor               INT UNSIGNED    NOT NULL DEFAULT 0,

    confirmations           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    required_confirmations  SMALLINT UNSIGNED NOT NULL DEFAULT 2,

    -- pending   : address issued, nothing seen on-chain
    -- confirmed : provider reports the payment settled
    -- credited  : ledger entry written (terminal, happy path)
    -- expired   : quote window passed with no payment
    -- underpaid : paid, but for less than the quote - needs an admin
    -- failed    : provider reported a terminal failure
    status                  ENUM('pending','confirmed','credited','expired','underpaid','failed')
                            NOT NULL DEFAULT 'pending',

    txid                    VARCHAR(100)        DEFAULT NULL,

    created_at              DATETIME        NOT NULL,
    confirmed_at            DATETIME            DEFAULT NULL,
    credited_at             DATETIME            DEFAULT NULL,

    PRIMARY KEY (id),

    -- The idempotency key. See the comment at the top of this file.
    UNIQUE KEY uniq_deposits_provider_charge (provider, provider_charge_id),

    KEY idx_deposits_user_created (user_id, created_at),
    KEY idx_deposits_status (status),
    -- Used by the cron sweep that expires abandoned quotes.
    KEY idx_deposits_expiry (status, quote_expires_at),

    CONSTRAINT fk_deposits_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Exchange rate quotes, cached.
--
-- The wallet page shows a rate and the timestamp it was fetched. Both come
-- from here, so the displayed timestamp is the real fetch time and not
-- "now" - a rate labelled with the current time is a rate the user cannot
-- audit.
CREATE TABLE exchange_rates (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base        VARCHAR(10)     NOT NULL,
    quote       CHAR(3)         NOT NULL,
    rate        DECIMAL(20,8)   NOT NULL,
    source      VARCHAR(40)     NOT NULL,
    fetched_at  DATETIME        NOT NULL,

    PRIMARY KEY (id),
    KEY idx_exchange_rates_pair (base, quote, fetched_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
