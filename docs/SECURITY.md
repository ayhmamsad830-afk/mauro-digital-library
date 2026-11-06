# Security review (Phase 6)

What protects what, and which automated test proves it. Honest limits are at the end.

## Content protection (protected reader)
| Control | Where | Test |
|---|---|---|
| Original PDFs only in `storage/private/original-pdf` (outside `public/`), random names, 0640 | `PdfUploadService`, `PrivateStorage` | `PrivateFilesNotExposedTest`, `PdfPipelineTest` |
| No route ever returns a PDF; no PDF.js; no download/print UI | controllers, `reader.js` | `PrivateFilesNotExposedTest` (scans controllers), `reader-e2e.js` (no PDF request/content-type) |
| Pages rendered server-side to WebP; per-page delivery with HMAC tokens (5 min) bound to session + reader session | `ReaderTokenService`, `ReaderSessionService` | `ProtectedReaderTest` |
| Visibility + members policy re-checked on **every** page/thumbnail/cover request | `ReaderSessionService::authorize`, `MediaController` | `ProtectedReaderTest`, `AdminCompletionTest` |
| Pseudonymous reader ID + date watermark baked into pixels | `WatermarkService` | `ProtectedReaderTest` (pixel diff) |
| Rate limits on sessions / pages / thumbnails per reader session and per IP | `ReaderApiController` | `ProtectedReaderTest` |

## Application security
| Threat | Control | Test |
|---|---|---|
| SQL injection | PDO prepared statements only, native prepares, LIKE escaped with `ESCAPE '!'` | `SqliteLibraryTest`/`MySqlLibraryTest::testSqlInjectionPayloadsAreInert`, `AdminCompletionTest` (literal `%`) |
| XSS | `e()` everywhere; tokenizer-based scan of every `<?= ?>` in templates; strict CSP (no `unsafe-inline`/`unsafe-eval`, no inline styles/scripts/handlers) | `TemplateEscapingTest`, `HttpFoundationTest`, member/admin XSS tests |
| CSRF | Global middleware on POST/PUT/PATCH/DELETE (form token or `X-CSRF-Token`), token rotated at sign-in | `GoogleSignInTest`, `MemberFeaturesTest`, `AdminHttpTest` |
| Session fixation / hijack | `session.use_strict_mode`, id regenerated at sign-in, HttpOnly + SameSite=Lax (+ Secure on HTTPS), DB re-check of user status/role on every request | `HardeningTest::testSessionCookieIsHardened`, suspension tests |
| Broken access control / IDOR | `AuthMiddleware` + `AdminMiddleware`; every member query scoped by `user_id`; bookmarks deleted only by owner | `MemberFeaturesTest`, `AdminHttpTest`, `AdminCompletionTest` |
| Privilege escalation | admins cannot change their own role/status; last active admin protected | `AdminCompletionTest::testRoleChangesAndGuardRails` |
| Google token forgery / replay | RS256 only, JWKS signature, `iss`/`aud`/`exp`/`iat`, per-session nonce consumed on use, `email_verified`, no account merge by e-mail | `GoogleSignInTest` |
| Malicious uploads | PDF: size, magic bytes, finfo, `pdfinfo`, encryption refused, page limit; images: finfo + getimagesize + dimension limits + **GD re-encode** (payloads dropped) | `PdfPipelineTest`, `AdminCompletionTest::testImageUploadRejections` |
| Open redirects | `safe_redirect_path()` (same-origin path only) | `HardeningTest::testRedirectTargetsAreSameOriginOnly`, `MemberFeaturesTest` |
| Brute force / abuse | DB-backed rate limits: Google sign-in, search, reviews, progress, reader, admin uploads | `HardeningTest`, `GoogleSignInTest`, `MemberFeaturesTest` |
| Spoofed client IP | `X-Forwarded-For` honoured only from `TRUSTED_PROXIES`, right-most untrusted hop | `HardeningTest::testClientIpIgnoresForwardedHeadersUnlessFromTrustedProxy` |
| Clickjacking / sniffing / leaks | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, no `X-Powered-By`, private `Cache-Control` for account/admin/API | `HardeningTest::testSecurityHeadersOnEveryKindOfResponse` |
| Information disclosure | generic error pages (no traces, paths, SQL) when `APP_DEBUG=false`; storage paths/original names never rendered | `HardeningTest::testErrorPagesNeverLeakInternals`, `ErrorHandlerTest` |
| Members-only leakage | hidden from guests in catalogue, search, counts, sitemap, related, authors, covers, reader | `LibraryRepositoryContract`, `HardeningTest::testSitemapListsOnlyPublicBooksWhoeverAsks` |

## Accessibility checks (automated)
`ui-check.js` and `admin-member-check.js` audit every page they visit: labelled form controls, `alt` on images, named buttons/links, exactly one `<h1>`, unique ids, `lang`/`dir`.

## Limits (stated honestly)
- **Screenshots, screen recording, photos of the screen and OCR cannot be prevented by any website.** The design deters and traces (watermark, short-lived tokens, rate limits); it is not DRM.
- MySQL-specific behaviour is verified on the SQLite harness only until a MySQL test database is provided (`docs/MYSQL_VERIFICATION.md`).
- Live Google sign-in is untested until a real `GOOGLE_CLIENT_ID` and authorised origin exist.
