# Admin dashboard — test matrix

Run: 2026-11-07, isolated staging (disposable SQLite + separate private storage), Chromium via Playwright,
real browser interface only. Source logs: `admin-audit.js`, `publish-e2e.js`, `failures.js` (kept out of the repo).

## Totals

| Suite | Passed | Failed | Skipped | Blocked |
|---|---:|---:|---:|---:|
| Admin screens × AR/EN × light/dark × 390/1280 px | 136 | 0 | 0 | 0 |
| Admin module functions (CRUD, persistence, lifecycle) | 31 | 0 | 0 | 0 |
| Authorization (guest + member, every admin route) | 39 | 0 | 0 | 0 |
| New-book publishing, owner workflow end to end | 59 | 0 | 0 | 0 |
| Failure & recovery scenarios | 30 | 0 | 0 | 0 |
| Admin login page (AR/EN × light/dark × mobile/desktop) | 72 | 0 | 0 | 0 |
| Admin top bar: change password + sign out (AR/EN × 390/1280) | 16 | 0 | 0 | 0 |
| **Browser total** | **383** | **0** | **0** | **0** |
| PHP suite (`php tests/run.php`) | 196 | 1 | 0 | 32 (MySQL) |
| Regression `ui-check.js` / card checks | 573 / 772 | 0 | 0 | 0 |

PHP: the 1 failure is `Phase2PagesTest::testWithoutDemoModeNoFictionalDataIsReachable` (the local `.env` holds MySQL
credentials that are set but rejected, so the site correctly answers 503). The 32 MySQL tests are **blocked, not
passed** — no valid MySQL test credentials exist on this machine.

## Every admin screen in every presentation

| Screen | ar/light/390 | ar/light/1280 | ar/dark/390 | ar/dark/1280 | en/light/390 | en/light/1280 | en/dark/390 | en/dark/1280 |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `/admin` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/books` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/books/create` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/books/{id}` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/categories` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/categories/create` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/categories/{id}` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/authors` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/authors/create` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/authors/{id}` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/files` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/users` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/reviews` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/analytics` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/logs` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/settings` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/admin/password` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

**136 / 136 screen renders passed** (HTTP 200, exactly one `<h1>`, correct `lang`/`dir`, no horizontal overflow, no JavaScript or CSP errors).

## Module functions

| # | Module | Checks | Result |
|---|---|---|---|
| 1 | Dashboard | counts equal database values (published books, users) | ✅ |
| 2 | Book list | search, status filter, 20 per page, page 2 | ✅ ✅ ✅ ✅ |
| 3 | Create / edit | validation (empty form, bad ISBN), old input kept, edit survives reload | ✅ ✅ ✅ |
| 4 | Authors & order | ranks follow click order; order kept on re-save; edit form shows ranks | ✅ ✅ ✅ |
| 5 | Categories | create, edit persists, deactivate → public 404, reactivate → 200 | ✅ ✅ ✅ ✅ |
| 6 | Cover | upload + preview, replace (new version), remove, upload again | ✅ ✅ ✅ ✅ |
| 7 | PDF | upload, process, Ready, replace with v2 (v1 stays live until v2 is processed) | ✅ ✅ |
| 8 | Lifecycle | publish only when processed; unpublish → 404; archive; restore → draft; republish | ✅ ✅ ✅ ✅ |
| 9 | Users | suspend → member signed out immediately; reactivate | ✅ ✅ |
| 10 | Reviews | member review appears; hide → gone publicly; re-publish | ✅ ✅ ✅ |
| 11 | Analytics | renders with data | ✅ |
| 12 | Settings | save persists; invalid e-mail rejected | ✅ ✅ |
| 13 | Activity log | readable labels for sign-ins and publishing, no raw keys | ✅ |
| 14 | Admin auth | temporary password forces change; other pages locked until changed; top-bar change-password link and sign-out on every screen | ✅ ✅ ✅ ✅ |

## Authorization

| Who | Request | Expected | Result |
|---|---|---|---|
| Guest | GET on all 17 admin routes | 302 → `/admin/login` | 17 ✅ |
| Member | GET on all 17 admin routes | 403 | 17 ✅ |
| Member | POST categories / publish / change role / settings | 403 | 4 ✅ |
| Member | promote itself to admin | role stays `user` | ✅ |
