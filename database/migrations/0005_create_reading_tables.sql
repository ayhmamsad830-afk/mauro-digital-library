-- One cloud progress row per member and book (spec §7.3, §11.1).
CREATE TABLE reading_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    book_file_id BIGINT UNSIGNED NULL,
    current_page INT UNSIGNED NOT NULL DEFAULT 1,
    total_pages INT UNSIGNED NOT NULL,
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reading_progress_user_book (user_id, book_id),
    KEY idx_reading_progress_user_last (user_id, last_read_at),
    KEY idx_reading_progress_book (book_id),
    CONSTRAINT fk_reading_progress_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reading_progress_book FOREIGN KEY (book_id) REFERENCES books (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reading_progress_file FOREIGN KEY (book_file_id) REFERENCES book_files (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_reading_progress_page CHECK (current_page >= 1 AND current_page <= total_pages),
    CONSTRAINT chk_reading_progress_percent CHECK (progress_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reader sessions for guests and members: back the short-lived page tokens and
-- analytics (real sessions vs. page loads). Only a hash of the token is stored.
CREATE TABLE reading_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    book_id BIGINT UNSIGNED NOT NULL,
    book_file_id BIGINT UNSIGNED NULL,
    session_token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    reader_pseudonym CHAR(10) CHARACTER SET ascii NOT NULL,
    ip_hash CHAR(64) CHARACTER SET ascii NULL,
    device_type ENUM('desktop', 'tablet', 'mobile', 'unknown') NOT NULL DEFAULT 'unknown',
    pages_viewed INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reading_sessions_token (session_token_hash),
    KEY idx_reading_sessions_book_started (book_id, started_at),
    KEY idx_reading_sessions_user (user_id, started_at),
    KEY idx_reading_sessions_expires (expires_at),
    CONSTRAINT fk_reading_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_reading_sessions_book FOREIGN KEY (book_id) REFERENCES books (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_reading_sessions_file FOREIGN KEY (book_file_id) REFERENCES book_files (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
