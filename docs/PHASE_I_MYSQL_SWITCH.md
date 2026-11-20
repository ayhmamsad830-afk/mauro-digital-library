# MAURO — Phase I: controlled switch from SQLite to MySQL (development machine)

**Status: PREPARED, NOT EXECUTED (2026-11-19).** `.env` still has `DB_DRIVER=sqlite`. Every step below
that changes something needs the owner's go-ahead.

## Preconditions (checked 2026-11-19, read-only)

| Check | Result |
|---|---|
| MySQL 8.0.46, service `MySQL80`, port 3306 | running |
| `mauro_library_dev`: 11 migrations, real data imported | 417 rows, identical to SQLite except `rate_limits` (throttle counters) — `docs/MYSQL_DATA_IMPORT.md` |
| SQLite unchanged since the import | sha256 `F13D5CE2…FF5AE` (last write 2026-11-17 17:06) → DEV is in sync |
| Test suites | SQLite and `MAURO_HARNESS=mysql`: 261 passed, 1 known issue (KI-1), 0 skipped |
| `env:check` with the MySQL driver | mandatory requirements met; `mysql connected` |
| Components hard-wired to SQLite | none — only the driver switch in `config/database.php`, `Database::connect`, `LibraryRepositoryFactory` |
| Private storage (PDFs, renders, covers) | 14.8 MB; unaffected by the switch (files, not database) |
| Disk | 45 GB free |

`env:check` warnings to know (not blockers locally): `ext-intl` missing, `expose_php On`,
`memory_limit 128M`, `original-pdf` permissions 777 (Windows), `APP_URL` not HTTPS (local),
**`mysqldump` not on PATH** (see I-2).

## Risks and how each step handles them

| Risk | Handling |
|---|---|
| Data written on SQLite after the import is missing in MySQL | I-1 refuses to continue if the SQLite hash changed; then a re-import is needed (separate approval) |
| After the switch, the test suite's HTTP servers read `.env` and would hit the **real** DEV database | **Resolved 2026-11-19 in code:** `tests/Feature/HttpServer.php` gives every test server a fresh scratch SQLite unless the test sets `DB_DRIVER` itself (MySQL without an explicit user = no database), and `tests/run.php` points the default in-process connection at an empty in-memory SQLite. Guard test: `tests/Feature/HttpServerIsolationTest.php`. Proof: full suite run with `DB_DRIVER=mysql` (simulated switch, no overrides) — 264 passed, 1 known issue (KI-1); DEV (incl. `rate_limits`), `entreprise`, SQLite, `.env` and backups unchanged (44/44) |
| `backup:run` prunes old archives | it only deletes `db-*.sql.gz` / `files-*.tar.gz`; run with `--keep=3650`; other backups never match |
| Rollback after real use on MySQL | switching back to SQLite is instant, but anything written to MySQL after the switch is **not** in SQLite |

## Steps

### I-0 Preparation (owner)
- Close or pause other agents (Codex desktop and VS Code extension) and stop any local server on port 8000.
- ~~Decide how tests are isolated after the switch~~ — done: isolation is built into the test code (see Risks).

### I-1 Sync check (read-only)
```powershell
(Get-FileHash storage\mauro.sqlite -Algorithm SHA256).Hash   # must be F13D5CE220EE3C5BD2D3B8F503ADDB2314C9877AA85FF78B4042A25F073FF5AE
```
Different → **stop**: SQLite received new data; plan a re-import first.

### I-2 Final backups (creates files only)
1. SQLite copy + checksum: `storage/backups/mauro-pre-switch-<timestamp>.sqlite` (+ `.sha256`), `integrity_check = ok`.
2. Manual dump of DEV (root option file): `storage/backups/mysql-dev-pre-switch-<timestamp>.sql` (+ `.sha256`).
3. The application's own backup, on MySQL, with `mysqldump` on the PATH **for this process only**:
   ```powershell
   $env:DB_DRIVER='mysql'; $env:Path="C:\Program Files\MySQL\MySQL Server 8.0\bin;$env:Path"
   powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console backup:run --keep=3650
   powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console backup:verify storage\backups\db-<stamp>.sql.gz
   powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/console backup:verify storage\backups\files-<stamp>.tar.gz
   ```
   Expected: `db-*.sql.gz` "complete SQL dump", `files-*.tar.gz` verified, nothing pruned.
4. Optional restore test (needs approval: creates a throw-away database `mauro_restore_check` as root, restores
   the `db-*.sql.gz`, compares table checksums with DEV, then drops it). Never restored into TEST
   (no real data in TEST) and never into DEV.

### I-3 Switch (one line in `.env`)
- Copy `.env` to the recovery folder first (ACL-restricted), then change `DB_DRIVER=sqlite` → `DB_DRIVER=mysql`.
- Keep `DB_SQLITE_PATH` and the SQLite file untouched (rollback).

### I-4 Verification on MySQL
- `env:check`, `db:check`, `db:check --test`, `migrate:status` (11 × applied).
- GET audit of every public page, protections and headers (only `rate_limits` may change).
- Reader on the real book (now writes reading sessions to DEV — expected from this point on).
- Owner manual tests: admin login, Google sign-in on `http://localhost:8000`, member login, favourites
  (toggle twice), reviews (view), admin pages (view only), logout.
- Full test suite with the test isolation from I-0.

### I-5 Rollback (if anything fails)
1. Restore the saved `.env` (or set `DB_DRIVER=sqlite`), restart the server.
2. SQLite is untouched, so the site is back to its pre-switch state.
3. Data created on MySQL after the switch must be reconciled manually (or the switch repeated after a fix).

## Not part of Phase I
Production server setup, production `.env`, HTTPS domain for Google, publishing/deploying — later phases,
each with explicit approval.
