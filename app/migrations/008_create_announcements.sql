-- Store announcements, shown on the account dashboard and the home page.
CREATE TABLE announcements (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    title        VARCHAR(160)    NOT NULL,

    -- Plain text. Rendered escaped with paragraph breaks - deliberately
    -- not HTML or markdown, so an admin account compromise cannot turn an
    -- announcement into a script tag on every logged-in user's dashboard.
    body         TEXT            NOT NULL,

    level        ENUM('info','warning','critical') NOT NULL DEFAULT 'info',

    -- NULL = draft. Set to a future time to schedule.
    published_at DATETIME            DEFAULT NULL,
    expires_at   DATETIME            DEFAULT NULL,

    created_by   INT UNSIGNED        DEFAULT NULL,
    created_at   DATETIME        NOT NULL,
    updated_at   DATETIME            DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_announcements_window (published_at, expires_at),

    CONSTRAINT fk_announcements_author
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
