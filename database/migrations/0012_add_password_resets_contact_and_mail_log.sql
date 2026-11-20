-- Member password recovery, contact requests and outgoing e-mail log (additive; no existing table changes).

-- One row per reset link. Only sha256(token) is stored; the token itself exists only in the e-mail.
-- A link is usable while used_at IS NULL and expires_at is in the future; a new request or a
-- successful reset removes the account's other rows.
CREATE TABLE password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_hash CHAR(64) CHARACTER SET ascii NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages sent from the public contact form. Always stored; notify_status records what really
-- happened to the administrator notification (not_configured = e-mail delivery not set up).
CREATE TABLE contact_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    locale ENUM('ar', 'en') NOT NULL DEFAULT 'ar',
    user_id BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) CHARACTER SET ascii NULL,
    status ENUM('new', 'read', 'archived') NOT NULL DEFAULT 'new',
    notify_status ENUM('not_configured', 'sent', 'failed') NOT NULL DEFAULT 'not_configured',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    read_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_contact_status (status, created_at),
    CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_contact_read_by FOREIGN KEY (read_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing e-mail attempts: kind and outcome only — never the recipient, the body, links or credentials.
CREATE TABLE mail_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(40) NOT NULL,
    status ENUM('sent', 'failed', 'disabled', 'logged') NOT NULL,
    error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mail_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
