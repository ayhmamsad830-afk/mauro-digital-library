-- Main book record (spec §11.1, §9.2). current_file_id FK is added in 0005,
-- after book_files exists (circular reference).
CREATE TABLE books (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(191) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    description MEDIUMTEXT NULL,
    cover_path VARCHAR(500) NULL,
    language VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'ar',
    publisher VARCHAR(255) NULL,
    publication_year SMALLINT UNSIGNED NULL,
    page_count INT UNSIGNED NULL,
    visibility ENUM('public', 'members') NOT NULL DEFAULT 'public',
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    current_file_id BIGINT UNSIGNED NULL,

    -- Rights record: a book cannot be published while these are incomplete.
    rights_status ENUM('owned', 'licensed', 'public_domain') NULL,
    license_name VARCHAR(255) NULL,
    rights_holder VARCHAR(255) NULL,
    rights_source TEXT NULL,
    rights_verified_at DATETIME NULL,

    -- Denormalised counters maintained by services, used for sorting.
    reads_count INT UNSIGNED NOT NULL DEFAULT 0,
    rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,

    -- Normalised text (Arabic diacritics/alef variants folded) for search.
    search_text MEDIUMTEXT NULL,

    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_books_slug (slug),
    KEY idx_books_status_published (status, published_at),
    KEY idx_books_language (language),
    KEY idx_books_status_reads (status, reads_count),
    KEY idx_books_status_rating (status, rating_avg),
    KEY idx_books_featured (is_featured, status),
    KEY idx_books_title (title),
    CONSTRAINT fk_books_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_books_year CHECK (publication_year IS NULL OR publication_year BETWEEN 1 AND 2100),
    CONSTRAINT chk_books_rating_avg CHECK (rating_avg BETWEEN 0 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE book_authors (
    book_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    author_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (book_id, author_id),
    KEY idx_book_authors_author (author_id),
    CONSTRAINT fk_book_authors_book FOREIGN KEY (book_id) REFERENCES books (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_book_authors_author FOREIGN KEY (author_id) REFERENCES authors (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE book_categories (
    book_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (book_id, category_id),
    KEY idx_book_categories_category (category_id),
    CONSTRAINT fk_book_categories_book FOREIGN KEY (book_id) REFERENCES books (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_book_categories_category FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
