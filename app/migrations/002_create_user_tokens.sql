-- Single-use tokens for email verification and password reset.
--
-- Only the SHA-256 of the token is stored. The plaintext exists in the
-- email and nowhere else, so a database leak cannot be replayed into
-- account takeover - which is exactly what storing reset tokens in clear
-- allows.
CREATE TABLE user_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED    NOT NULL,
    type         ENUM('email_verify','password_reset') NOT NULL,

    token_hash   CHAR(64)        NOT NULL,

    expires_at   DATETIME        NOT NULL,
    used_at      DATETIME            DEFAULT NULL,
    created_at   DATETIME        NOT NULL,

    -- Which IP requested it, for abuse investigation.
    created_ip   VARBINARY(16)       DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_tokens_hash (token_hash),
    KEY idx_user_tokens_user_type (user_id, type, used_at),
    KEY idx_user_tokens_expiry (expires_at),

    CONSTRAINT fk_user_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
