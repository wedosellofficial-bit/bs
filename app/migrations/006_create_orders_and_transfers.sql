-- Orders and the manual transfer queue.

CREATE TABLE orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED    NOT NULL,
    nft_id              INT UNSIGNED    NOT NULL,

    -- Price at the moment of sale, copied rather than joined. If the
    -- listing price changes later the order must still show what was
    -- charged.
    price_minor         INT UNSIGNED    NOT NULL,
    currency            CHAR(3)         NOT NULL DEFAULT 'USD',

    -- paid        : balance debited, order recorded
    -- queued      : in the admin transfer queue
    -- transferring: admin has claimed it and is broadcasting
    -- complete    : transfer confirmed on-chain
    -- failed      : transfer could not be completed; needs refund or retry
    -- refunded    : buyer's balance restored by a compensating entry
    status              ENUM('paid','queued','transferring','complete','failed','refunded')
                        NOT NULL DEFAULT 'paid',

    -- Taproot address supplied and confirmed by the buyer at checkout.
    -- Validated by Ordinals::validatePayoutAddress before it lands here.
    buyer_wallet_address VARCHAR(90)    NOT NULL,

    tx_hash             VARCHAR(100)        DEFAULT NULL,

    created_at          DATETIME        NOT NULL,
    completed_at        DATETIME            DEFAULT NULL,

    PRIMARY KEY (id),

    -- Deliberately NOT unique. An nft can be sold, refunded, and sold
    -- again - each is a real, separate order and all of them stay in
    -- the table, the same way a reversed ledger entry sits next to the
    -- charge it reverses rather than replacing it. What stops the same
    -- inscription being sold twice AT ONCE is Orders::purchase() taking
    -- `SELECT ... FOR UPDATE` on the nft row before checking its status -
    -- a concurrent second purchase blocks on that lock, then sees
    -- status <> 'listed' once it can proceed, and never reaches this
    -- table at all. A unique key here would additionally forbid ever
    -- reselling a refunded item, which is not a race condition, just a
    -- normal thing this store needs to do.
    KEY idx_orders_nft (nft_id),
    KEY idx_orders_user_created (user_id, created_at),
    KEY idx_orders_status (status, created_at),

    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_orders_nft
        FOREIGN KEY (nft_id) REFERENCES nfts (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- The transfer queue.
--
-- TRANSFER MODE for this build is manual: an admin sees the queue, sends
-- the inscription from the project wallet, and records the txid. There is
-- no signing key anywhere in this codebase, which is the point - a hot
-- wallet key on shared hosting means one host compromise empties the
-- whole collection in a single transaction.
--
-- The schema is already shaped for automation (attempts, last_error,
-- claimed_at) so that when it is automated it can be done behind a
-- spending cap and an address allowlist, without a migration.
CREATE TABLE transfer_queue (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id      BIGINT UNSIGNED NOT NULL,

    -- pending    : waiting for an admin
    -- claimed    : an admin has it open, to stop two admins double-sending
    -- sent       : broadcast, waiting for confirmations
    -- complete   : confirmed
    -- failed     : needs attention
    status        ENUM('pending','claimed','sent','complete','failed')
                  NOT NULL DEFAULT 'pending',

    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error    VARCHAR(500)        DEFAULT NULL,

    -- Who is holding it and since when. A claim older than the timeout is
    -- released by the cron sweep so an admin closing their laptop does not
    -- strand an order forever.
    claimed_by    INT UNSIGNED        DEFAULT NULL,
    claimed_at    DATETIME            DEFAULT NULL,

    created_at    DATETIME        NOT NULL,
    updated_at    DATETIME            DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_transfer_queue_order (order_id),
    KEY idx_transfer_queue_status (status, created_at),
    KEY idx_transfer_queue_claim (status, claimed_at),

    CONSTRAINT fk_transfer_queue_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_transfer_queue_admin
        FOREIGN KEY (claimed_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
