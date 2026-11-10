# Cosmic Animations — Implementation Notes

This is an enhancement of the existing MAURO site, not a rebuild. The PHP MVC structure, routes, controllers, database, authentication, Google Sign-In, admin and the protected reader pipeline are unchanged. The work touches only the front-end styles and scripts, two view templates, one config key and the browser test.

## 1. What was implemented

| Area | Effect | Where |
|---|---|---|
| Visual identity | New colour tokens for both themes: deep-space navy `#060a17`, midnight `#0d1428`, electric blue `#4f8cff`, cosmic violet `#8b7cf6`, soft white `#e6ebf7`. Light mode is a bright, cool white with blue and violet accents. Gold and teal were removed from the UI. | `app.css` (tokens), `pages.css`, `components.css` |
| Sky | A fixed `.cosmos` layer behind every public page: a Milky-Way photograph (the landscape reference image, recompressed to 2400 px / 320 KB), soft blue and violet nebula light, and a darkening gradient for readability. Portrait phones use the existing portrait photograph so stars stay sharp. Light mode has no photo, only faint coloured light. | `cosmic.css`, `layouts/main.php` |
| Twinkling stars | 26–80 tiny dots, depending on viewport area (half as many on low-power devices). Only their opacity is animated, on randomised 3.5–8 s cycles. In light mode they are faint ink dots. | `cosmic.js` §1, `cosmic.css` §2 |
| Shooting star | One streak every 20–30 s (the first after 6–12 s), with a blue-white trail and a diagonal fall that follows the reading direction. Never more than one at a time. Skipped while the tab is hidden, in light mode, under reduced motion and on low-power devices. | `cosmic.js` §2, `cosmic.css` §3 |
| Parallax | The sky drifts up to 40 px, and the stars up to 70 px, as the page scrolls. Desktop with a mouse only (at least 1024 px); updates are throttled with requestAnimationFrame. | `cosmic.js` §3 |
| Book cards | The card rises 6 px; the cover gets a soft blue glow and scales to 1.045; transitions are smooth. On touch screens there is no sticky hover, just a small press effect instead. In dark mode the card is translucent midnight. | `components.css` |
| Featured book (hero) | The cover floats slowly (7 s, 10 px) above a breathing contact shadow, with a blue/violet halo and a faint field of fixed stars behind it. Hero content enters in a staggered sequence. The caption now shows the book's real description (up to 3 lines) and both buttons. | `pages.css`, `cosmic.css` §5 |
| Home sections | Featured, Most Read, Categories and Latest are kept. **Most Read** now lists only books with `reads_count > 0` (a real counter, incremented by reader sessions) and hides itself when no book has been read. No ratings, statistics or reviews are invented. | `home/index.php` |
| Scroll reveal | Content that starts **below the fold** fades up 18 px when scrolled into view (IntersectionObserver, up to 320 ms stagger). Content visible on arrival is never hidden. Without JS, without IntersectionObserver, under reduced motion or when printing, nothing is hidden. | `cosmic.js` §4, `cosmic.css` §4 |
| Reader | After the new page has loaded it fades in, sliding 22 px in the direction of travel (mirrored for RTL books). The progress hairline has a blue→violet glow. Loading shows a soft shimmer. Bottom controls are a floating pill. The desk is a calm midnight colour with a dim, static sky; it is a bright paper colour in light mode. | `reader.js` (18 lines), `reader.css` |
| Cursor trail | Eight small blue dots that follow the mouse and fade out. The animation loop runs only while the mouse moves and stops within ~0.6 s of rest. Desktop mouse and dark theme only; off on touch, reduced motion and low-power devices. Can be turned off with `UI_CURSOR_TRAIL=false`. | `cosmic.js` §5, `config/ui.php` |
| Admin | A calm, static version of the sky (no animation), because it is a work tool. | `admin.css` |

## 2. Files

**New:** `public/assets/css/cosmic.css`, `public/assets/js/cosmic.js`, `public/assets/images/background/cosmic-sky.jpg`, this document.

