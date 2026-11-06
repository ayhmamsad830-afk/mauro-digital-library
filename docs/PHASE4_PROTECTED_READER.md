# Phase 4 — Protected Book Reader

## 1. Infrastructure check (build environment, 2026-11-05)

| Requirement | Result |
|---|---|
| Poppler `pdfinfo` | ✅ `/usr/bin/pdfinfo` (Poppler 24.02.0) |
| Poppler `pdftoppm` | ✅ `/usr/local/bin/pdftoppm` (24.02.0) |
| PHP `proc_open` | ✅ enabled (no `disable_functions`) |
| GD WebP read + write | ✅ `imagecreatefromwebp` / `imagewebp`; FreeType ✅ (watermark text) |
| Private storage | ✅ `storage/private/*` outside `public/`, now `0750`; files written `0640` |
| Free disk | ✅ ~30 GB (check `php bin/console env:check`; renders refuse below `PDF_MIN_FREE_DISK_MB`) |
| Limits | CLI: `max_execution_time=0`, `memory_limit=-1`. **`upload_max_filesize=2M`, `post_max_size=8M` (PHP defaults)** → raise on the host to ≥ 12M / 16M for 10 MB PDFs |
| Measured speed | 25 A4 pages @ 1400 px → WebP + thumbnails in 5.1 s (≈ 0.2 s/page, 2 vCPU) |

`php bin/console env:check` now also reports free disk space, original-pdf permissions and `max_execution_time`.
The production host is still unknown: **run `env:check` on it before launch**. If it lacks Poppler or `proc_open`,
use a VPS or a separate render worker (see `IMPLEMENTATION_PLAN.md` — "Hosting fallback"); never fall back to sending PDFs.

## 2. Pipeline

```
Admin form (multipart, CSRF, admin-only, rate-limited)
  → PdfValidator: upload error codes · size ≤ PDF_MAX_UPLOAD_MB · ".pdf" · finfo = application/pdf · "%PDF-" signature
                  · pdfinfo opens it · 1 ≤ pages ≤ PDF_MAX_PAGES · not password-protected
  → private/original-pdf/{bookId}/{random32hex}.pdf (0640) · SHA-256 · duplicate check
  → book_files (new version_number, validation=valid, render=pending) + book_render_jobs (queued)
     — books.current_file_id is NOT changed

render:work (cron) or "Process now" (≤ PDF_SYNC_MAX_PAGES)
  → atomic claim → pdfinfo re-check → pdftoppm in batches (-scale-to-x PDF_PAGE_WIDTH) into private/temp
  → GD → WebP masters private/rendered-pages/{fileId}/p0001.webp + thumbnails private/thumbnails/{fileId}/t0001.webp
  → completeness gate (every page AND thumbnail present)
  → one transaction: file ready, job completed, books.current_file_id = this version (never downgrades)
  failure → partial images/rows deleted, retry up to PDF_RENDER_MAX_ATTEMPTS, then "failed" (admin can retry);
            the previous version stays active; errors shown to admins contain no paths
```

Publishing still requires a current version that is `valid` + `ready` (Phase 3 rule), so an unprocessed book can never be published.

## 3. Delivery

| Endpoint | Protection |
|---|---|
| `GET /read/{slug}` | visibility + members policy; no PDF URL, path, file name or text layer in the page |
| `POST /api/reader/sessions` | CSRF + rate limit; policy; ready version; returns manifest + token (reuses the session for the same browser) |
| `POST /api/reader/sessions/renew` | CSRF; only sessions created in this browser session |
| `GET /api/books/{id}/reader-manifest` | token |
| `GET /api/books/{id}/pages/{n}` · `/thumbnails/{n}` | token + reading session + policy + version + page range + rate limits (per session and per IP) |
| `POST /api/reading/progress` · `GET /api/reading/progress/{bookId}` | signed-in members; CSRF; version + range validated |

Token: `HMAC-SHA256(APP_KEY, v1|readingSession|book|fileVersionId|exp|hash(sessionBinding))`, TTL `READER_TOKEN_TTL` (300 s),
renewed by the reader ~60 s before expiry and on a failed image load. The binding secret lives only in the server-side PHP
session and is rotated at login/logout, so copied URLs fail in other browsers and all tokens die at logout.
Response headers: `Content-Type: image/webp`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`,
`Cross-Origin-Resource-Policy: same-origin`, `X-Robots-Tag: noindex, noimageindex`.

Responses: 401 expired · 403 invalid/foreign token · 404 not visible (guests never learn that a members-only book exists) ·
409 version replaced (reader reloads) · 429 rate limited · 503 no ready version.

**Watermark** (in pixels, per request): `MAURO · R-XXXXXXXX · YYYY-MM-DD HH:MM UTC` tiled diagonally + footer stamp.
`R-…` is a random per-reading-session pseudonym stored in `reading_sessions` (traceable by admins, no personal data on the image).

**Deterrence in the reader only**: images only (no text layer), `user-select: none`, no drag, no context menu,
Ctrl/Cmd + A/C/X/P/S blocked, reader hidden in print CSS, no download/print buttons.
**Limitation**: screenshots, photos of the screen, saving a received image and OCR cannot be prevented by any website.

## 4. Operations

```
* * * * *   php bin/console render:work --max=5
15 * * * *  php bin/console reader:cleanup
```
Config (`.env`): `PDF_MAX_UPLOAD_MB`, `PDF_MAX_PAGES`, `PDF_PAGE_WIDTH`, `PDF_RENDER_BATCH`, `PDF_RENDER_MAX_ATTEMPTS`,
`PDF_RENDER_STALE_AFTER`, `PDF_MIN_FREE_DISK_MB`, `PDF_SYNC_MAX_PAGES`, `READER_TOKEN_TTL`, `READER_SESSION_TTL`,
`READER_PAGES_PER_MINUTE`, `READER_THUMBS_PER_MINUTE`, `READER_SESSIONS_PER_10MIN`, `WATERMARK_ALPHA`.

## 5. Not verified / pending
- MySQL: all Phase 4 tests ran on the SQLite harness; the MySQL suite (32 tests) is **BLOCKED** (no server/credentials).
- Production host capabilities: unknown until `env:check` runs there.
- Malware scanning of uploads (spec §13.3 "when a suitable tool exists"): no scanner (e.g. ClamAV) is installed; PDFs are only
  ever opened by Poppler on the server and never delivered to browsers.
- Covers and author photos upload: not yet implemented (same private-storage + controller pattern; planned next).
