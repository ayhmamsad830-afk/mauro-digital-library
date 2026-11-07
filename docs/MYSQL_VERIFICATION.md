# MySQL verification (Phase 3 acceptance)

**Status (2026-11-06, launch preparation): NOT VERIFIED — blocked on credentials.** A MySQL 26.7 server
(Windows service `MySQL267`) runs on the development machine on **port 3308** (not 3306), but the `DB_USERNAME`
in the local `.env` is rejected (`1045 Access denied`). No migration (0001–0010) has been applied to MySQL.
The 32 MySQL integration tests are reported as **SKIPPED (BLOCKED)** by the test runner, never as passed.
The same contract tests pass on the SQLite harness, which verifies the query logic but not MySQL-specific
behaviour (InnoDB, utf8mb4 collation, native prepares, `mysqldump`).

### Unblock on the development machine (owner, ~5 minutes)

Passwords are typed only at the MySQL prompt and in `.env` — never in chat, Git or the command line.

1. Open **PowerShell** and log in with the MySQL administrator account you created when installing MySQL
   (usually `root`; `-p` makes MySQL ask for the password without echoing it):
   ```powershell
   & "C:\Program Files\MySQL\MySQL Server 26.7\bin\mysql.exe" -h 127.0.0.1 -P 3308 -u root -p
   ```
2. At the `mysql>` prompt run (choose a new strong password for `mauro_test`):
   ```sql
   CREATE DATABASE IF NOT EXISTS mauro_library_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER IF NOT EXISTS 'mauro_test'@'localhost' IDENTIFIED BY '<new strong password>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, TRIGGER, SHOW VIEW
       ON mauro_library_test.* TO 'mauro_test'@'localhost';
   EXIT;
   ```
   This user can reach `mauro_library_test` and nothing else.
3. In the project's `.env` (ignored by Git) add:
   ```
   DB_TEST_HOST=127.0.0.1
   DB_TEST_PORT=3308
   DB_TEST_DATABASE=mauro_library_test
   DB_TEST_USERNAME=mauro_test
   DB_TEST_PASSWORD=<the password from step 2>
   ```
4. Check (non-destructive): `php bin/console db:check --test` → `OK server 26.7.0 …`.

Also fix the development entry while there: `DB_PORT=3308` (the server does not listen on 3306), and either valid
`DB_USERNAME`/`DB_PASSWORD` for `mauro_library_dev` or an empty `DB_USERNAME` (the local site uses SQLite anyway).

If the administrator password is lost, do **not** reset it as part of this project — that is a server-level
change outside MAURO; use MySQL's documented `--init-file` recovery procedure deliberately, or reinstall.

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
