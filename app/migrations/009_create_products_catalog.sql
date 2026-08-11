-- Digital-art product catalog: standalone downloadable goods.
--
-- This is deliberately separate from `nfts` (the Ordinals inventory that
-- ships through the manual on-chain transfer queue). A product here has
-- no on-chain leg at all - paying for one debits the wallet and grants an
-- instant download, nothing more. `inscription_id` on the product itself
-- is optional display metadata only; see the comment on that column.

CREATE TABLE product_categories (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(80)   NOT NULL,
    name            VARCHAR(120)  NOT NULL,
    description     TEXT              DEFAULT NULL,

    -- Visible-but-locked to a non-member, per the Membership perk in
    -- Membership::gateForCategory(). Not the same thing as is_visible:
    -- an invisible category is hidden from everyone, a members-only one
    -- is shown to everyone with a "join to access" prompt.
    is_members_only TINYINT(1)    NOT NULL DEFAULT 0,

    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_visible      TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_categories_slug (slug),
    KEY idx_product_categories_visible (is_visible, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE product_tags (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug  VARCHAR(80)  NOT NULL,
    name  VARCHAR(80)  NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_product_tags_slug (slug)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE products (
    id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug                        VARCHAR(160) NOT NULL,
    name                        VARCHAR(160) NOT NULL,
    description                 TEXT             DEFAULT NULL,

    category_id                 SMALLINT UNSIGNED DEFAULT NULL,

    -- Public preview: the watermarkable image shown on cards and the
    -- product page. Re-encoded through ImageStore (collection='product')
    -- like an NFT image, so it carries the same metadata-stripping
    -- guarantee. image_path is the larger render for the product page;
    -- preview_path is the grid thumbnail - same split as nfts.image_path
    -- / nfts.preview_path in migration 003.
    image_path                  VARCHAR(255)     DEFAULT NULL,
    preview_path                VARCHAR(255)     DEFAULT NULL,
    preview_mime                VARCHAR(60)      DEFAULT NULL,
    preview_width               SMALLINT UNSIGNED DEFAULT NULL,
    preview_height              SMALLINT UNSIGNED DEFAULT NULL,

    -- The file a buyer actually receives. Stored outside the web root
    -- under a random name (see DeliverableStore) and served only through
    -- DownloadController - never linked directly. original_name is what
    -- the browser is told to save it as; the on-disk name carries no
    -- meaningful extension for the buyer.
    deliverable_path            VARCHAR(255)     DEFAULT NULL,
    deliverable_mime            VARCHAR(120)     DEFAULT NULL,
    deliverable_size            BIGINT UNSIGNED  DEFAULT NULL,
    deliverable_original_name   VARCHAR(255)     DEFAULT NULL,

    -- Optional, cosmetic only. Shape enforced by
    -- Ordinals::isValidInscriptionId(): <64-hex-txid>i<index>. Nothing in
    -- this application mints or moves an inscription because of this
    -- field - it is reference metadata on the product page, no different
    -- in kind from a free-text description.
    inscription_id              VARCHAR(80)      DEFAULT NULL,

    price_minor                 INT UNSIGNED NOT NULL,
    -- Optional member discount. NULL means members pay price_minor too -
    -- a member price is an upgrade a product can opt into, not a second
    -- price every product must define.
    member_price_minor          INT UNSIGNED     DEFAULT NULL,

    status                      ENUM('listed','hidden') NOT NULL DEFAULT 'listed',

    created_at                  DATETIME     NOT NULL,
    updated_at                  DATETIME         DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_products_slug (slug),
    KEY idx_products_status_created (status, created_at),
    KEY idx_products_status_price (status, price_minor),
    KEY idx_products_category_status (category_id, status),

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES product_categories (id)
        ON DELETE SET NULL,

    CONSTRAINT chk_products_member_price
        CHECK (member_price_minor IS NULL OR member_price_minor <= price_minor)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


CREATE TABLE product_tag_map (
    product_id  INT UNSIGNED NOT NULL,
    tag_id      INT UNSIGNED NOT NULL,

    PRIMARY KEY (product_id, tag_id),
    KEY idx_product_tag_map_tag (tag_id, product_id),

    CONSTRAINT fk_product_tag_map_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_product_tag_map_tag
        FOREIGN KEY (tag_id) REFERENCES product_tags (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
