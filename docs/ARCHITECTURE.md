# Architecture

## Directory layout

```
digital-library/
├── app/
│   ├── Core/          Kernel, Router, Request, Response, View, Config, Env, Database,
│   │                  Session, Csrf, ErrorHandler, Logger, HttpException, Migrator
│   ├── Controllers/   Thin HTTP controllers (one per area)
│   ├── Models/        Database entities / repositories (PDO, prepared statements)
│   ├── Services/      Business logic (rendering, watermark, progress, search, auth…)
│   ├── Middleware/    SecurityHeaders, Csrf, (Auth, Admin, RateLimit, ReaderSession)
│   ├── Validators/    Input validation
│   ├── Support/       helpers.php (e(), __(), url(), csrf_field()…)
│   └── bootstrap.php  Autoload + env + config + error handler
├── bin/console        CLI: migrate, migrate:status, db:check, env:check
├── config/            app, database, session, security, storage, rendering, google
├── database/migrations  Ordered *.sql files (MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4)
├── public/            ← the ONLY web document root
│   ├── index.php      Front controller
│   ├── .htaccess      Rewrite + deny dotfiles
│   └── assets/        css, js, images
├── resources/
│   ├── lang/          ar.php, en.php
│   └── views/         layouts, partials, errors, pages
├── routes/            web.php, api.php, admin.php
├── storage/           (outside document root)
│   ├── private/       original-pdf, rendered-pages, thumbnails, watermark-cache, temp, covers
│   ├── logs/          app-YYYY-MM-DD.log
│   ├── backups/
│   ├── cache/
│   └── sessions/
└── tests/             Unit, Feature, Security + run.php
```

## Request lifecycle

```
Browser → Apache/Nginx (docroot = public/) → public/index.php
  → app/bootstrap.php (autoload, .env, config, ErrorHandler, timezone)
  → Kernel::handle(Request)
      → Session::start() (secure cookie params, strict mode)
      → global middleware: SecurityHeaders → Csrf (non-GET)
      → Router::dispatch() → route middleware → Controller@action
      → Controller calls Services/Models → returns Response (View/JSON/image)
  → Response::send()
Errors: HttpException → error view (HTML) or JSON (for /api/*); any other Throwable → logged + 500.
```

## Layer responsibilities

| Layer | Responsibility | Must not |
|---|---|---|
| Controller | Parse request, call validator/service, choose response | Contain SQL or business rules |
| Service | Business rules, transactions, file processing | Echo output |
| Model | SQL via `Database` (prepared statements only) | Know about HTTP |
| Middleware | Cross-cutting checks (CSRF, auth, roles, rate limits, headers) | Render pages (except error redirects) |
| View | Escape and render data (`e()`) | Run queries |

## Protected reader (target design, implemented in Phase 4)

```
Admin upload → PdfValidationService (size, ext, finfo MIME, %PDF magic, pdfinfo pages)
  → storage/private/original-pdf/<random>.pdf  (+ sha256, book_files row, version)
  → book_render_jobs (queued) → render:work (cron) → pdftoppm → GD → WebP
  → storage/private/rendered-pages/<file_id>/p0001.webp  + thumbnails/<file_id>/t0001.webp
  → book_page_renders rows; book_files.render_status = ready
  → admin publishes (requires valid file + rights data) → books.current_file_id switched in a transaction

Reader:  GET /read/{slug}  (HTML shell, no file URLs)
  POST /api/reader/sessions  (CSRF) → reading_sessions row + HMAC token (short TTL)
  GET  /api/books/{id}/reader-manifest       → page count, file version, dimensions
  GET  /api/books/{id}/pages/{n}?t=<token>   → policy + token + range + rate limit
         → load private master → bake watermark (GD) → image/webp,
           Cache-Control: private, no-store; X-Content-Type-Options: nosniff
```

There is **no** route that streams the original PDF, and no storage directory under `public/`.

## Security baseline (Phase 1)

