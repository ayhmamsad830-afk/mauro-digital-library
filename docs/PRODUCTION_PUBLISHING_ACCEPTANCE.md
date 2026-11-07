# Production publishing — acceptance

**Acceptance requirement:** a website owner can add a new book from the admin dashboard, upload its PDF, process every
page, publish it, find it on the public website and read it — without developer intervention or manual database work.

**Status: ACCEPTED on staging (SQLite).** Production acceptance additionally requires the BLOCKED items below.

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Owner signs in at `/admin/login`, forced to replace a temporary password | ✅ | `BOOK_PUBLISHING_END_TO_END_TEST.md` steps 1–3; `AdminPasswordLoginTest` (8/8) |
| 2 | Owner adds authors and a category from the dashboard | ✅ | steps 4–5 |
| 3 | Owner creates a bilingual book with publication data, chosen author order and rights | ✅ | steps 6–8 |
| 4 | Owner uploads a cover | ✅ | step 9 |
| 5 | Owner uploads a PDF; it is validated and stored privately (byte-identical, random name, outside `public/`) | ✅ | step 10, storage proof |
| 6 | Every page is processed into WebP pages and thumbnails, in the correct order | ✅ | 5/5 pages + 5/5 thumbnails decode and match their PDF page |
| 7 | Publishing is impossible until processing succeeded | ✅ | disabled button + refused forged request; failure scenarios |
| 8 | Owner publishes from the dashboard | ✅ | step 12 |
| 9 | The book is found on home, catalogue, category and search pages | ✅ | step 13 (7 checks) |
| 10 | The book can be read page by page; pages visibly render; thumbnails, navigation and progress work | ✅ | steps 15–18 |
| 11 | Reader protection holds (signed, session-bound URLs; images only; no PDF exposed) | ✅ | step 19 |
| 12 | Failures are reported clearly, never published, and recoverable with Retry; no duplicates | ✅ | `ADMIN_DASHBOARD_FULL_AUDIT.md` (30/30) |
| 13 | Every admin screen works in AR/EN, RTL/LTR, light/dark, mobile/desktop | ✅ | `ADMIN_DASHBOARD_TEST_MATRIX.md` (136/136) |
| 14 | Guests and members cannot reach any admin page or action | ✅ | 39/39 authorization checks |
| 15 | The owner has written instructions | ✅ | `CLIENT_HANDOVER_GUIDE.md` |
| 16 | Behaviour on MySQL (production database) | ⛔ BLOCKED | 32 MySQL integration tests not executed — no valid MySQL test credentials |
| 17 | Behaviour on the production host (Poppler, cron worker, HTTPS, disk) | ⛔ BLOCKED | no host yet; run `docs/LAUNCH_RUNBOOK.md` §8 go/no-go checklist |

## The real library was not used for testing

| Check (after all tests) | Result |
|---|---|
| Original PDF SHA-256 (`29f235d1…d472`) | identical to the source file |
| 164 page images / 164 thumbnails | all present and decodable |
| Book metadata, both authors and their order, category, files, render records | identical to the snapshot taken after the co-author change |
| Books / authors / categories in the real library | 1 / 2 / 1 — no synthetic content |
| Administrator `moein.khairy` | active, Argon2id, own password set; signed in successfully with it at 12:17:03 |
| Reading count | 1 (genuine) — see note |

**Note — one accidental write, reverted.** During a diagnostic of reader rendering, one guest reader session was opened on
the real site, which raised the reading count from 1 to 2. After a verified backup
(`storage/backups/mauro-before-undo-diagnostic-*.sqlite`), exactly that session (id 15, started 2026-11-07 13:15:21,
guest) was removed and the count returned to 1. The genuine session (id 14) is untouched. All further reader checks ran
on staging only.

## Test totals (2026-11-07)

| Suite | Passed | Failed | Skipped | Blocked |
|---|---:|---:|---:|---:|
| Browser — staging (publishing 59, dashboard audit 206, failures 30, top bar 16) | 311 | 0 | 0 | 0 |
| Browser — admin login page | 72 | 0 | 0 | 0 |
| Browser — regression (`ui-check.js`, cards) | 1345 | 0 | 0 | 0 |
| PHP (`php tests/run.php`, 229 tests) | 196 | 1 | 0 | 32 |

The PHP failure is environment-specific (local `.env` MySQL credentials set but rejected → correct 503). MySQL tests are
blocked, not passed.
