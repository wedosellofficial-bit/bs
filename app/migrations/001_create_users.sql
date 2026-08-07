-- Accounts.
--
-- `status` gates login independently of `email_verified_at`: a suspended
-- account with a verified email must not be able to sign in, and an
-- unverified account must not be able to buy. Two separate questions,
-- two separate columns.
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Stored lowercase. The application lowercases on write so that
    -- `A@example.com` and `a@example.com` cannot become two accounts;
    -- relying on a case-insensitive collation to enforce that would tie
    -- account identity to a collation someone can change later.
    email               VARCHAR(190) NOT NULL,

    -- Argon2id output. 255 chars leaves room for parameter changes; the
    -- current format is ~97 bytes.
    password_hash       VARCHAR(255) NOT NULL,

    display_name        VARCHAR(60)      DEFAULT NULL,

    email_verified_at   DATETIME         DEFAULT NULL,

    -- TOTP shared secret, encrypted at rest with a key derived from
    -- APP_KEY (see Auth::encryptSecret). Stored encrypted because a
    -- read-only database leak should not hand over the second factor
    -- along with the password hashes.
    twofa_secret        VARBINARY(255)   DEFAULT NULL,
    twofa_confirmed_at  DATETIME         DEFAULT NULL,

    -- Recovery codes, hashed, one per line. Nullable until 2FA is set up.
    twofa_recovery      TEXT             DEFAULT NULL,

    role                ENUM('user','admin') NOT NULL DEFAULT 'user',
    status              ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',

    -- Default payout address, remembered so a repeat buyer does not have
    -- to re-paste it. Always re-validated and always re-confirmed at
    -- purchase time - a stored address is a convenience, never an
    -- authority.
    default_payout_address VARCHAR(90)   DEFAULT NULL,

    created_at          DATETIME     NOT NULL,
    updated_at          DATETIME         DEFAULT NULL,
    last_login_at       DATETIME         DEFAULT NULL,
    last_login_ip       VARBINARY(16)    DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_status (status),
    KEY idx_users_created (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
