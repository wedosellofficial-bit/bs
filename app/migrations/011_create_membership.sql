-- Billions Membership: a flag on the user plus a record of the fee
-- payment. The fee itself is charged through Wallet::debit() exactly
-- like any other purchase (see Membership::join()); this table exists so
-- "did this user ever pay to join" does not depend on grepping
-- wallet_entries.memo, the same reasoning as deposits sitting alongside
-- wallet_entries instead of being inferred from it.
ALTER TABLE users
    ADD COLUMN is_member    TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
    ADD COLUMN member_since DATETIME       DEFAULT NULL   AFTER is_member;

CREATE TABLE membership_payments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NOT NULL,
    fee_minor   INT UNSIGNED    NOT NULL,
    currency    CHAR(3)         NOT NULL DEFAULT 'USD',
    created_at  DATETIME        NOT NULL,

    PRIMARY KEY (id),
    KEY idx_membership_payments_user (user_id, created_at),

    CONSTRAINT fk_membership_payments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
