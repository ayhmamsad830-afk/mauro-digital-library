# مُعِين خيري — MAURO

Bilingual (Arabic default / English) **website** for reading licensed books in the browser. Original PDFs stay in private server storage; readers receive only server-rendered, watermarked page images.

Specification: `docs/Cahier_des_Charges_Digital_Library_AR_v1.2.docx` · Audit: `docs/PROJECT_AUDIT.md` · Plan: `docs/IMPLEMENTATION_PLAN.md` · Architecture: `docs/ARCHITECTURE.md` · Deployment: `docs/DEPLOYMENT.md` · Security: `docs/SECURITY.md` · Final report (AR): `docs/FINAL_REPORT_AR.md`

## Status

| Phase | State |
|---|---|
| 0 Audit | Done |
| 1 Foundation (MVC core, router, config, PDO, sessions, CSRF, security headers, errors/logging, layout, 404/500, migrations, console, tests) | Done — DB migrations not yet executed against a live MySQL server (none available in the build sandbox) |
| 2 Design system & UI (AR/EN, RTL/LTR, light/dark, components, all page designs, reader UI, demo data) | Done — see `docs/DESIGN_SYSTEM.md` |
| 3 Library & database (MySqlLibraryRepository, Arabic-normalised search, filters/sort/pagination in SQL, visibility rules, admin books/categories/authors behind auth+admin middleware, brand) | Code + tests done. **MySQL integration tests BLOCKED** until a MySQL test database is provided (they report SKIPPED, never passed). Logic verified on the SQLite harness. Members-only books hidden from guests everywhere (repository + second check in book/reader/page-image controllers). Official logo installed byte-for-byte (`public/assets/images/brand/logo.png`). MySQL steps: `docs/MYSQL_VERIFICATION.md`. |
| 4 Protected reader (secure PDF upload + validation, private storage, Poppler→WebP worker with completeness gate and versioning, signed short-lived page tokens bound to the session, members policy on every request, per-reader pixel watermark, rate limits, member progress) | Done on the SQLite harness + real Poppler/GD; browser E2E passed. MySQL still **BLOCKED**. See `docs/PHASE4_PROTECTED_READER.md` |
| 5 Google Sign-In & member area (GIS popup + server-side RS256 ID-token verification against Google JWKS, nonce, account provisioning by `sub`, suspended accounts refused, audit log; favourites, bookmarks, reviews with rating recompute, history, consented guest-progress import, preferred language) | Code + tests done (token verification tested with a local RSA key standing in for Google). **Live Google sign-in BLOCKED** until `GOOGLE_CLIENT_ID` and an authorised domain are configured. Admin completed: users, review moderation, analytics, activity log, settings, files overview, covers/author photos. See `docs/PHASE5_MEMBERS.md` |
| 6 Security & testing (headers on every response, tokenizer-based template XSS scan, search/upload rate limits, trusted-proxy client IP, sitemap with public books only, automated accessibility audit in the browser suites) | Done — see `docs/SECURITY.md` |
| 7 Deployment prep (`deploy/` nginx/apache/php.ini/crontab, root `.htaccess` safety net, extended `env:check`, `backup:run`/`backup:verify`, `render:rebuild` recovery, production checklist) | Done — files backup/verify tested; **MySQL dump/restore BLOCKED** until a MySQL server is available. See `docs/DEPLOYMENT.md` |

## Requirements

- PHP 8.4+ in production (code also runs on 8.3) with `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json`, `gd` (WebP), `curl`
- MySQL 8.0.16+ or MariaDB 10.6+ (InnoDB, utf8mb4)
- Apache (mod_rewrite) or Nginx, HTTPS in production
- Poppler (`pdftoppm`, `pdfinfo`) and `proc_open` enabled — required for the protected reader
- Composer (optional at runtime: a fallback autoloader is built in)

## Local setup

```bash
cp .env.example .env            # then edit DB_* and APP_URL
php bin/console key:generate    # creates APP_KEY
composer dump-autoload          # or composer install (adds PHPUnit for dev)
php bin/console env:check       # verifies PHP, storage, Poppler + GD round-trip
php bin/console db:check        # tests the MySQL connection
php bin/console migrate         # creates all tables (dev DB; refuses production without --production)
php bin/console db:seed-dev --yes   # optional: FICTIONAL sample books into an EMPTY dev DB
php bin/console user:promote you@example.com   # after your first Google sign-in (Phase 5)
php -S 127.0.0.1:8000 -t public public/index.php
```

Open http://127.0.0.1:8000 — the site starts in Arabic (RTL); the header switches language and theme.

To review the UI before MySQL is connected, set `APP_DEMO_UI=true` (non-production only): fictional, clearly-labelled sample books are shown and `/styleguide`, `/demo/account`, `/demo/admin` and the reader become design previews.

## Tests

```bash
php tests/run.php            # unit + HTTP feature + security tests (no dependencies)
php tests/run.php Router     # filter by name
node tests/browser/ui-check.js http://127.0.0.1:8000 [shots-dir]   # Playwright UI checks (also run by BrowserUiTest)
E2E_SHOTS=/tmp/shots php tests/run.php RealBrowser                  # real reader + admin + member flows in Chromium (demo off, throw-away SQLite)
```

MySQL integration tests need `DB_TEST_DATABASE=<name>_test` (+ `DB_TEST_*` credentials); without them they are reported as **SKIPPED (BLOCKED)**. The same contract tests also run on an in-memory SQLite harness to verify query logic.

Feature tests boot `php -S` with `public/` as document root and make real HTTP requests.

## Console

| Command | Purpose |
|---|---|
| `env:check` | Hosting capability report incl. a real PDF → PNG → WebP render |
| `db:check` | MySQL connectivity + InnoDB check |
| `migrate`, `migrate:status` | Apply / list SQL migrations |
| `key:generate [--force]` | Generate `APP_KEY` |
| `routes` | List routes |
| `search:reindex` | Rebuild the Arabic-normalised `books.search_text` |
| `user:promote <email>` | Grant the admin role to an existing account |
| `db:seed-dev --yes` | Fictional sample catalogue into an empty development DB (refused in production) |
| `dev:sqlite <file>` | Local SQLite harness DB (dev/test only, refused in production) |
| `render:work [--max=N]` | Render queued PDF versions (cron, every minute) |
| `render:rebuild [--all] [--book=ID]` | Re-queue current versions whose page images are missing (after a restore) |
| `reader:cleanup` | Expire reader sessions, purge rate-limit rows and stale temp files (cron, hourly) |
| `backup:run [--db-only\|--files-only] [--keep=14]` | Database dump + original PDFs/covers to `storage/backups` with checksums |
| `backup:verify <file>` | Verify a backup (checksum, dump completeness, archive listing) |
| `brand:favicon` | Regenerate favicons from the official logo (letterboxed, never cropped) |

## Security principles

- Only `public/` is web-accessible. `app/`, `config/`, `storage/`, `.env` are outside it.
- No route ever returns the original PDF; no PDF.js; no download/print UI.
- Screenshots, photos of the screen and OCR **cannot** be prevented by any website. Protection = image-only delivery + baked-in watermark + short-lived tokens + rate limits + access control. It is deterrence and traceability, not DRM.

## Layout

See `docs/ARCHITECTURE.md`.
