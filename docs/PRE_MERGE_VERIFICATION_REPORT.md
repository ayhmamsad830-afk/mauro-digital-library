# Pre-Merge Verification Report — `cosmic-ui-animations` → `main`

- **Date:** 2026-11-10
- **Machine:** Windows 11, PHP 8.4 (WinGet), Node + Playwright/Chromium 141
- **Scope:** verification only. Nothing was merged or pushed, no branch was deleted and no history was modified. `main` was checked out only to run its tests; the working tree was then returned to `cosmic-ui-animations`.

## Decision

# READY WITH DOCUMENTED BASELINE FAILURES

There are no regressions. Every failure on the branch also occurs on `main`, with the same test names, messages and file:line locations, and every one is caused by this Windows machine's environment. The branch fixes one browser failure that exists on `main`.

Before merging, read three findings that do not block the merge but need a decision (§7): watermark overprinting, an inaccurate claim in one document, and test sessions recorded in the real library.

---

## 1. Baseline comparison: `main` vs `cosmic-ui-animations`

Both suites ran the same way: `bin/dev-php.ps1 tests/run.php`, `DB_DRIVER=mysql`, `OPENSSL_CONF` set, Playwright on `NODE_PATH`.

### PHP suite

| | `main` (e7a14c4) | `cosmic-ui-animations` (947aca6) |
|---|---|---|
| Result | 224 passed · 4 failed · 1 skipped (3759 assertions) | **230 passed · 4 failed · 1 skipped** (3853 assertions) |
| Difference | — | +6 tests, all new, all passing |

The failures are **byte-identical**: a line-by-line `diff` of test names, messages and locations shows no difference.

| # | Test | Message | Location | Cause (environment) |
|---|---|---|---|---|
| 1 | `Feature\BrowserUiTest::testResponsiveLayoutsAndInteractions` | Expected 0, got -1 | `tests/TestCase.php:52` | `ProcessRunner` deadlocks on Windows: PHP ignores `stream_set_blocking(false)` on Windows pipes, so `fread` blocks while the child fills the other pipe and the 400 s timeout is never reached. A watcher stopped the hung `ui-check.js` after ~300 s on both branches. The same browser checks were run directly instead (below). |
| 2 | `Feature\Phase2PagesTest::testWithoutDemoModeNoFictionalDataIsReachable` | Expected 200, got 503 | `tests/TestCase.php:52` | Windows drops the test's empty `DB_USERNAME=""` override, so the real `.env` value leaks in. |
| 3 | `Feature\Phase2PagesTest::testLoginPageDoesNotFakeGoogleButton` | Failed asserting that output contains `google-btn" disabled` | `tests/TestCase.php:60` | Same: the empty `GOOGLE_CLIENT_ID=""` override is dropped, so the real client ID is used. |
| 4 | `Feature\RealBrowserE2ETest::testReaderAdminAndMemberFlowsInARealBrowser` | Unable to start process: env | `app/Services/ProcessRunner.php:43` | The test launches the Unix `env` binary, which Windows does not have. |

Skipped on both: `PdfPipelineTest::testCorruptAndEncryptedPdfsAreRejected` (qpdf not installed).

### Browser checks (`tests/browser/ui-check.js`, run directly against a demo server)

| | `main` | `cosmic-ui-animations` |
|---|---|---|
| Result | 573 checks · **7 failed** | **587 checks · 6 failed** |
| `/login` × 6 (AR/EN × mobile/tablet/desktop) | fail | fail, **identical text** |
| `theme: body background matches` | **fails on `main`** (`rgb(5, 7, 11)`: the starry-sky commit changed the colour but not the test) | passes |
| New checks (cosmic ×4, shield ×10) | — | all pass |

All six `/login` failures have one root cause, the same on both branches: Google's sign-in script logs `[GSI_LOGGER]: The given origin is not allowed for the given client ID` (HTTP 403) and injects inline styles that the site's CSP blocks. It needs the local origin added in Google Cloud Console, or a test-only client ID. It is unrelated to this branch.

**Conclusion:** all 4 PHP failures and all 6 `/login` browser failures existed before these changes. **No new regressions.**

## 2. New implementation, verified on its own

Each new test was run on its own (`tests/run.php <name>`):

| Test | Result |
|---|---|
| `testWatermarkLabelCarriesPrivacySafeIdentity` | ✅ 16 assertions |
| `testMemberPagesCarryTheirOwnWatermarkInThePixels` | ✅ 10 assertions |
| `testWatermarkCodesTraceBackToTheReader` | ✅ 11 assertions |
| `testSignInAndSignOutInvalidateOutstandingPageLinks` | ✅ 12 assertions |
| `testProtectedResponsesAreNeverCacheable` | ✅ 19 assertions |
| `testReaderShellHasBackgroundShieldInBothLanguages` | ✅ 11 assertions |
| Existing: tampered/misused tokens, expired tokens, browser-session binding | ✅ 14 / 8 / 7 assertions |
| `PrivateFilesNotExposed` group / `Hardening` group | ✅ 5 tests (218 assertions) / 7 tests (189 assertions) |
| Browser: shield in AR (normal motion) and EN (reduced motion) — page obscured when hidden, notice in the page language, restored on return still on page 2, focus loss shields and one click resumes, no JS errors | ✅ 10 / 10 checks |

