-- Original PDF versions. storage_path is RELATIVE to the private storage root
-- and is never exposed to the browser. PDF bytes are never stored in MySQL.
CREATE TABLE book_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_id BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    validation_status ENUM('pending', 'valid', 'invalid') NOT NULL DEFAULT 'pending',
    validation_error VARCHAR(1000) NULL,
    render_status ENUM('pending', 'processing', 'ready', 'failed') NOT NULL DEFAULT 'pending',
    render_engine VARCHAR(50) NULL,
    render_profile VARCHAR(100) NULL,
    page_count INT UNSIGNED NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rendered_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_files_version (book_id, version_number),
    KEY idx_book_files_checksum (checksum_sha256),
    KEY idx_book_files_status (validation_status, render_status),
    CONSTRAINT fk_book_files_book FOREIGN KEY (book_id) REFERENCES books (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_book_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE books
    ADD KEY idx_books_current_file (current_file_id),
    ADD CONSTRAINT fk_books_current_file FOREIGN KEY (current_file_id) REFERENCES book_files (id)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- Derived page images (masters, unwatermarked) in private storage.
-- `variant` distinguishes full pages from thumbnails (extension of spec §11.1).
CREATE TABLE book_page_renders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_file_id BIGINT UNSIGNED NOT NULL,
    variant ENUM('page', 'thumb') NOT NULL DEFAULT 'page',
    page_number INT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    format ENUM('webp', 'jpeg', 'png') NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_page_renders (book_file_id, variant, page_number),
    CONSTRAINT fk_page_renders_file FOREIGN KEY (book_file_id) REFERENCES book_files (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_page_renders_page CHECK (page_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE book_render_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_file_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    pages_done INT UNSIGNED NOT NULL DEFAULT 0,
    locked_by VARCHAR(64) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_error VARCHAR(2000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_render_jobs_status (status, created_at),
    KEY idx_render_jobs_file (book_file_id),
    CONSTRAINT fk_render_jobs_file FOREIGN KEY (book_file_id) REFERENCES book_files (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
