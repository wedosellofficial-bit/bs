-- Rate limiting, auditing, and webhook receipts.

-- One row per attempt, counted within a sliding window.
--
-- A row-per-attempt table rather than a counter column: a counter with a
-- window-start timestamp resets the entire window when it rolls over, so
-- an attacker who waits for the boundary gets a full fresh allowance
-- every window. Counting rows in the last N seconds is a true sliding
-- window. Old rows are pruned by cron.
--
-- The subject is stored hashed: the login limiter keys on IP + email, and
-- an unauthenticated visitor's email address should not accumulate in a
-- table in clear just because they mistyped a password.
CREATE TABLE rate_limit_hits (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket       VARCHAR(40)     NOT NULL,
    subject_hash CHAR(64)        NOT NULL,
    created_at   DATETIME        NOT NULL,

    PRIMARY KEY (id),
    KEY idx_rate_limit_lookup (bucket, subject_hash, created_at),
    KEY idx_rate_limit_prune (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Audit trail: every ledger write, every admin action, every failed
-- login. Written by App\Lib\Logger::audit(), which redacts secrets and
-- middle-truncates on-chain identifiers before anything lands here.
CREATE TABLE audit_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- NULL for system-originated events (webhooks, cron).
    actor_user_id INT UNSIGNED        DEFAULT NULL,

    event         VARCHAR(60)     NOT NULL,
    message       VARCHAR(500)    NOT NULL,

    subject_type  VARCHAR(32)         DEFAULT NULL,
    subject_id    BIGINT UNSIGNED     DEFAULT NULL,

    ip_address    VARCHAR(45)         DEFAULT NULL,
    user_agent    VARCHAR(255)        DEFAULT NULL,
    context       JSON                DEFAULT NULL,

    created_at    DATETIME        NOT NULL,

    PRIMARY KEY (id),
    KEY idx_audit_event_created (event, created_at),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_subject (subject_type, subject_id),

    -- Deliberately ON DELETE SET NULL rather than CASCADE: deleting a user
    -- must not erase the record of what that user did.
    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Every webhook delivery we accept, keyed by the provider's event id.
--
-- This is the outermost idempotency layer. The handler inserts here
-- first; a duplicate key means "already seen, acknowledge and stop", so a
-- replayed delivery does no work at all rather than relying on downstream
-- checks to notice.
--
-- Rejected deliveries (bad signature) are recorded too, without the body,
-- because a sudden run of them is worth seeing.
CREATE TABLE webhook_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider      VARCHAR(32)     NOT NULL DEFAULT 'coinbase_commerce',
    event_id      VARCHAR(100)    NOT NULL,
    event_type    VARCHAR(60)     NOT NULL,

    signature_ok  TINYINT(1)      NOT NULL DEFAULT 0,

    -- Raw body, retained for reconciliation against the provider's
    -- dashboard. Cleared after 90 days by the cron sweep.
    payload       MEDIUMTEXT          DEFAULT NULL,

    received_at   DATETIME        NOT NULL,
    processed_at  DATETIME            DEFAULT NULL,
    process_error VARCHAR(500)        DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_provider_event (provider, event_id),
    KEY idx_webhook_received (received_at),
    KEY idx_webhook_unprocessed (processed_at, received_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