**Server-side code inspected (not just tests).** The entire server diff is four small changes:
- `ReaderSessionService::authorize()` now also *reads* `user_id` (a SELECT column; validation logic unchanged).
- `ReaderApiController::image()` passes that owner id to `PageDeliveryService::render()`.
- `PageDeliveryService::render()` gained an optional `$userId`, which is forwarded to `WatermarkService::label()`.
- `WatermarkService`: `memberCode()` = `'M-' . upper(substr(HMAC-SHA256(APP_KEY, "watermark-member|<id>"), 0, 8))`, a GUEST label, neutral ink, measured spacing, and console-only `trace()`.

The identity comes from the server-side reading-session row and is never taken from the request.

## 3. Security review

Live HTTP probes were run against a server using a **copy** of the library database (so the real read counters were not touched), plus inspection of the diff.

| Requirement | Evidence | Result |
|---|---|---|
| Original PDFs not publicly accessible | `/storage/mauro.sqlite`, `/private/covers/…`, `/storage/private/…`, `/.env`, `/app/bootstrap.php`, `/api/books/1/pdf`, `/api/books/1/original` → **404**. Reader HTML and manifest contain no `.pdf`, `private/`, storage path, secret or e-mail. `PrivateFilesNotExposed` ✅ | ✅ |
| Protected WebP requires authorization | no token **403** · tampered signature **403** · altered expiry **403** · copied URL without cookies **403** · URL in another browser session **403** · page 0 / beyond end **404** · session start without CSRF **419** | ✅ |
| Signed URLs expire | `testExpiredAndOverlongTokensAreRejected` (expired → 401, lifetime beyond TTL → 403, server-side session expiry → 401). `ReaderTokenService` is unchanged (TTL 300 s) | ✅ |
| No public caching | Live: `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache`, `nosniff`, `CORP: same-origin` on pages and thumbnails. JSON is `no-store`. Test covers 403 responses too | ✅ |
| User identifiers privacy-safe | The label contains only `M-<8 hex>` (HMAC under APP_KEY), `GUEST`, `R-<random>` and the time; the test asserts there is no name, e-mail or raw id. Codes exist only in image pixels, never in HTML or JS | ✅ |
| Tracing works with the server secret | `php bin/console watermark:trace R-2A72A56C` → the reading session (book, start time, device type, pages viewed). `M-` codes are matched by recomputing the HMAC with APP_KEY. Unknown codes → nothing; malformed input such as `R-1' OR '1'='1` → rejected by the format check. No HTTP endpoint | ✅ |
| No sensitive data in front-end assets | `git grep` of `public/assets/js`, `public/assets/css`, reader/layout views for keys, secrets, e-mails and storage paths: only the existing admin show/hide-password UI matched. The reader only sends `book_id`, `rs`, `page` and `file_version` | ✅ |
| Existing controls not weakened | **No changes** to `app/Middleware/*` (CSP/headers), `ReaderTokenService`, `BookAccessPolicy`, `Session`, `Auth`, `routes/*`, `config/security.php`, `PrivateStorage`, `MediaController`, `.htaccess` files. CSP still has no `unsafe-inline` (Hardening ✅) | ✅ |

## 4. Author metadata — «رُكون الظلام»

The change is **data only**: it is in `storage/mauro.sqlite`, not in Git; the Git diff contains no change to authorship.

| | Before (backup `storage/backups/mauro-before-author-fix-20261110-105935.sqlite`) | Now |
|---|---|---|
| Book ↔ author links | 1. مُعِين خيري أبوعائشة (order 1) · 2. محمد سليمان اليونسي (order 2) | 1. مُعِين خيري أبوعائشة (order 1) |
| Author records | id 1 مُعِين خيري أبوعائشة · id 2 محمد سليمان اليونسي | **both still exist** (neither deleted) |
| Title, slug, visibility | unchanged | unchanged |

- **What changed exactly:** one row in `book_authors` (book 1 ↔ author 2) was deleted, and the book's search text was re-indexed. This was done on the owner's explicit instruction in this session ("remove the name of محمد سليمان اليونسي from the author, just مُعِين خيري أبوعائشة").
- **Matches the previously approved information?** **No.** The previously specified attribution listed both authors; the current metadata lists only مُعِين خيري أبوعائشة. Restoring the link is one row from the backup; it is not done without confirmation.

