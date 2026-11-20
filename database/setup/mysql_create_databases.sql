-- =============================================================================
-- MAURO — create the local DEVELOPMENT and TEST databases and their accounts.
-- Target: MySQL 8.0 (verified on 8.0.46, Windows service MySQL80, 127.0.0.1:3306).
--
-- Run ONCE as a MySQL administrator, in batch mode so the first error stops it:
--   cmd /c "mysql -h 127.0.0.1 -P 3306 -u root -p < database\setup\mysql_create_databases.sql"
--
-- Replace both <SET_…_PASSWORD> placeholders with strong, DISTINCT passwords and
-- put them ONLY in .env (DB_PASSWORD / DB_TEST_PASSWORD) — never in Git.
--
-- Design decisions (see docs/MYSQL_VERIFICATION.md):
--  * No IF NOT EXISTS: if a database or account already exists the script stops
--    with an error instead of silently keeping an unknown configuration or
--    password. Inspect it first; nothing is ever dropped here.
--  * Each account reaches ONE database only. No global (*.*) privilege, no
--    GRANT OPTION, no access to any other schema on the server.
--  * Two host entries per account with identical password and privileges:
--    'localhost' is what MySQL matches for a TCP connection from 127.0.0.1 while
--    name resolution is on (the default); '127.0.0.1' keeps the application
--    working if skip_name_resolve is ever enabled.
--  * DEV has TRIGGER because `bin/console backup:run` runs
--    `mysqldump --triggers`, which needs it. LOCK TABLES is not needed
--    (--single-transaction) and PROCESS is not needed (--no-tablespaces).
--  * TEST has no TRIGGER: the destructive suite only drops and re-migrates
--    tables and is never backed up.
--  * No FLUSH PRIVILEGES: CREATE USER and GRANT update the privilege cache
--    themselves; FLUSH is only needed after editing grant tables directly.
--  * Default authentication plugin (caching_sha2_password) — supported by PHP's
--    mysqlnd. Do not use mysql_native_password (deprecated, removed in 9.x).
--  * Avoid ' " \ and spaces in the passwords (they would need escaping both here
--    and in .env).
-- =============================================================================

-- ---- 0. Inspection (read-only): both result sets must be empty -------------
SELECT user, host FROM mysql.user WHERE user IN ('mauro_dev', 'mauro_test');
SHOW DATABASES LIKE 'mauro\_library\_%';

-- ---- 1. Databases ----------------------------------------------------------
CREATE DATABASE mauro_library_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mauro_library_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- 2. Development: application + migrations + backups -------------------
CREATE USER 'mauro_dev'@'localhost' IDENTIFIED BY '<SET_DEV_PASSWORD>';
CREATE USER 'mauro_dev'@'127.0.0.1' IDENTIFIED BY '<SET_DEV_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, TRIGGER
    ON mauro_library_dev.* TO 'mauro_dev'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, TRIGGER
    ON mauro_library_dev.* TO 'mauro_dev'@'127.0.0.1';

-- ---- 3. Test: destructive integration suite (drop + re-migrate) -----------
CREATE USER 'mauro_test'@'localhost' IDENTIFIED BY '<SET_TEST_PASSWORD>';
CREATE USER 'mauro_test'@'127.0.0.1' IDENTIFIED BY '<SET_TEST_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
    ON mauro_library_test.* TO 'mauro_test'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
    ON mauro_library_test.* TO 'mauro_test'@'127.0.0.1';

-- ---- 4. Verification (read-only) -------------------------------------------
SHOW GRANTS FOR 'mauro_dev'@'localhost';
SHOW GRANTS FOR 'mauro_dev'@'127.0.0.1';
SHOW GRANTS FOR 'mauro_test'@'localhost';
SHOW GRANTS FOR 'mauro_test'@'127.0.0.1';
SHOW DATABASES;
