# MySQL verification (Phase 3 acceptance)

**Status (2026-11-19): VERIFIED on the development machine.** MySQL **8.0.46** (Windows service `MySQL80`,
port **3306**). Both databases exist with their own least-privilege accounts, all **11** migrations are
applied to the development database, and the 32 MySQL integration tests pass. The application itself
still runs on SQLite (`DB_DRIVER=sqlite`) until the controlled switch (Phase I).

History: until 2026-11-18 this document reported "NOT VERIFIED — blocked on credentials" (a duplicate
service on port 3308, unknown root password). The recovery is recorded in
`docs/MYSQL_DUPLICATE_SERVICE_REMEDIATION.md`, `docs/MYSQL_CONNECTION_DIAGNOSTIC.md` and
`docs/MYSQL_ROOT_RECOVERY_ASSESSMENT.md`; the data import in `docs/MYSQL_DATA_IMPORT.md`.

## Databases

| Purpose | Name | Account | Used by | Destructive? |
|---|---|---|---|---|
| Development | `mauro_library_dev` | `mauro_dev` (this database only) | the application (`DB_*`), `migrate`, `bin/import-sqlite-to-mysql.php` | no |
| Test | `mauro_library_test` | `mauro_test` (this database only) | `php tests/run.php` (`DB_TEST_*`) | **yes** — every table dropped and re-migrated per test |

Creation script: `database/setup/mysql_create_databases.sql` (no global privilege, no `GRANT OPTION`;
`localhost` and `127.0.0.1` host entries). Passwords live only in `.env` (ignored by Git).

The development database holds the real library imported from SQLite (417 rows, verified row by row —
see `docs/MYSQL_DATA_IMPORT.md`). The test database only ever holds fictional fixtures
(`tests/Support/LibraryFixtures.php`); real data must never be copied into it.

## Safety guards in code

- `App\Support\TestDatabaseConfig`: every `DB_TEST_*` value is mandatory (the development credentials are
  never used as a fallback); destructive tests run only against `mauro_library_test`, with an account
  different from `DB_USERNAME`, on a local server, never when `APP_ENV=production`; `entreprise`,
  `mauro_library_dev` and the system schemas are refused by name.
- `Tests\Support\TestDatabase::mysql()` re-checks `SELECT DATABASE()` before dropping anything.
- `migrate` prints its target, refuses `APP_ENV=production` without `--production`, and refuses unless the
  driver is MySQL.
- `bin/import-sqlite-to-mysql.php` refuses non-empty targets, the test database, `entreprise` and production.
- `db:seed-dev` refuses production and a non-empty `books` table.

## Commands

```powershell
# connectivity (non-destructive)
powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console db:check          # OK server 8.0.46 — InnoDB: DEFAULT
powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console db:check --test   # same, for mauro_library_test

# development schema — while .env keeps DB_DRIVER=sqlite, set the driver for this process only
$env:DB_DRIVER='mysql'; powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console migrate:status

# destructive integration tests (test database only)
powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 tests/run.php MySql
# whole suite with every harness test on MySQL instead of in-memory SQLite
$env:MAURO_HARNESS='mysql'; powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 tests/run.php
```

## What the 32 MySQL tests verify

Server is InnoDB + `utf8mb4_unicode_ci` with all 12 migrations (0012 = password resets, contact messages, mail log); visibility (draft/archived/scheduled/deleted);
members-only hiding for guests and visibility for members; latest/popular/featured; hydration; taxonomy counts;
reviews filtering; Arabic search (diacritics, letter variants, partial words); English case-insensitive search;
multi-token AND; literal `%`/`_`; empty results; category (incl. children) / author / language filters; sorting;
SQL pagination and clamping; SQL-injection payloads; admin create/validate/slug uniqueness; publish rules;
lifecycle; category deactivation; cycle prevention; author re-indexing; transaction rollback.

With `MAURO_HARNESS=mysql` every other integration test (admin, members, reader, Google Sign-In, PDF
pipeline, backups, importer) also runs on MySQL. That run found MySQL-only defects (named placeholders
reused in one statement → `HY093`), fixed in `AdminPasswordAuth` and two test fixtures.

## Results log

| Date | Server version | dev migrate | MySQL tests | Notes |
|---|---|---|---|---|
| 2026-11-05 | — | not run (no server) | 28 skipped (BLOCKED) | awaiting credentials |
| 2026-11-06 | — | not run (no server) | 32 skipped (BLOCKED) | final delivery — awaiting credentials |
| 2026-11-18 | 8.0.46 | 11 applied | **32 passed** | root recovered, accounts created, migrations applied |
| 2026-11-19 | 8.0.46 | 11 applied | **32 passed**; full suite with `MAURO_HARNESS=mysql`: 261 passed, 1 failed (known issue KI-1, `docs/KNOWN_ISSUES.md`), 0 skipped | data imported into dev and verified; E2E included |
