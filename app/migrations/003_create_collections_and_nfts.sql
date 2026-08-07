-- Collections and inventory.

CREATE TABLE collections (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug         VARCHAR(80)   NOT NULL,
    name         VARCHAR(120)  NOT NULL,
    description  TEXT              DEFAULT NULL,
    cover_path   VARCHAR(255)      DEFAULT NULL,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_visible   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at   DATETIME      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_collections_slug (slug),
    KEY idx_collections_visible (is_visible, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Inventory.
--
-- On the shape of `token_id` / `contract_address`: this build is for
-- Bitcoin Ordinals, where there is no contract - an inscription is
-- identified by `<reveal_txid>i<index>`. `token_id` holds that
-- inscription id and `contract_address` stays NULL. Both columns are kept
-- so the schema can carry an EVM collection later without a migration
-- that touches every row, and `chain` says which interpretation applies.
CREATE TABLE nfts (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Inscription id for bitcoin-ordinals; the ERC-721 token id on EVM
    -- chains. Unique per chain, not globally: the same numeric token id
    -- legitimately exists on two chains.
    token_id            VARCHAR(100) NOT NULL,
    contract_address    VARCHAR(64)      DEFAULT NULL,
    chain               VARCHAR(32)  NOT NULL DEFAULT 'bitcoin-ordinals',

    -- Ordinals-specific: the global inscription sequence number, and the
    -- sat's ordinal number. Both are display/provenance data, not keys.
    inscription_number  BIGINT UNSIGNED  DEFAULT NULL,
    sat_ordinal         BIGINT UNSIGNED  DEFAULT NULL,

    collection_id       SMALLINT UNSIGNED DEFAULT NULL,

    name                VARCHAR(160) NOT NULL,
    description         TEXT             DEFAULT NULL,

    -- Paths are relative to storage/nft and are served through a PHP
    -- handler, never linked directly. Uploaded media lives outside the
    -- web root so a file that survives validation still cannot be
    -- executed by Apache.
    image_path          VARCHAR(255)     DEFAULT NULL,
    preview_path        VARCHAR(255)     DEFAULT NULL,
    image_mime          VARCHAR(60)      DEFAULT NULL,
    image_width         SMALLINT UNSIGNED DEFAULT NULL,
    image_height        SMALLINT UNSIGNED DEFAULT NULL,

    -- Display copy of the traits. Filtering does NOT read this column -
    -- see nft_attributes below.
    attributes          JSON             DEFAULT NULL,

    price_minor         INT UNSIGNED NOT NULL,

    -- listed    : purchasable
    -- reserved   : held out of the storefront by an admin
    -- sold       : bought, transfer not yet broadcast
    -- transferred: on-chain transfer confirmed, buyer owns it
    status              ENUM('listed','reserved','sold','transferred') NOT NULL DEFAULT 'listed',

    -- Higher is rarer. Computed from trait frequency across the
    -- collection at import time; recomputed by bin/rarity.php.
    rarity_score        INT UNSIGNED NOT NULL DEFAULT 0,

    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME         DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_nfts_chain_token (chain, token_id),

    -- The collection browser's default view is "listed, newest first",
    -- and its sorts are price and rarity. These three cover them without
    -- a filesort.
    KEY idx_nfts_status_created (status, created_at),
    KEY idx_nfts_status_price (status, price_minor),
    KEY idx_nfts_status_rarity (status, rarity_score),
    KEY idx_nfts_collection_status (collection_id, status),

    CONSTRAINT fk_nfts_collection
        FOREIGN KEY (collection_id) REFERENCES collections (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


-- Traits, normalised for filtering.
--
-- The same data is in nfts.attributes as JSON. It is duplicated here
-- because trait filtering is the collection browser's main query, and on
-- MariaDB 10.6 there is no way to index a JSON path - every JSON_CONTAINS
-- filter is a full scan. A narrow table with a composite index turns
-- "show me Laser Eyes + Gold Background" into two index lookups.
--
-- nfts.attributes stays the source of truth for display; this table is
-- rebuilt from it by Nft::syncAttributes().
CREATE TABLE nft_attributes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nft_id      INT UNSIGNED    NOT NULL,
    trait_type  VARCHAR(60)     NOT NULL,
    value       VARCHAR(120)    NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_nft_attr (nft_id, trait_type, value),

    -- Leading (trait_type, value) so a filter can find matching nft_ids
    -- directly; nft_id trails to keep the lookup covering.
    KEY idx_nft_attr_lookup (trait_type, value, nft_id),

    CONSTRAINT fk_nft_attributes_nft
        FOREIGN KEY (nft_id) REFERENCES nfts (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