- Only `public/` is web-accessible; `.htaccess` denies dotfiles; `index.php` is the only PHP entry point.
- Security headers: CSP (self + Google Identity Services origins), `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: DENY`, `Permissions-Policy`, HSTS on HTTPS.
- Sessions: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, strict mode, custom name, server-side files under `storage/sessions`.
- CSRF: per-session token, validated for POST/PUT/PATCH/DELETE (form field or `X-CSRF-Token` header), constant-time comparison.
- PDO: `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `utf8mb4`.
- Errors: `display_errors` off unless `APP_DEBUG=true`; all exceptions logged with a request id; user sees a generic page.

## Database (see `database/migrations`)

Core tables from spec §11.1: users, authors, categories, books, book_authors, book_categories, book_files, book_page_renders, book_render_jobs, reading_progress, reading_sessions, favorites, bookmarks, reviews, admin_activity_logs, oauth_audit_events. Added: `settings` (website settings), `rate_limits` (abuse protection), `migrations` (runner bookkeeping).

`books.current_file_id → book_files.id` is a nullable FK added after both tables exist (circular reference). Deleting books is restricted where versions/sessions must be kept; archiving is preferred (`status = archived`, `deleted_at`).

## Catalogue data source (Phase 2)

```
Controller → LibraryRepositoryFactory::make() → LibraryRepository (interface, app/Contracts)
                 ├─ DemoLibraryRepository        app/Demo (fictional; non-production + APP_DEMO_UI only)
                 ├─ UnavailableLibraryRepository app/Repositories (empty → empty states)
                 └─ MySqlLibraryRepository       Phase 3 (PDO, SQL pagination)
```

Query input is validated by `App\Support\BookQuery` (whitelisted sort/per-page, slug patterns, 100-char normalised search) and results are returned as `App\Support\Paginator`. Arabic search folding: `App\Services\ArabicNormalizer` (used for `books.search_text` in Phase 3).

### View data shapes
- **Book**: `id, slug, title, description, language, publisher, publication_year, page_count, cover_url|null, cover_tone (1-8), authors[{id,slug,name}], categories[{id,slug,name_ar,name_en,tone}], rating_avg, rating_count, reads_count, published_at, visibility, rights_status, license_name, is_featured`
- **Category**: `id, slug, name_ar, name_en, description_ar, description_en, tone, books_count`
- **Author**: `id, slug, name, books_count`

### Reader contract
The reader view receives a manifest `{book, fileVersion, pageCount, pageUrl, thumbUrl, aspect, dir}` with URL templates ending in `/0`. Phase 2 points them at demo GD-generated pages (`/api/demo/...`, watermark baked into pixels); Phase 4 points them at token-protected `/api/books/{id}/pages/{n}` backed by private storage. `reader.js` does not change. The server-side watermark font is bundled in `resources/fonts` (DejaVu Sans, free licence) so hosts need no system fonts.

## Phase 3 — database-backed library

### Repository selection (`LibraryRepositoryFactory`)
1. `APP_DEMO_UI=true` and not production → `DemoLibraryRepository` (fictional, labelled).
2. Database configured → `MySqlLibraryRepository`.
3. Not configured → `UnavailableLibraryRepository` (empty states). A configured-but-down database yields **503**, never demo data.

### `MySqlLibraryRepository`
- Visibility everywhere: `status='published' AND deleted_at IS NULL AND published_at <= now` (drafts, archived, soft-deleted and scheduled books are invisible in lists, details, counts, related, authors, languages).
- Search: query folded with `ArabicNormalizer`, split into ≤ 5 tokens, each `search_text LIKE :qN ESCAPE '!'` (AND). Wildcards in user input are escaped. `search_text` = title + authors + category names (AR/EN) + publisher + description, maintained by `SearchIndexer` inside the same transaction as every write.
- Filters: category (includes direct sub-categories via `parent_id`; active categories only), author slug, language. Sort whitelist: newest / popular (`reads_count`) / rating / title. COUNT + LIMIT/OFFSET in SQL; out-of-range pages clamp.
- Hydration: authors and active categories for a page of books in 2 batched `IN (...)` queries (no N+1).
- Every value is a bound parameter; placeholder names are unique per statement (required by MySQL native prepares). The SQL is portable so the same class runs on the SQLite test harness.

### Admin (Phase 3 scope)
- `/admin/*` behind `AuthMiddleware` (session `user_id` → active user re-read from the DB on each request; suspension ends the session) and `AdminMiddleware` (role=admin, else 403). CSRF on every write.
- Books: list/search/status filter, create, edit (PUT), publish / unpublish / archive / restore. Publishing requires ≥ 1 author, ≥ 1 category, rights status + source, and a current file version that is `valid` + `ready` with pages — real publishing therefore becomes possible once Phase 4 processes PDFs.
- Categories (bilingual names, parent with cycle prevention, order, activate/deactivate) and authors (create/edit; renames re-index their books).
- Every write: validator → service → single transaction → `admin_activity_logs`. No file uploads in Phase 3.

### Schema
No migration was changed. `database/sqlite/schema.sql` mirrors the migrations for the dev/test harness; `SchemaParityTest` fails if tables or columns drift.
