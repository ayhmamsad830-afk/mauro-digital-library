# Pearl Cosmic Light Mode & Reader Protection

This builds on the cosmic redesign (`docs/COSMIC_ANIMATIONS_IMPLEMENTATION.md`). The architecture is unchanged; the existing PDF → WebP pipeline, server-side watermark, signed short-lived image URLs and access policy are all kept and extended.

Priority followed: **security → reading comfort → performance → visual quality.**

---

## Part 1 — Pearl Cosmic light theme

Only the light theme changed; the dark theme is untouched. The theme toggle, the `dl_theme` cookie/localStorage persistence, and the dark default all work as before.

| Element | Pearl Cosmic |
|---|---|
| Base | Pearl white `#f8f8fc`; surfaces pure white; secondary surfaces `#f2f3f9` |
| Text | `#151a30` (≈16:1 on pearl) and muted `#535b75` (≈6.6:1), both well above WCAG AA |
| Accents | Primary blue `#2b55c9`, icy-blue soft fill `#e9effc`, lavender `#6b55c8` (text variant `#5940b8`, AA on white) |
| Sky | **Gradients only, no image.** Icy-blue daylight from the top corner, an extremely faint lavender glow opposite, a soft pearl sheen in the middle, and a pearl→`#f3f4fa` vertical wash |
| Stars | The same stars as dark mode, but small (×0.85), blue-grey, and with the whole layer at 32 % opacity. A few carry a faint lavender halo. No shooting stars and no cursor trail in light mode |
| Panels | Shelves are pearl panels: near-white with a white top highlight, a hairline `#e2e4f2` border and a soft indigo-tinted ambient shadow |
| Book cards | White with a hairline border. On hover the border turns icy and an icy glow appears around the cover, keeping the covers as the focus |
| Shadows | All light shadows are tinted indigo (`rgb(48 52 96 / …)`) instead of grey, for a softer, premium look |
| Reader | Pearl desk (`#f9f9fc` → `#eceef6`), unchanged dark bezel around the page, calm for long reading |

The theme is built with CSS variables in `app.css` and `:root[data-theme="light"]` overrides in `cosmic.css`/`pages.css`. Logical properties keep Arabic RTL and English LTR identical. Verified at 1366 px (AR + EN) and 375 px (AR): no horizontal overflow and no console errors.

---

## Part 2 — Reader protection

### Audit: what was already in place, and is kept

| Mechanism | Where | Status |
|---|---|---|
| PDF → WebP rendering into **private storage** (outside `public/`) | `RenderService`, `PrivateStorage` | unchanged |
| **No route ever returns a PDF**, its path or its original file name | `PrivateFilesNotExposedTest`, `ProtectedReaderTest::testReaderShellExposesNoPdfPathOrFileName` | unchanged, tested |
| Watermark drawn **into the image pixels** on every request (never a CSS overlay) | `WatermarkService`, `PageDeliveryService` | **extended** (below) |
| **Signed URLs**: HMAC-SHA256(APP_KEY) over session, book, file version, expiry and a secret bound to the PHP session | `ReaderTokenService` | unchanged |
| **Expiry**: tokens live `READER_TOKEN_TTL` (300 s) and are renewed by the reader. Reading sessions expire server-side (`READER_SESSION_TTL`) | `ReaderTokenService`, `ReaderSessionService` | unchanged, tested |
| **Authorization on every image request**: token check, book visibility policy (members-only, unpublished, suspended accounts), version check, page range, rate limits | `ReaderSessionService::authorize`, `ReaderApiController::image` | unchanged, tested |
| **Session changes**: sign-in and sign-out rotate the PHP session and drop the token binding, so every outstanding image link dies | `Session::regenerate/invalidate` | unchanged, **new test** |
| **No caching**: page/thumbnail images `Cache-Control: private, no-store, max-age=0` + `Pragma: no-cache`; reader API JSON `no-store` | `PageDeliveryService`, `Response::json` | unchanged, **new test** |
| Copy deterrence in the reader only (no selection, drag, context menu, Ctrl/Cmd+C/A/P/S; print hides pages) | `reader.js`, `reader.css` | unchanged |

### A. Personalised watermark (server-side, in the pixels)

Every page and thumbnail is re-encoded on request with this label:

```
MAURO · M-3F9A2C1B · R-A6365F18 · 2026-11-10 10:30 UTC
brand   member code  session ref  reading date/time (UTC)
```