**Cover (also data only, also on explicit instruction in this session):** the cover was replaced with the supplied artwork through `ImageUploadService::cover()`, the same validation, re-encoding and private storage as an admin upload. `private/covers/51a75efc….webp` became `private/covers/61a0d692….webp` (800×1041 WebP, 55 KB). Backups: `storage/backups/cover-before-change-51a75efc.webp` and `storage/backups/mauro-before-cover-change-20261110-112747.sqlite`. Verified on `/books/rukun-al-zalam`.

## 5. Git review

| | |
|---|---|
| Current branch | `cosmic-ui-animations` |
| HEAD | `947aca61ce99799d204aec332bd10b250a8e2206` |
| `main` HEAD | `e7a14c465bcc0844fe99ba3fa0bad8455c2c4dcb` (= merge base, so the merge would be a fast-forward) |
| Commits on `main` not in the branch | none |

**Commits ahead of `main`:**
1. `fb346e956cd86ea3332309733165443829968aeb` Cosmic UI: night-sky palette, twinkling/shooting stars, reveal, reader page turns
2. `947aca61ce99799d204aec332bd10b250a8e2206` Pearl Cosmic light theme; personalised watermark and reader background shield

**Diff `main...cosmic-ui-animations`:** 26 files, +1309 / −232.

| Area | Files |
|---|---|
| Server (reader) | `app/Controllers/Api/ReaderApiController.php` (+2/−1), `app/Services/Reader/PageDeliveryService.php` (+6/−2), `ReaderSessionService.php` (+1/−1), `WatermarkService.php` (+79 net), `bin/console` (+19) |
| Config | `config/ui.php` (+4, `UI_CURSOR_TRAIL`), `.env.example` (+3) |
| Front end | `public/assets/css/{app,components,pages,reader,admin}.css`, **new** `cosmic.css`, **new** `public/assets/js/cosmic.js`, `reader.js` (+61), **new** `public/assets/images/background/cosmic-sky.jpg` (328 KB) |
| Views and language | `resources/views/layouts/main.php`, `home/index.php`, `reader/show.php`, `resources/lang/{ar,en}.php` (+4 keys each) |
| Tests | `tests/Integration/ProtectedReaderTest.php` (+118), `tests/browser/ui-check.js` (+74/−) |
| Docs | **new** `COSMIC_ANIMATIONS_IMPLEMENTATION.md`, **new** `PEARL_COSMIC_AND_READER_PROTECTION.md`, `DESIGN_SYSTEM.md` (+3) |

- **Working tree:** clean before verification. Afterwards the only change is this report (`docs/PRE_MERGE_VERIFICATION_REPORT.md`, untracked, not committed).
- **Unrelated changes in Git:** none. There are no database migrations, no dependency changes, and no `.env` or secret files. `storage/` is git-ignored.

## 6. Data outside Git touched in this session (real library, `storage/mauro.sqlite`)

| Change | Requested? | Backup |
|---|---|---|
| Author link removed (§4) | Yes, explicitly | `mauro-before-author-fix-20261110-105935.sqlite` |
| Cover replaced (§4) | Yes, explicitly | `cover-before-change-51a75efc.webp`, `mauro-before-cover-change-20261110-112747.sqlite` |
| **3 guest reading sessions (#19 10:30, #20 11:09, #21 11:10 UTC)** | **No.** Side effect of automated browser checks run against the real library earlier today | They raised `reads_count` of «رُكون الظلام» from 4 to 7. Not reverted without confirmation |

## 7. Findings that need a decision (non-blocking)

1. **Watermark copies still overprint each other along the diagonals.** The full 1400-px page shows copies from adjacent rows overlapping. `main` has the same behaviour with its shorter label, so this is **not a regression**. The footer stamp (`MAURO · GUEST · R-2A72A56C · 2026-11-10 11:36 UTC`) stays fully legible, and many tile fragments are readable. **Proposed fix:** place copies along the 25° text direction, with lines spaced perpendicular to it, instead of a horizontal grid.
2. **One documentation claim is inaccurate.** `docs/PEARL_COSMIC_AND_READER_PROTECTION.md` says the measured spacing means copies "never overprint each other". As shown in finding 1, that is only true within a row. The sentence should be corrected together with the fix.
3. **Real analytics inflated by 3 test reads (§6).** Options: delete sessions #19–#21 and set `reads_count` back to 4, or leave as is.
4. **Windows test harness** (not caused by this branch): `ProcessRunner` deadlock, empty-env overrides, and the missing `env` binary make 4 suite tests fail on this machine. The Google Cloud Console origin setting makes the 6 `/login` browser checks fail.

No merge or push has been done. Waiting for explicit authorization.