**Modified:** `public/assets/css/app.css`, `components.css`, `pages.css`, `reader.css`, `admin.css`, `public/assets/js/reader.js`, `resources/views/layouts/main.php`, `resources/views/home/index.php`, `config/ui.php`, `.env.example`, `docs/DESIGN_SYSTEM.md`, `tests/browser/ui-check.js`.

**Dependencies:** none added. Everything is vanilla CSS and JS.

## 3. Architecture decisions

- **Decoration lives in its own layer.** `cosmic.css` and `cosmic.js` are loaded last and only on public pages. Deleting the two `<link>`/`<script>` tags and the `.cosmos` div restores a plain background; nothing else depends on them.
- **CSP stays strict.** There are no inline scripts and no `style=""` attributes; the existing `Phase2PagesTest` and `AdminCompletionTest` enforce this. Per-star values (position, size, timing) are set through CSSOM (`el.style.setProperty`), which the CSP allows.
- **The sky is a fixed element, not `background-attachment: fixed`.** A fixed element scrolls on the compositor and can be moved cheaply for parallax.
- **Dark is the base design.** The cosmic rules are written for dark and overridden with `:root[data-theme="light"]`. The server and `theme.js` always set `data-theme` (dark by default), so the theme toggle and its cookie/localStorage persistence work exactly as before.
- **The reader page transition runs after the image `load` event.** It never delays loading, navigation, saving progress or renewing the signed token. It uses the Web Animations API, so no reflow tricks or class juggling are needed.

## 4. Performance

- Only `opacity` and `transform` are animated, so the browser can run the animations on the GPU.
- There is no permanently running JS loop. Stars are CSS animations. The shooting star is a single `setTimeout`. Parallax runs one requestAnimationFrame per scroll event. The cursor trail loop stops when the mouse rests.
- **Page Visibility API:** `cosmos-paused` freezes the stars, the shooting star, the floating book and the halo while the tab is hidden. Shooting stars are also skipped while hidden.
- **Low-power devices** (Save-Data, 2 CPU cores or less, 2 GB memory or less) get half the stars, and no shooting star, parallax or cursor trail.
- **Mobile:** no parallax, no cursor trail, and fewer stars (26 at 375×760).
- **Layout reads before writes:** the reveal setup measures every target first, then adds classes, which avoids layout thrashing.
- **Size added:** `cosmic.css` ≈ 7 KB, `cosmic.js` ≈ 10 KB (both unminified), and the sky photo is 320 KB. The photo is one cached background image, not part of the page content.

## 5. Accessibility

- `prefers-reduced-motion: reduce`:
  - the existing global rule in `app.css` still stops all animations and transitions;
  - `cosmic.js` also skips creating stars, shooting stars, parallax, reveal and the trail;
  - `reader.js` skips the page-turn animation.
- Every decorative layer has `aria-hidden="true"` and `pointer-events: none`, so it can't be focused or clicked.
- Focus styles, keyboard navigation (book cards keep their focus ring and focus-within lift), skip links and drawers are unchanged.
- Text contrast:
  - light mode: primary `#2b55c9` and violet `#5a3fc0` both pass WCAG AA on white;
  - dark mode: body text `#e6ebf7` on navy.
  - Panels and a darkening gradient keep text readable over the photograph.
- Arabic RTL and English LTR:
  - layout uses logical properties throughout;
  - the shooting star, the card offset panel and the reader page slide are mirrored for RTL.

## 6. Security verification

Nothing on the server side changed: no PHP service, middleware, route, policy, rasteriser, watermark or token code was edited. The reader change is limited to a visual `img.animate()` call after load.

