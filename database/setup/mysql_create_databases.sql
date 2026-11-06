-- =============================================================================
-- MAURO — create the DEVELOPMENT and TEST databases (run once as a MySQL admin).
-- Replace both <SET_…_PASSWORD> placeholders with strong, distinct passwords
-- and put them ONLY in .env (never in Git). Each user can reach ONE database.
-- Never point DB_TEST_* at production: the test suite drops every table in it.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS mauro_library_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS mauro_library_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Development: application + migrations.
CREATE USER IF NOT EXISTS 'mauro_dev'@'localhost' IDENTIFIED BY '<SET_DEV_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
    ON mauro_library_dev.* TO 'mauro_dev'@'localhost';

-- Test: destructive integration tests (drop + re-migrate on every run).
CREATE USER IF NOT EXISTS 'mauro_test'@'localhost' IDENTIFIED BY '<SET_TEST_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP
    ON mauro_library_test.* TO 'mauro_test'@'localhost';

FLUSH PRIVILEGES;
