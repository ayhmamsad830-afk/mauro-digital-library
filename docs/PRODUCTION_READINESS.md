# MAURO — production readiness (updated 2026-11-19)

Verdict: **not yet ready to launch — MySQL is now verified, the remaining open items are listed below
(⚠️/⛔).** Nothing has been deployed. The application still runs on SQLite locally (`DB_DRIVER=sqlite`)
until the controlled switch to MySQL (Phase I).
Launch procedure: `docs/LAUNCH_RUNBOOK.md`.

## Acceptance criteria

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Local application works | ✅ | `http://127.0.0.1:8000` 200; book page, catalogue, search, reader |
| 2 | Production configuration complete | ✅ | `.env.production.example`, `deploy/*`, `docs/LAUNCH_RUNBOOK.md` |
| 3 | Database migrations verified | ✅ | 0001–0011 applied to MySQL 8.0.46 (`mauro_library_dev`, `mauro_library_test`); SQLite mirror parity (`SchemaParityTest`, `MigrationsTest`) |
| 4 | MySQL integration tests have results | ✅ | 32/32 pass; whole suite with `MAURO_HARNESS=mysql`: 261 passed, 1 known failure (KI-1), 0 skipped (2026-11-19) — `docs/MYSQL_VERIFICATION.md` |
| 4b | Real data on MySQL | ✅ | SQLite library imported into `mauro_library_dev` (417 rows), identical row by row — `docs/MYSQL_DATA_IMPORT.md` |
| 5 | qpdf-dependent test has results | ✅ | `PdfPipelineTest::testCorruptAndEncryptedPdfsAreRejected` passes with qpdf 12.4.2 |
| 6 | OpenSSL-dependent tests have results | ✅ | `GoogleSignInTest` 9/9 (needs `OPENSSL_CONF` on Windows for test key generation only) |
| 7 | tar-dependent tests have results | ✅ | `BackupTest` 3/3 with GNU tar and Windows bsdtar |
| 8 | Google authentication configured or documented | ⚠️ documented | verifier tested; live sign-in needs a real client ID + HTTPS domain |
| 9 | Admin authorization verified | ✅ | `AdminHttpTest`, `AdminCompletionTest`, `admin-member-check.js` (real browser) |
| 10 | PDF files remain private | ✅ | outside `public/`; direct URLs 4xx; no PDF in any response |
| 11 | Protected reader works for all 164 pages | ✅ | Playwright: pages 1→164 in order, signed tokens, watermark, tamper/session checks |
| 12 | Both authors display correctly AR/EN | ✅ | 46 browser checks + HTTP/unit tests |
| 13 | Mobile and desktop layouts | ✅ | 375–1440 px, no horizontal overflow |
| 14 | Light and dark themes | ✅ | screenshots + `ui-check.js` |
| 15 | Backups and recovery documented and tested | ⚠️ partial | files backup + verify tested; manual `mysqldump` backups of `mauro_library_dev` made and checksummed; **`bin/console backup:run` against MySQL not yet run** (planned in Phase I) |
| 16 | No production secrets committed | ✅ | `.env` ignored; templates contain placeholders only |
| 17 | Local database and original PDF intact | ✅ | SHA-256 identical; 328/328 images decode; SQLite `integrity_check` ok |
| 18 | No unresolved critical/high security issues | ✅ in code | none found; live OAuth remains unverified (see below); known issue KI-1 (`docs/KNOWN_ISSUES.md`) |

## Security findings

| Severity | Finding | Status |
|---|---|---|
| High (process) | MySQL-specific behaviour (InnoDB, collation, constraints) never exercised | **Resolved 2026-11-19** — 32 MySQL tests + whole suite on MySQL pass; two MySQL-only defects (`HY093`) found and fixed. `backup:run` (mysqldump) on MySQL still to run in Phase I; re-run on the production host before launch |
| Medium (process) | Live Google Sign-In never exercised end-to-end | Open — client ID configured and the button renders on `http://localhost:8000`; a real sign-in still has to be done by the owner, and the production HTTPS domain added to the OAuth client |
| Low | Google Identity Services inline styles are blocked by the CSP on `/login` (6 browser checks) | Accepted — known issue KI-1, CSP not weakened (`docs/KNOWN_ISSUES.md`) |
| Medium | `.env.example` defaults to `APP_URL=http://localhost`, `SESSION_SECURE_COOKIE=auto` | Mitigated — use `.env.production.example` (HTTPS URL, secure cookies) |
| Low | Effective page-turn limit ≈ 60/min (reader preloads neighbours; responses are `no-store`) | Accepted — far above reading speed; reader recovers via “retry” |
| Low | `chmod 0640` is a no-op on Windows/NTFS | Accepted — production target is Linux; asserted there |
| Info | Screenshots/photos of the screen cannot be prevented by any website | By design — watermark + short-lived tokens deter and trace |

Fixed during this audit: Windows binary lookup (`tar.exe`, `mysqldump.exe` were invisible to the app);
GNU tar archive paths with drive letters; CRLF tar listings; two browser tests that counted a skip as a pass.

## Test results — 2026-11-19 (Windows 11, PHP 8.4.25, MySQL 8.0.46, Playwright 1.56.1 / Chromium 141, Poppler 25.07, qpdf 12.4.2)

| Run | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|
| Whole suite, SQLite harness | 261 | 1 | 0 | 4 236 |
| Whole suite, `MAURO_HARNESS=mysql` (`mauro_library_test`, fictional fixtures only) | 261 | 1 | 0 | 4 236 |

- The single failure in both runs is known issue **KI-1** (`docs/KNOWN_ISSUES.md`): `BrowserUiTest` 581/587,
  the 6 failing checks are Google Identity Services inline styles blocked by the CSP on `/login`.
- `RealBrowserE2ETest` (upload → Poppler render → protected reader, admin and member flows) and the qpdf test pass.
- Fixed on 2026-11-19: `ProcessRunner` deadlock on Windows (output to temp files), reader `shield` name
  collision (`TypeError: shield is not a function`), `RealBrowserE2ETest` Windows portability (`env` binary),
  theme baseline updated to Vintage Paper; the 2026-11-07 `Phase2PagesTest` failure below (empty env override on
  Windows) is fixed in `tests/Feature/HttpServer.php`.

## Test results — 2026-11-07 (history)

PHP: **188 passed, 1 failed, 32 skipped (221)**.
- Failed: `Phase2PagesTest::testWithoutDemoModeNoFictionalDataIsReachable` — asserts “no database configured ⇒ empty
  pages”; the local `.env` holds MySQL credentials that are set but rejected, so the site correctly answers 503.
  On Windows an empty env override cannot be passed to the child server. Passes once `.env` is fixed.
- Skipped: 32 MySQL (credentials).
- Run with `PDF_MIN_FREE_DISK_MB=100` for the test process only: the disk has < 300 MB free, so the real 500 MB
  upload guard (correctly) refuses uploads on this machine. Without the override 25 pipeline/reader tests fail on that guard.

Browser: `ui-check.js` 573/573, card checks 772/772, first-book reader E2E 65/65, co-authors 46/46,
`BrowserUiTest` and `RealBrowserE2ETest` (admin + member + upload→render→read) pass.
