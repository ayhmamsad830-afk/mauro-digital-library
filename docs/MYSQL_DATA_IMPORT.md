# MAURO — SQLite → MySQL data import (Phase G)

**Status (2026-11-18): data imported into `mauro_library_dev` and verified row by row.
`DB_DRIVER=sqlite` is still active; the switch (Phase I) has not been made.**

## Tool

`bin/import-sqlite-to-mysql.php` + `app/Support/SqliteToMysqlImporter.php`

```powershell
powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/import-sqlite-to-mysql.php --source=storage/mauro.sqlite --dry-run
powershell -ExecutionPolicy Bypass -File bin\dev-php.ps1 bin/import-sqlite-to-mysql.php --source=storage/mauro.sqlite --confirm
```

The target is the `DB_*` MySQL settings (`DB_DATABASE`, `DB_USERNAME`), regardless of
`DB_DRIVER`, so the site keeps running on SQLite until the switch.

| Guarantee | How |
|---|---|
| Source never written | opened with SQLite URI `mode=ro`; `PRAGMA integrity_check` must be `ok` |
| No merge into existing data | every target table must be empty; `settings` may hold only the 3 defaults seeded by migration 0008 (replaced by the source values) |
| Right target only | refuses `entreprise`, system schemas and `mauro_library_test`; refused when `APP_ENV=production`; target must have every migration applied and the same tables/columns as the source |
| All or nothing | one InnoDB transaction, foreign keys ON, parents before children |
| Relations preserved | ids copied verbatim; the `books.current_file_id ↔ book_files.book_id` cycle is broken through the nullable column (inserted NULL, set after `book_files`), keeping the original `updated_at` despite `ON UPDATE CURRENT_TIMESTAMP` |
| Exact copy | inside the transaction, every row and column is compared with the source; any difference rolls everything back |
| Rehearsal | `--dry-run` does the full copy + comparison, then rolls back |

Tests: `tests/Integration/SqliteImportTest.php` (copy, cycle, timestamps, settings, Unicode, dry run, refusal of a second import, production guard).

## Run of 2026-11-18

| Step | Result |
|---|---|
| Schema audit | 18 tables, identical column sets; MySQL adds only `migrations` |
| Value audit | 452 datetimes, all enums, decimals, lengths, NOT NULL: 0 incompatibilities |
| Backup of MySQL DEV before import | `storage/backups/mysql-dev-pre-import-20261118-232115.sql` (+ `.sha256`) |
| SQLite backup | `storage/backups/mauro-pre-mysql-migration-20261118-223058.sqlite` — sha256 matches, `integrity_check=ok`, same row counts as the live file |
| Dry run | 417 rows, identical, rolled back |
| Import | 417 rows in 18 tables, committed; a second run is refused |
| Independent verification | per-table sha256 fingerprint of every row: **18/18 identical** |
| Live SQLite file | sha256 unchanged (`f13d5ce2…ff5ae`) |
| `entreprise` | checksums unchanged |

### Reference book (id 1)

`رُكون الظلام` / *Sitting in the Darkness* — publisher صفقات كتابية للنشر والتوزيع, الطبعة الأولى, 2025,
`page_count = 164`, published, public.

- Rendered images: **328 rows = 164 `page` + 164 `thumb`**, pages 1–164 for each variant
  (the 328 "page images" of the inventory are two variants of 164 pages).
- Every image file exists under `storage/` and matches its stored sha256 and size; the original PDF matches its sha256.
- **Data observation (not changed):** two authors exist (`مُعِين خيري أبوعائشة`, `محمد سليمان اليونسي`) but only the
  first is linked to the book in `book_authors` — already the case in SQLite. Linking the second author is an
  editorial decision; it can be done from the admin book form.

### Rows per table

admin_activity_logs 14 · authors 2 · book_authors 1 · book_categories 1 · book_files 1 · book_page_renders 328 ·
book_render_jobs 1 · bookmarks 0 · books 1 · categories 1 · favorites 1 · oauth_audit_events 17 · rate_limits 32 ·
reading_progress 1 · reading_sessions 9 · reviews 1 · settings 3 · users 3 — **total 417**.

## Rollback

Nothing on the SQLite side changed. To undo the import on MySQL, restore
`storage/backups/mysql-dev-pre-import-20261118-232115.sql` (empty, migrated schema).

## Functional audit on MySQL (2026-11-19)

| Suite / audit | Result |
|---|---|
| Full suite, default (SQLite harness) | 253 passed, 0 failed, 3 skipped (Playwright, Poppler, qpdf not installed) |
| Full suite, `MAURO_HARNESS=mysql` (every harness test on MySQL) | 253 passed, 0 failed, 3 skipped |
| GET audit against `mauro_library_dev` (real data): public pages, book page, categories, search AR/EN with and without diacritics, XSS escaping, cover, sitemap, anonymous redirects for `/admin/*` and `/account/*`, reader APIs refused without token, private files not served, security headers, 404s | 0 failures; only `rate_limits` written (throttle counters) |
| Reader flow on a disposable copy of the real data in `mauro_library_test` (writes `reading_sessions`): CSRF required, manifest 164 pages without paths, WebP page/thumbnail, `no-store`, visible per-reader watermark, guest preview 10 pages, page 165 → 404, tampered/extended/replayed tokens refused, token TTL 300 s, no pdf.js | 0 failures; DEV untouched |

## MySQL-only defects found and fixed (Phase H)

Running the SQLite-harness integration tests against MySQL (`MAURO_HARNESS=mysql`, see `tests/Support/TestDatabase.php`)
exposed statements that reuse a named placeholder. SQLite accepts it; MySQL native prepares fail with `HY093`:

- `AdminPasswordAuth::changePassword` (`:n` twice) — an administrator could not change their password on MySQL.
- `AdminPasswordAuth::createAdmin` (`:c` twice) — `bin/console admin:create` failed on MySQL.
- Two fixtures in `tests/Integration/AdminCompletionTest.php` (`:b` twice).
