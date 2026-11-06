CREATE TABLE admin_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    description VARCHAR(1000) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_logs_admin (admin_id, created_at),
    KEY idx_admin_logs_entity (entity_type, entity_id),
    KEY idx_admin_logs_created (created_at),
    CONSTRAINT fk_admin_logs_admin FOREIGN KEY (admin_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authentication events for review. ID tokens are NEVER stored.
CREATE TABLE oauth_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'google',
    event_type VARCHAR(50) NOT NULL,
    outcome ENUM('success', 'failure') NOT NULL,
    reason VARCHAR(255) NULL,
    ip_hash CHAR(64) CHARACTER SET ascii NULL,
    request_id VARCHAR(32) CHARACTER SET ascii NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oauth_events_user (user_id, created_at),
    KEY idx_oauth_events_created (created_at),
    CONSTRAINT fk_oauth_events_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
