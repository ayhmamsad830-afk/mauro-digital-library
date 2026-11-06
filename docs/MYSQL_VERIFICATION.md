# MySQL verification (Phase 3 acceptance)

**Status (2026-11-06, launch preparation): NOT VERIFIED — blocked on credentials.** A MySQL 26.7 server
(Windows service `MySQL267`) runs on the development machine on **port 3308** (not 3306), but the `DB_USERNAME`
in the local `.env` is rejected (`1045 Access denied`). No migration (0001–0010) has been applied to MySQL.
The 32 MySQL integration tests are reported as **SKIPPED (BLOCKED)** by the test runner, never as passed.
The same contract tests pass on the SQLite harness, which verifies the query logic but not MySQL-specific
behaviour (InnoDB, utf8mb4 collation, native prepares, `mysqldump`).

To unblock locally: create the test database and a user limited to it (`database/setup/mysql_create_databases.sql`),
then set `DB_TEST_PORT=3308`, `DB_TEST_USERNAME`, `DB_TEST_PASSWORD` in `.env` and run `php bin/console db:check --test`.

## Databases

| Purpose | Name | Used by | Destructive? |
|---|---|---|---|
| Development | `mauro_library_dev` | the application (`DB_*`), `migrate`, optional `db:seed-dev` | no (migrations only) |
| Test | `mauro_library_test` | `php tests/run.php` (`DB_TEST_*`) | **yes** — all tables dropped and re-migrated each run |

Creation script: `database/setup/mysql_create_databases.sql` (separate users, each limited to one database).

Safety guards in code:
- Tests refuse unless the database name ends in `_test`, refuse when `APP_ENV=production`, and refuse if the test
  database equals the development database on the same host/port.
- `migrate` prints its target and refuses `APP_ENV=production` without `--production`.
- `db:seed-dev` refuses production and refuses a non-empty `books` table.

## Steps once credentials exist

```bash
# 1. .env
DB_HOST=…  DB_PORT=3306  DB_DATABASE=mauro_library_dev   DB_USERNAME=mauro_dev  DB_PASSWORD=…
DB_TEST_DATABASE=mauro_library_test  DB_TEST_USERNAME=mauro_test  DB_TEST_PASSWORD=…  (DB_TEST_HOST/PORT if different)

# 2. connectivity (non-destructive)
php bin/console db:check            # dev   → expect: OK server 8.x — InnoDB: DEFAULT
php bin/console db:check --test     # test  → same

# 3. development schema
php bin/console migrate             # expect: 8 migration(s) applied
php bin/console migrate:status      # expect: 8 × [applied]

# 4. destructive integration tests (test DB only)
php tests/run.php MySql             # expect: 32 passed, 0 skipped
php tests/run.php                   # whole suite: 0 failed, 0 skipped
```

## What the 32 MySQL tests verify
Server is InnoDB + `utf8mb4_unicode_ci` with all 8 migrations; visibility (draft/archived/scheduled/deleted);
members-only hiding for guests and visibility for members; latest/popular/featured; hydration; taxonomy counts;
reviews filtering; Arabic search (diacritics, letter variants, partial words); English case-insensitive search;
multi-token AND; literal `%`/`_`; empty results; category (incl. children) / author / language filters; sorting;
SQL pagination and clamping; SQL-injection payloads; admin create/validate/slug uniqueness; publish rules;
lifecycle; category deactivation; cycle prevention; author re-indexing; transaction rollback.

## Results log
| Date | Server version | dev migrate | MySQL tests | Notes |
|---|---|---|---|---|
| 2026-11-05 | — | not run (no server) | 28 skipped (BLOCKED) | awaiting credentials |
| 2026-11-06 | — | not run (no server) | 32 skipped (BLOCKED) | final delivery — awaiting credentials |
