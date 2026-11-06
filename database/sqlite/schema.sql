-- =============================================================================
--  SQLite mirror of database/migrations/0001–0008 — LOCAL DEV / TEST HARNESS ONLY
--  Production and the official development database use MySQL + the migrations.
--  Column names must stay identical to the MySQL schema; tests/Unit/SchemaParityTest
--  fails if the two drift. ENUMs become TEXT + CHECK.
-- =============================================================================
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    google_sub TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL DEFAULT '',
    avatar_url TEXT NULL,
    email_verified_at TEXT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
    preferred_locale TEXT NULL,
    preferred_theme TEXT NULL,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE authors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    biography TEXT NULL,
    photo_path TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES categories (id) ON DELETE SET NULL,
    name_ar TEXT NOT NULL,
    name_en TEXT NULL,
    slug TEXT NOT NULL UNIQUE,
    description_ar TEXT NULL,
    description_en TEXT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    title_en TEXT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NULL,
    description_en TEXT NULL,
    cover_path TEXT NULL,
    language TEXT NOT NULL DEFAULT 'ar',
    publisher TEXT NULL,
    edition TEXT NULL,
    isbn TEXT NULL,
    publication_year INTEGER NULL CHECK (publication_year IS NULL OR publication_year BETWEEN 1 AND 2100),
    page_count INTEGER NULL,
    visibility TEXT NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','members')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
    is_featured INTEGER NOT NULL DEFAULT 0,
    current_file_id INTEGER NULL REFERENCES book_files (id) ON DELETE SET NULL,
    rights_status TEXT NULL CHECK (rights_status IS NULL OR rights_status IN ('owned','licensed','public_domain')),
    license_name TEXT NULL,
    rights_holder TEXT NULL,
    rights_source TEXT NULL,
    rights_verified_at TEXT NULL,
    reads_count INTEGER NOT NULL DEFAULT 0,
    rating_avg REAL NOT NULL DEFAULT 0 CHECK (rating_avg BETWEEN 0 AND 5),
    rating_count INTEGER NOT NULL DEFAULT 0,
    search_text TEXT NULL,
    published_at TEXT NULL,
    created_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL
);
CREATE INDEX idx_books_status_published ON books (status, published_at);

CREATE TABLE book_authors (
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES authors (id) ON DELETE RESTRICT,
    author_order INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (book_id, author_id)
);

CREATE TABLE book_categories (
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    category_id INTEGER NOT NULL REFERENCES categories (id) ON DELETE RESTRICT,
    PRIMARY KEY (book_id, category_id)
);

CREATE TABLE book_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE RESTRICT,
    storage_path TEXT NOT NULL,
    original_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    checksum_sha256 TEXT NOT NULL,
    version_number INTEGER NOT NULL,
    validation_status TEXT NOT NULL DEFAULT 'pending' CHECK (validation_status IN ('pending','valid','invalid')),
    validation_error TEXT NULL,
    render_status TEXT NOT NULL DEFAULT 'pending' CHECK (render_status IN ('pending','processing','ready','failed')),
    render_engine TEXT NULL,
    render_profile TEXT NULL,
    page_count INTEGER NULL,
    uploaded_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rendered_at TEXT NULL,
    UNIQUE (book_id, version_number)
);

CREATE TABLE book_page_renders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_file_id INTEGER NOT NULL REFERENCES book_files (id) ON DELETE CASCADE,
    variant TEXT NOT NULL DEFAULT 'page' CHECK (variant IN ('page','thumb')),
    page_number INTEGER NOT NULL CHECK (page_number >= 1),
    storage_path TEXT NOT NULL,
    format TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    byte_size INTEGER NOT NULL,
    checksum_sha256 TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (book_file_id, variant, page_number)
);

CREATE TABLE book_render_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_file_id INTEGER NOT NULL REFERENCES book_files (id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    pages_done INTEGER NOT NULL DEFAULT 0,
    locked_by TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE reading_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    book_file_id INTEGER NULL REFERENCES book_files (id) ON DELETE SET NULL,
    current_page INTEGER NOT NULL DEFAULT 1,
    total_pages INTEGER NOT NULL,
    progress_percent REAL NOT NULL DEFAULT 0,
    last_read_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, book_id),
    CHECK (current_page >= 1 AND current_page <= total_pages),
    CHECK (progress_percent BETWEEN 0 AND 100)
);

CREATE TABLE reading_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE RESTRICT,
    book_file_id INTEGER NULL REFERENCES book_files (id) ON DELETE SET NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    reader_pseudonym TEXT NOT NULL,
    ip_hash TEXT NULL,
    device_type TEXT NOT NULL DEFAULT 'unknown',
    pages_viewed INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT NOT NULL,
    ended_at TEXT NULL
);

CREATE TABLE favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, book_id)
);

CREATE TABLE bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    page_number INTEGER NOT NULL CHECK (page_number >= 1),
    title TEXT NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    book_id INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    status TEXT NOT NULL DEFAULT 'published' CHECK (status IN ('published','hidden')),
    moderated_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    moderated_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, book_id)
);

CREATE TABLE admin_activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NULL,
    description TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE oauth_audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    provider TEXT NOT NULL DEFAULT 'google',
    event_type TEXT NOT NULL,
    outcome TEXT NOT NULL,
    reason TEXT NULL,
    ip_hash TEXT NULL,
    request_id TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rate_limits (
    limiter_key TEXT PRIMARY KEY,
    hits INTEGER NOT NULL DEFAULT 0,
    reset_at TEXT NOT NULL
);

INSERT INTO settings (setting_key, setting_value) VALUES
    ('catalog.per_page', '24'),
    ('home.featured_book_id', NULL),
    ('site.contact_email', NULL);

-- 0009 analytics indexes
CREATE INDEX idx_reading_sessions_started ON reading_sessions (started_at);
CREATE INDEX idx_reviews_status_created ON reviews (status, created_at);
CREATE INDEX idx_users_created ON users (created_at);
