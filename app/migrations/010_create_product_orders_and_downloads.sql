-- Digital-product purchases and the download-access grant they create.
--
-- Unlike `orders` (Ordinals inventory, manual on-chain transfer), buying
-- a product here delivers instantly: the debit and the download grant
-- happen in the same transaction, and there is no transfer queue.
CREATE TABLE product_orders (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED    NOT NULL,
    product_id        INT UNSIGNED    NOT NULL,

    -- Price at the moment of sale, copied rather than joined - same
    -- reasoning as orders.price_minor in migration 006.
    price_minor       INT UNSIGNED    NOT NULL,
    currency          CHAR(3)         NOT NULL DEFAULT 'USD',
    was_member_price  TINYINT(1)      NOT NULL DEFAULT 0,

    status            ENUM('paid','refunded') NOT NULL DEFAULT 'paid',

    created_at        DATETIME        NOT NULL,

    PRIMARY KEY (id),

    -- Deliberately not unique on product_id - see the comment on
    -- orders.nft_id in migration 006. Buying the same product twice (or
    -- rebuying after a refund) is normal for a digital good with
    -- unlimited supply; ProductOrders::purchase() runs inside
    -- Wallet::withUserLock(), which is what stops a double-charge, not a
    -- constraint here.
    KEY idx_product_orders_product (product_id),
    KEY idx_product_orders_user_created (user_id, created_at),
    KEY idx_product_orders_status (status, created_at),

    CONSTRAINT fk_product_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_product_orders_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Single-use, short-lived download links.
--
-- A permanent "here is your file" URL is itself a liability for
-- something that is the thing being sold: it can be forwarded, cached by
-- a proxy, or crawled. Every click on "Download" mints a fresh row here;
-- DownloadController checks ownership, expiry and single-use, then
-- streams the file and marks the token spent in the same request that
-- logs it - used_at/used_ip double as that log, so there is no separate
-- download_log table to keep in sync.
CREATE TABLE download_tokens (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_order_id  BIGINT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED    NOT NULL,

    -- Only the hash is stored, same reasoning as user_tokens.token_hash:
    -- a database leak must not be directly replayable.
    token_hash        CHAR(64)        NOT NULL,
    expires_at        DATETIME        NOT NULL,
    used_at           DATETIME            DEFAULT NULL,
    used_ip           VARBINARY(16)       DEFAULT NULL,

    created_at        DATETIME        NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_download_tokens_hash (token_hash),
    KEY idx_download_tokens_order (product_order_id),

    CONSTRAINT fk_download_tokens_order
        FOREIGN KEY (product_order_id) REFERENCES product_orders (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_download_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
