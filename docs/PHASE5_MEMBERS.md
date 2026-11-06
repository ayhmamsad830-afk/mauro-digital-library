# Phase 5 — Google Sign-In and member area

## Google Sign-In
- Front end: Google Identity Services (GIS) button, popup mode (`public/assets/js/auth.js`). Only the public **client ID** reaches the browser; no client secret is used or stored.
- `POST /api/auth/google` (CSRF + `google_signin` rate limit 5/15 min) receives the ID token.
- `GoogleIdTokenVerifier` verifies on the server: 3-part JWT, `alg=RS256` only (no `none`/HS*), `kid` in Google's JWKS (cached, one forced refresh for an unknown `kid`), RSA signature with ext-openssl, `iss` ∈ {accounts.google.com, https://accounts.google.com}, `aud` = configured client ID, `exp`/`iat` with 60 s leeway, `nonce` = per-session nonce (consumed on use → replay refused), `email_verified`, optional `GOOGLE_ALLOWED_HD`.
- `GoogleAuthService::signIn`: matches by `google_sub` (never by e-mail alone; an e-mail already bound to another Google account is refused as `email_conflict`), creates `role=user` accounts, refuses `suspended`, regenerates the session id + CSRF token, stores `last_login_at`, writes `oauth_audit_events` (IP as keyed hash; token never stored).
- `POST /logout` (CSRF) destroys the session.

## Member area (`/account*`, AuthMiddleware — active accounts only)
- Favourites toggle, bookmarks (page range checked, 200 per book, owner-scoped delete → 404 for others), history from `reading_progress`, reviews (one per user/book, rating 1–5, comment ≤ 2000, edit keeps a moderator's `hidden` status, rate limit 5/min), rating recomputed from **published** reviews of **active** users only.
- Guest progress (localStorage) is imported only after the member clicks "sync", only for books without cloud progress and only when the file version matches.
- Every list re-checks visibility, so books later unpublished/archived disappear; members-only books show only to members.
- All writes accept JSON (fetch) or plain HTML forms (redirect with flash, open-redirect guarded).

## Tests
`tests/Integration/GoogleSignInTest.php` (verifier rejection matrix, JWKS refresh, provisioning, suspension, e-mail conflict, replay, audit, CSRF, rate limit, 503 when unconfigured, logout) and `tests/Integration/MemberFeaturesTest.php` (auth gates, CSRF, favourites, bookmarks IDOR, XSS escaping, reviews + recompute + hidden, rate limit, suspended reviewers, history, progress import rules).

## Blocked externally
Live Google sign-in needs `GOOGLE_CLIENT_ID` and the site origin added to "Authorised JavaScript origins" in Google Cloud Console.

## Admin completion (Phase 5c)
All behind AuthMiddleware + AdminMiddleware (role re-read from the DB on every request), CSRF on every write, every change written to `admin_activity_logs`.

| Screen | Route | Notes |
|---|---|---|
| Users | `/admin/users` | Search (name/e-mail, LIKE-escaped), role/status filters; suspend/reactivate, grant/remove admin. Cannot change yourself; the last active admin can never be suspended/demoted. Suspension is effective on the user's next request; ratings are recomputed (suspended reviewers do not count). |
| Reviews | `/admin/reviews` | Hide / re-publish; rating recomputed; `moderated_by/at` stored. |
| Analytics | `/admin/analytics?days=7|30|90` | From `reading_sessions` only (no invented figures): sessions per day (members vs guests), pages viewed, new accounts/reviews, top books, devices. Inline SVG with attributes only (strict CSP), table fallback. |
| Activity log | `/admin/logs` | Read-only, filter by entity type. |
| Settings | `/admin/settings` | `catalog.per_page` (drives the catalogue), `home.featured_book_id` (published books only), `site.contact_email` (shown on /copyright). |
| Files | `/admin/files` | Every PDF version with render status / progress; no storage paths. |
| Covers / author photos | book & author edit pages | JPEG/PNG/WebP ≤ `COVER_MAX_UPLOAD_MB`, finfo + getimagesize, 64–4000 px, decoded with GD and **re-encoded to WebP** (drops metadata/payloads), resized (cover 800×1200 box, photo 400×400), random name in `storage/private/covers|author-photos`. Public URLs `/media/covers/{bookId}` and `/media/authors/{id}` re-check visibility (members-only cover → members only; draft → 404). Admin previews under `/admin/media/...`. |

## Browser end-to-end (demo mode OFF)
`tests/Feature/RealBrowserE2ETest.php` builds a throw-away SQLite harness DB + private storage, starts `php -S` with `public/` as root and runs:
- `tests/browser/reader-e2e.js` — admin uploads a real PDF, renders it, guest reads signed watermarked WebP pages.
- `tests/browser/admin-member-check.js` — every admin screen in AR/EN at 390/1280 px, member favourite/review/account pages, cover upload shown publicly, review moderation, settings, suspension → member signed out, activity log.
Set `E2E_SHOTS=<dir>` to keep screenshots.
