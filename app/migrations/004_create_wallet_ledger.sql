-- The ledger.
--
-- Append-only. There is no UPDATE and no DELETE on this table anywhere in
-- the application: a refund is a new positive entry, a correction is an
-- `adjustment` entry, and a mistake stays visible with its reversal next
-- to it. That property is what makes the statement page trustworthy and
-- what makes a discrepancy investigable at all.
--
-- Balance is always SUM(amount_minor) for a user. Never a column on
-- `users`.
CREATE TABLE wallet_entries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED    NOT NULL,

    -- Signed minor units: positive credits, negative debits. INT is
    -- deliberate and sufficient per entry (+/- 21,474,836.47); SUM() is
    -- promoted to DECIMAL by the server so the aggregate cannot overflow
    -- even across millions of rows.
    amount_minor    INT             NOT NULL,

    currency        CHAR(3)         NOT NULL DEFAULT 'USD',

    type            ENUM('deposit','purchase','refund','adjustment') NOT NULL,

    -- What caused this entry. Polymorphic on purpose: a ledger row must
    -- be traceable to its cause, but the ledger must not carry a foreign
    -- key to every table that can move money, or adding a new cause means
    -- migrating the ledger.
    reference_type  VARCHAR(32)         DEFAULT NULL,
    reference_id    BIGINT UNSIGNED     DEFAULT NULL,

    -- Human-readable line for the statement page.
    memo            VARCHAR(200)        DEFAULT NULL,

    -- Set for admin adjustments and manual credits. NULL means the entry
    -- was created by the system (a webhook, a purchase).
    created_by      INT UNSIGNED        DEFAULT NULL,

    created_at      DATETIME        NOT NULL,

    PRIMARY KEY (id),

    -- The balance query is SUM over one user; this index makes it an
    -- index-only scan.
    KEY idx_wallet_entries_user (user_id, id),
    KEY idx_wallet_entries_user_created (user_id, created_at),
    KEY idx_wallet_entries_reference (reference_type, reference_id),

    -- One ledger entry per (type, reference). This is the second line of
    -- defence for webhook idempotency: even if a replayed delivery got
    -- past the deposits table's unique key, it could not double-credit.
    UNIQUE KEY uniq_wallet_entries_ref (type, reference_type, reference_id),

    CONSTRAINT fk_wallet_entries_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_wallet_entries_admin
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Optional read cache for the balance.
--
-- Separate table, never a column on `users`, and rebuildable from the
-- ledger at any time with `php bin/reconcile.php --rebuild`. It stores
-- `last_entry_id` so a stale cache is *detectable*: if the newest ledger
-- id for a user is ahead of the cached one, the cache is wrong and the
-- reader falls back to SUM().
--
-- A cache you cannot prove wrong is worse than no cache.
CREATE TABLE wallet_balance_cache (
    user_id       INT UNSIGNED    NOT NULL,
    balance_minor BIGINT          NOT NULL,
    last_entry_id BIGINT UNSIGNED NOT NULL,
    entry_count   INT UNSIGNED    NOT NULL,
    rebuilt_at    DATETIME        NOT NULL,

    PRIMARY KEY (user_id),

    CONSTRAINT fk_balance_cache_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Nightly reconciliation results, kept so a drift that appeared once and
-- vanished is still on the record.
CREATE TABLE reconciliation_runs (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at        DATETIME        NOT NULL,
    finished_at       DATETIME            DEFAULT NULL,
    users_checked     INT UNSIGNED    NOT NULL DEFAULT 0,
    drift_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    negative_balances INT UNSIGNED    NOT NULL DEFAULT 0,
    notes             TEXT                DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_reconciliation_started (started_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
