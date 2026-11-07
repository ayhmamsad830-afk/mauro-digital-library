# MAURO — production readiness (updated 2026-11-07)

Verdict: **ready to deploy once the BLOCKED items below are unblocked by the owner.** Nothing has been deployed.
Launch procedure: `docs/LAUNCH_RUNBOOK.md`.

## Acceptance criteria

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Local application works | ✅ | `http://127.0.0.1:8000` 200; book page, catalogue, search, reader |
| 2 | Production configuration complete | ✅ | `.env.production.example`, `deploy/*`, `docs/LAUNCH_RUNBOOK.md` |
| 3 | Database migrations verified | ⚠️ partial | 0001–0010 verified on the SQLite mirror (`SchemaParityTest`, `MigrationsTest`); **MySQL BLOCKED** |
| 4 | MySQL integration tests have results | ⛔ BLOCKED | 32 tests skipped: MySQL 26.7.0 runs on port 3308 but no valid credentials are configured; owner steps in `docs/MYSQL_VERIFICATION.md` |
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
| 15 | Backups and recovery documented and tested | ⚠️ partial | files backup + verify tested; **MySQL dump/restore BLOCKED** until a MySQL test DB is available |
| 16 | No production secrets committed | ✅ | `.env` ignored; templates contain placeholders only |
| 17 | Local database and original PDF intact | ✅ | SHA-256 identical; 328/328 images decode; SQLite `integrity_check` ok |
| 18 | No unresolved critical/high security issues | ✅ in code | none found; MySQL behaviour and live OAuth remain unverified (see below) |

## Security findings

| Severity | Finding | Status |
|---|---|---|
| High (process) | MySQL-specific behaviour (InnoDB, collation, constraints, `mysqldump`) never exercised | BLOCKED — run the 32 MySQL tests on staging before launch |
| Medium (process) | Live Google Sign-In never exercised end-to-end | BLOCKED — needs client ID + HTTPS domain; verifier logic is tested |
| Medium | `.env.example` defaults to `APP_URL=http://localhost`, `SESSION_SECURE_COOKIE=auto` | Mitigated — use `.env.production.example` (HTTPS URL, secure cookies) |
| Low | Effective page-turn limit ≈ 60/min (reader preloads neighbours; responses are `no-store`) | Accepted — far above reading speed; reader recovers via “retry” |
| Low | `chmod 0640` is a no-op on Windows/NTFS | Accepted — production target is Linux; asserted there |
| Info | Screenshots/photos of the screen cannot be prevented by any website | By design — watermark + short-lived tokens deter and trace |

Fixed during this audit: Windows binary lookup (`tar.exe`, `mysqldump.exe` were invisible to the app);
GNU tar archive paths with drive letters; CRLF tar listings; two browser tests that counted a skip as a pass.

## Test results (this machine, Windows 11, PHP 8.4.25)

PHP: **188 passed, 1 failed, 32 skipped (221)**.
- Failed: `Phase2PagesTest::testWithoutDemoModeNoFictionalDataIsReachable` — asserts “no database configured ⇒ empty
  pages”; the local `.env` holds MySQL credentials that are set but rejected, so the site correctly answers 503.
  On Windows an empty env override cannot be passed to the child server. Passes once `.env` is fixed.
- Skipped: 32 MySQL (credentials).
- Run with `PDF_MIN_FREE_DISK_MB=100` for the test process only: the disk has < 300 MB free, so the real 500 MB
  upload guard (correctly) refuses uploads on this machine. Without the override 25 pipeline/reader tests fail on that guard.

Browser: `ui-check.js` 573/573, card checks 772/772, first-book reader E2E 65/65, co-authors 46/46,
`BrowserUiTest` and `RealBrowserE2ETest` (admin + member + upload→render→read) pass.