Verified:
- **No original PDF exposed.** The reader still loads only signed page-image URLs, and a real-browser check on a 164-page library book requested no `.pdf` at all.
- **Watermarks unchanged.** The watermark is still embedded in the delivered page images (checked in a screenshot of the real book).
- **Strict security policy still in place.** The `HardeningTest` check that the policy never allows inline code still passes, and so does the test that pages contain no inline styles or scripts.
- **Copy protection and token renewal unchanged.** Copy deterrence, signed-token renewal and access checks are the existing code, unmodified.

## 7. Test results (2026-11-10, Windows dev machine)

All of the runs below were actually executed.

| Run | Before the change | After the change |
|---|---|---|
| PHP suite `tests/run.php` (via `bin/dev-php.ps1`, `DB_DRIVER=mysql`) | 224 passed · 4 failed · 1 skipped | **224 passed · 4 failed · 1 skipped** (the same 4) |
| Browser UI `tests/browser/ui-check.js` (demo server, run directly) | — | **577 checks, 6 failed**: all `/login` Google sign-in errors (see below) |
| Extra checks on the real library (port 8000) | — | see list below |

**The 4 PHP-suite failures are environmental and were present before this change:**
1. `BrowserUiTest`: run through `ProcessRunner` on Windows, `ui-check.js` deadlocks, because PHP ignores non-blocking mode on Windows pipes and `fread` blocks while the other pipe fills. The process had to be stopped (exit -1). Run directly, the same script completes (row above).
2. `Phase2PagesTest::testWithoutDemoModeNoFictionalDataIsReachable` and
3. `Phase2PagesTest::testLoginPageDoesNotFakeGoogleButton`: Windows drops the empty `DB_USERNAME=""` and `GOOGLE_CLIENT_ID=""` overrides, so the real `.env` values leak into these tests.
4. `RealBrowserE2ETest`: the test starts the `env` binary, which Windows does not have.

**The 6 browser failures:** Google's sign-in script on `/login` (3 viewports × 2 languages) injects inline styles, which the CSP blocks, and Google returns 403 for the local origin. They are unrelated to this change.

**Tests changed:**
- `ui-check.js` now expects the new body colours (navy `rgb(6, 10, 23)` / white `rgb(245, 247, 252)`) instead of the old ivory and green-charcoal.
- It also has 4 new cosmic checks, all passing:
  - the sky layer and stars render;
  - all scroll-reveal content ends up visible;
  - there are no JS errors;
  - reduced motion disables the stars, reveal and trail.

**Extra Playwright checks on the real library (dev server, port 8000), all passing:**
- Home and `/books`:
  - pages tested: desktop dark AR, desktop light EN, mobile dark AR;
  - all return 200, with no console errors and no horizontal overflow;
  - `dir` is correctly `rtl`/`ltr`.
- Book-card hover lifts the card by about 6 px. The cursor trail shows while the mouse moves and is gone about 0.9 s after it rests.
- Hiding the tab adds `cosmos-paused`.
- Reader on the real 164-page book:
  - "Next" moves to page 2;
  - progress goes 1 → 2 and the hash becomes `#page=2`;
  - one page-turn animation plays;
  - no `.pdf` request is made;
  - the watermark is visible.
- Reduced motion: 0 stars, 0 reveal targets, no trail, no float animation.

## 8. Known limitations

- On large desktop screens the sky photo (2400×928) is shown with `cover` and upscaled slightly on very tall windows. It sits under a darkening gradient, so the softness is barely visible.
- The cursor trail and shooting stars appear only in dark mode, by design: they would look like dirt on a bright background.
- Light mode intentionally no longer shows the photographic night sky. The previous version did, which made text sit directly on the dark photo in light mode. The brief asks for a bright, clean light mode.
- The `/login` browser check fails on this machine before and after this work. Google's sign-in script injects inline styles, which the CSP blocks, and Google rejects the local origin (403) for the configured client ID. This is an environment issue, not part of this change.
- `ProcessRunner` can deadlock on Windows when a child process writes a lot of output (see §7). It was deliberately left unchanged, because it also runs the PDF rasteriser and the brief forbids backend changes without a documented need. Production runs on Linux, where non-blocking pipes work.
