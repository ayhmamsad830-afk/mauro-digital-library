# Implementation Plan

Phases follow the developer brief (§10) and map to the specification's delivery plan (§17). A phase is only marked complete when its code exists **and** has been tested.

| Phase | Scope | Spec refs | Exit criteria |
|---|---|---|---|
| 0 — Audit | Workspace/env audit, plan, architecture | — | `PROJECT_AUDIT.md`, `IMPLEMENTATION_PLAN.md`, `ARCHITECTURE.md` |
| 1 — Foundation | MVC skeleton, front controller, router, config/env, PDO, sessions, CSRF, security headers, error handling + logging, base layout, 404/500, migrations + runner, console, docs | §10, §11, §17 P1 | `php -l` clean, unit + HTTP tests pass, migrations reviewed, DB connectivity check implemented |
| 2 — Design system ✅ | AR/EN translator, RTL/LTR, light/dark, header/footer/mobile drawer, components, designs for all pages incl. reader shell and admin, demo data source behind `LibraryRepository` | §2, §6, §7.2, §15.2 | Language/theme persist; responsive without horizontal scroll (verified by Playwright at 375/768/1280) |
| 3 — Library ✅ (MySQL run pending) | Models/repositories, catalog with SQL pagination, filters (category/author/language), sort (newest/most read/top rated), search (LIKE + Arabic normalisation), categories, authors, latest, popular, book details, related books, sitemap | FR-001…006, §6.2–6.4 | TC-001…004, TC-006 |
| 4 — Protected reader ✅ (MySQL run pending) | PDF upload validation (finfo, magic bytes, `pdfinfo`), SHA-256, random storage names, render jobs (Poppler → WebP), thumbnails, reader sessions + short-lived HMAC page tokens, page/thumbnail endpoints with policy checks, baked watermark (GD), rate limits, reader UI (nav, zoom, fit, fullscreen, thumbnails, copy deterrence, print CSS), guest progress (localStorage), member progress (MySQL) | §7, §13.4, FR-007…011, FR-022…028 | TC-005, TC-007…009, TC-023…029 |
| 5 — Accounts & admin ✅ (live Google + MySQL pending) | Google Identity Services + server-side ID token verification, session regeneration, account pages, favorites, history, bookmarks, reviews, guest-progress sync with consent; admin dashboard: books lifecycle, PDF versions/replace, covers, categories (parent_id, order, active), authors, users (suspend/role), reviews moderation, analytics, activity logs, settings | §8, §9, FR-014…021 | TC-010…017, TC-021 |
| 6 — Security & testing ✅ | Rate limiting everywhere, CSP review, security tests (SQLi, XSS, CSRF, IDOR, upload spoofing, direct storage access, stale session), accessibility, performance | §13, §15, §16 | No critical defects |
| 7 — Deployment prep ✅ (host, MySQL dump/restore, OAuth on real domain pending) | Host capability verification, Apache/Nginx configs, `public_html` split layout, cron jobs, backups + tested restore, OAuth production config | §14, §13.7 | TC-022, launch checklist |

## Key technical decisions

1. **No framework** (per brief). A small in-house core (`app/Core`) provides routing, DI-free services, views and middleware.
2. **Rendering pipeline**: `pdftoppm -r <dpi> -png` per page → GD → WebP (quality configurable) stored under `storage/private/rendered-pages/{file_id}/`. Thumbnails generated in the same pass. Watermark is drawn into the pixels **per request** (reader pseudonym + timestamp + platform name), so the stored master never leaves the server unwatermarked.
3. **Page tokens**: HMAC-SHA256 over `(reader_session_id, book_id, file_id, expiry)` with `APP_KEY`; TTL ~5 min, renewable; bound to the PHP session. Page endpoints also check publication state, visibility and page range.
4. **Background jobs**: `book_render_jobs` table consumed by `php bin/console render:work` from cron; small files may be processed synchronously after upload.
5. **Database**: plain SQL migrations (`database/migrations/NNNN_name.sql`) with a runner that records applied files in a `migrations` table.
6. **Tests**: dependency-free runner now; PHPUnit when Composer packages can be installed.

## Hosting fallback if the provider cannot rasterise PDFs

In order of preference:
1. Choose a VPS / hosting plan that provides Poppler and `proc_open` (recommended).
2. Run a separate trusted render worker (any machine with Poppler + PHP CLI) that reads queued jobs and writes page images to the private storage (shared DB + SFTP/object storage).
3. Admin-side pre-rendering tool that uploads already-rendered page images (keeps the original PDF off the public site).

Rejected: PDF.js or any approach that ships the original PDF to the browser.
