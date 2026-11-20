# MAURO — final security audit (Phase J), 2026-11-19

Scope: the application as it now runs locally on **MySQL 8.0.46** (`DB_DRIVER=mysql`, `mauro_library_dev`).
Method: static review of the code + automated suite + live HTTP probes. Read-only: no configuration or data was
changed by the audit (live probes were rejected before any write; only throttle counters in `rate_limits` moved).

**Verdict: no critical, high or medium finding in the application code.** Open items are deployment tasks and
one accepted third-party exception (KI-1).

| Area | Result | Evidence |
|---|---|---|
| Secrets / `.env` | ✅ | `.env`, databases, backups, private files, sessions, logs are git-ignored; `.env` never committed (history checked); no key/password pattern in tracked files; tracked `.sql` files are schema/placeholders only |
| Authentication & sessions | ✅ | cookie `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS (`SESSION_SECURE_COOKIE=true` in production template), `use_strict_mode`, `use_only_cookies`, session id regenerated on login (Google, password admin) |
| CSRF | ✅ | global middleware on every non-GET request (token + Origin check). Live: 10 POST endpoints without token → 419; valid token from a foreign Origin → 403 |
| SQL injection | ✅ | all values bound (native prepares); dynamic fragments are fixed strings; `ORDER BY` from a whitelist; `IN` lists as int placeholders; `LIKE` escaped. Live: SQL-like slug → 404, SQL-like search → 200 normal page |
| XSS | ✅ | `TemplateEscapingTest` parses every `<?= ?>` with the tokenizer (`nl2br` only as `nl2br(e(...))`); search query escaped (live) |
| Roles / admin access | ✅ | every `/admin` route: `AuthMiddleware` + `AdminMiddleware` (role re-checked server-side, absolute lifetime for password sessions, forced password change). Live: 7 admin pages and admin media → login redirect for guests |
| File protection | ✅ | PDFs, renders, backups, `.env`, SQLite outside `public/`; direct URLs → 404 (live) |
| Reader | ✅ | HMAC-signed tokens bound to the session, TTL 300 s; tampered / extended / replayed tokens → 403; guest preview 10 pages; page 165 → 404; `no-store`; no PDF and no pdf.js exposed (live, real book on MySQL) |
| Watermark | ✅ | visible per-reader watermark (`MAURO · GUEST · R-… · UTC time`) on every served page; different per reader |
| Uploads | ✅ | PDF: `is_uploaded_file`, size limit, `finfo` MIME + `%PDF-` signature, `pdfinfo`; images: size, `finfo` + `getimagesize`, dimensions, re-encoded |
| Security headers | ✅ | CSP, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` on pages and 404; no `X-Powered-By` |
| Errors & logging | ✅ | debug output only when `APP_DEBUG=true` **and** not production (forced off in production); errors logged; admin actions and OAuth events audited |
| Tests | ✅ | full suite on MySQL with E2E: 264 passed, 1 known (KI-1), 0 skipped; HTTP test servers and in-process tests isolated from real data |

## Open items (not code defects)

| Item | Severity | Action |
|---|---|---|
| KI-1: Google Identity Services inline styles blocked by the CSP on `/login` | Low (accepted) | none — CSP not weakened (`docs/KNOWN_ISSUES.md`) |
| Local `APP_DEBUG=true` shows stack traces | Info (dev only) | production template has `APP_DEBUG=false` and production forces it off |
| Production `APP_KEY` empty in template | Deployment | generate (`php bin/console key:generate`) on the server; `env:check` fails until set |
| Production HTTPS origin for Google OAuth | Deployment | add the domain to the OAuth client's authorised JavaScript origins |
| `env:check` warnings (local) | Info | `ext-intl` missing, `expose_php On`, `memory_limit 128M`, 777 on Windows — re-run `env:check` on the production host |
| Live Google sign-in by the owner | Process | to do on `http://localhost:8000`, then on the production domain |
| MySQL binary logs (30-day expiry) still contain earlier test inserts | Info (local) | expire automatically; purge only with explicit approval |
| Malware scanning of uploaded PDFs | Low | no scanner installed (admin-only uploads; strict validation) — optional ClamAV on the server |
