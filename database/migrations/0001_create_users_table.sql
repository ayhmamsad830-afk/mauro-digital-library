-- Accounts created through Google Sign-In only (spec §8, §11.1).
-- Role defaults to 'user'; 'admin' is granted only via a protected admin action / CLI.
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email VARCHAR(320) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    avatar_url VARCHAR(2048) NULL,
    email_verified_at DATETIME NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    preferred_locale ENUM('ar', 'en') NULL,
    preferred_theme ENUM('light', 'dark') NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_google_sub (google_sub),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