- **Member code `M-XXXXXXXX`** for signed-in members: the first 8 hex characters of `HMAC-SHA256(APP_KEY, "watermark-member|<user id>")`. It is stable per member and never shows a name, e-mail or raw database id. Only someone with the server's `APP_KEY` can link it to an account. Guests show `GUEST`.
- **Session ref `R-XXXXXXXX`**: the random pseudonym of the reading session. It differs for every reading session.
- **Date and time** of the request, to the minute, in UTC.
- **Owner**: the identity comes from the reading session's `user_id` on the server, never from anything the browser sends.

How it is drawn:
- **Pattern**: a repeated diagonal pattern (25°) across the whole page in neutral slate ink at low opacity (`WATERMARK_ALPHA`, default 98/127 ≈ 23 %), plus a denser footer stamp.
- **Spacing**: the horizontal spacing is now measured from the real rotated text width (`imagettfbbox`), so copies no longer overprint each other. Previously they did, which made the code hard to read.
- **Responsive**: font size and spacing scale with the image width, so the pattern looks the same on every page size and zoom level. Because it is part of the image, it scales with the page on every screen.
- **Arabic and English**: the label uses only Latin letters and digits, on purpose. GD cannot shape Arabic script, and a script-neutral label renders identically on Arabic and English books.
- **Cannot be removed in the browser**: there is no overlay to delete in the developer tools. The unwatermarked master image never leaves the server (tested).

**Tracing a leak (operator, shell access only):**

```
php bin/console watermark:trace M-3F9A2C1B   → the member account
php bin/console watermark:trace R-A6365F18   → the reading session: member (if any), book, start time, device type, pages viewed
```

There is no HTTP endpoint for tracing.

### B. Screenshot deterrence (and its honest limits)

What the reader now does (`reader.js`, "Background shield"):

| Situation | Behaviour |
|---|---|
| Tab hidden (tab switch, minimise, mobile app switcher, `pagehide`) | The page and thumbnails are blurred beyond legibility (`blur(26px)`, 35 % opacity) and a short notice appears ("Reading paused — pages carry a personal watermark…", Arabic or English). Progress is saved at that moment |
| Tab visible again | Restored immediately, on the same page with the same zoom. The page keeps its size, so nothing shifts |
| Window loses focus (another window, devtools, a capture tool overlay) | The same notice appears after 350 ms, and only if focus has not come back. It goes away as soon as the window is focused again, or with one click on "Continue reading", so side-by-side reading is never blocked |
| `PrintScreen` key (only when the browser reports it) | A discreet reminder toast that pages are watermarked (at most once every 10 s). This is **not** screenshot detection |
| Reduced motion | Same protection without transitions |

**What a website cannot do.** No browser gives a web page an event for, or a way to block, operating-system screenshots or screen recording:

| Platform | Reality |
|---|---|
| **Chrome / Edge (Windows)** | `PrintScreen`, `Win+Shift+S` (Snipping Tool) and Game Bar capture the screen without notifying the page. A page may get a `keyup` for `PrintScreen`, but only after the image has been taken. A capture tool's overlay takes focus, but the frozen frame was grabbed before the page could repaint. |
| **Chrome / Edge / Safari (macOS)** | `Cmd+Shift+3/4/5` and screen recording are handled by the OS and never reach the page. |
| **Chrome (Android)** | Screenshots (power + volume) and screen recording send nothing to web pages. The `FLAG_SECURE` window flag that blocks captures exists only for native apps; a web page cannot set it. The app-switcher thumbnail may be taken before the page repaints. |
| **Safari / any browser (iOS, iPadOS)** | Screenshots and Control Centre screen recording send nothing to web pages. The native capture check (`UIScreen.isCaptured`) is not available to the web. The app-switcher snapshot may show the page as it was. |
| **All platforms** | Encrypted Media Extensions (hardware DRM) can black out *video* in captures on some devices, but not images. It would also require a paid DRM licence service, so it was not used. A photo of the screen with a phone can never be prevented by any software. |

Therefore the background shield is **deterrence**, not prevention. The real control against leaks is **traceability**: every captured page carries the reader's member code (or GUEST) and session reference in its pixels. In-app text (`reader.protection_text`) already tells readers that no website can reliably prevent screenshots.

### C. Server-side security verification

