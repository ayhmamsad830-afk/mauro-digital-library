-- Website settings editable from the admin dashboard (key/value, JSON values).
CREATE TABLE settings (
    setting_key VARCHAR(100) CHARACTER SET ascii NOT NULL,
    setting_value TEXT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiter storage (no Redis required on shared hosting).
CREATE TABLE rate_limits (
    limiter_key CHAR(64) CHARACTER SET ascii NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at DATETIME NOT NULL,
    PRIMARY KEY (limiter_key),
    KEY idx_rate_limits_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baseline settings only (no fake content).
INSERT INTO settings (setting_key, setting_value) VALUES
    ('catalog.per_page', '24'),
    ('home.featured_book_id', NULL),
    ('site.contact_email', NULL);
