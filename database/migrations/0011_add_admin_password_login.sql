-- Local username/password sign-in for administrator accounts (additive, backward-compatible).
-- Existing rows keep NULLs and continue to sign in with Google exactly as before.
--   username              lowercase login name, unique when set (NULL for Google-only accounts)
--   password_hash         password_hash() output (Argon2id when available); never plaintext
--   password_must_change  1 = the current password is a temporary one: must be replaced after login
--   password_changed_at   when the administrator last set their own password
-- Password-only administrators are stored with reserved placeholders that can never match Google:
--   google_sub = 'local:<username>' (Google subjects are numeric), email = '<username>@admin.invalid' (RFC 2606).
ALTER TABLE users ADD COLUMN username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER email;
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER username;
ALTER TABLE users ADD COLUMN password_must_change TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password_must_change;
ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);