| Requirement | Verified by |
|---|---|
| Original PDFs never publicly accessible | `PrivateFilesNotExposedTest` (sensitive paths, public dir contents, no PDF route, stored originals never served, API never declares PDF) + `testReaderShellExposesNoPdfPathOrFileName` |
| Protected WebP images require authorization | `testMissingTamperedOrMisusedTokensAreRejected` (no token, tampered signature, altered expiry, token reused on another book, page out of range) |
| Signed URLs expire | `testExpiredAndOverlongTokensAreRejected` (expired → 401, lifetime beyond TTL → 403, expired reading session → 401) |
| Authorization on every request | `testMembersOnlyPagesFollowTheSamePolicy` (a suspended member is refused on the next page), `testUnpublishingOrReplacingAfterTokenIssueTakesEffectImmediately` |
| Session changes | `testTokensAreBoundToTheBrowserSession`, **new** `testSignInAndSignOutInvalidateOutstandingPageLinks` |
| No public caching | **new** `testProtectedResponsesAreNeverCacheable` (pages, thumbnails, manifest, and 403 errors) |
| No private data in front-end code | **new** `testReaderShellHasBackgroundShieldInBothLanguages` (no identity code in the HTML); the manifest test checks for no paths, file names or secrets; member codes exist only inside image pixels |
| Reading permissions unchanged | `BookAccessPolicy` and `ReaderSessionService::readableBook` not modified; all existing policy tests pass |

---

## Modified files

**Server:**
- `app/Services/Reader/WatermarkService.php`: member code, guest label, measured spacing, neutral ink, `trace()`
- `app/Services/Reader/PageDeliveryService.php`: passes the session owner to the watermark
- `app/Services/Reader/ReaderSessionService.php`: `authorize()` also selects `user_id` (read-only change)
- `app/Controllers/Api/ReaderApiController.php`: hands the session owner to page delivery
- `bin/console`: new `watermark:trace` command

**Front end:**
- `public/assets/js/reader.js`: background shield, `PrintScreen` reminder
- `public/assets/css/reader.css`: shield styles, Pearl desk
- `public/assets/css/app.css`: Pearl light tokens and shadows
- `public/assets/css/cosmic.css`: Pearl sky and stars
- `public/assets/css/pages.css`: Pearl panels, cards, header and featured stage
- `resources/views/reader/show.php`: shield markup and `captureNotice` string
- `resources/lang/en.php`, `resources/lang/ar.php`: 4 new reader strings

**Tests:**
- `tests/Integration/ProtectedReaderTest.php`: 6 new tests
- `tests/browser/ui-check.js`: Pearl background colour; 10 new shield checks (AR + EN, reduced motion)

**Docs:** this file.

No database schema change and no new dependency.

## Test results (2026-11-10, Windows dev machine)

All of these runs were actually executed.

| Run | Result |
|---|---|
| `tests/run.php ProtectedReader` | **21 passed, 0 failed** (15 existing + 6 new) |
| `tests/run.php Security` | **15 passed, 0 failed** |
| `tests/run.php Translation` (AR/EN parity) | **4 passed, 0 failed** |
| `tests/browser/ui-check.js` (demo server, run directly) | **587 checks, 6 failed**: only the 6 `/login` checks broken by Google's sign-in script on this machine (CSP blocks GSI inline styles; Google answers 403 for the local origin). These fail identically before this work. All 10 new shield checks pass. |
| Real library (dev server) | Pearl Cosmic home and catalogue at 1366 px AR/EN and 375 px AR: no overflow, no console errors. The 164-page book shows the measured, readable watermark (`MAURO · GUEST · R-… · 2026-11-10 11:10 UTC`). The shield appears in Arabic. |
| Full suite `tests/run.php` | **230 passed, 4 failed, 1 skipped** (was 224 / 4 / 1; +6 new tests). The 4 failures are the pre-existing Windows environment ones: `BrowserUiTest` (harness deadlock, browser checks run directly instead, row above), two `Phase2PagesTest` cases (Windows drops empty env overrides, so the real `.env` values leak in), and `RealBrowserE2ETest` (no `env` binary on Windows) |

## Remaining risks

- **Screens can always be photographed or captured.** The watermark makes leaks traceable; it cannot stop them.
- **Cropping and editing.** The diagonal pattern repeats across the whole page, so a crop still contains the code, but a determined editor can blur it. Lowering `WATERMARK_ALPHA` (more opaque) trades reading comfort for robustness.
- **Guests are traceable only to a session.** `R-…` links to a reading session (time, device type, hashed IP), not to a person. Books that need person-level traceability should be members-only.
- **`APP_KEY` rotation** changes every member code. Codes from old leaks can then only be traced through the `R-…` session reference.
- **Windows test harness.** `ProcessRunner` can deadlock on Windows when a child process prints a lot (non-blocking pipes are not supported there), so `BrowserUiTest` must be run directly on this machine. Production (Linux) is unaffected; `ProcessRunner` was left unchanged because it also runs the PDF renderer.
