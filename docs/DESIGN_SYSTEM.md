# Design System (Phase 2)

Live reference: `/styleguide` (demo mode only — `APP_DEMO_UI=true`, never in production).

## Principles
- **Website only**, mobile-first, no framework: HTML + CSS custom properties + vanilla JS.
- **One stylesheet for both directions**: logical properties (`margin-inline`, `inset-inline-start`, …). Directional icons use `icon--dir` and mirror in RTL.
- **CSP-strict**: no inline `<script>`, no `style=""`, no `on*=` handlers (enforced by `Phase2PagesTest`). Dynamic sizes are set via CSSOM from external JS.
- **Honest UI**: no decorative buttons. Features of later phases are shown disabled with an explanation, never faked.

## Files
| File | Contents |
|---|---|
| `public/assets/css/app.css` | Tokens (light/dark), base, layout, header, mobile drawer, footer, demo banner |
| `public/assets/css/components.css` | Buttons, forms, search bar, cards, generated covers, book/category cards, badges, rating, pagination, empty/loading states, alerts, tabs, table, stats, progress |
| `public/assets/css/pages.css` | Home, catalogue, book details, auth, account, legal, styleguide, error pages |
| `public/assets/css/reader.css` | Protected reader (page images only, `user-select: none`, hidden in print) |
| `public/assets/css/admin.css` | Admin shell (sidebar → drawer on mobile) |
| `public/assets/js/theme.js` | Applies saved theme before first paint |
| `public/assets/js/app.js` | Theme toggle, drawers (focus trap, Esc), auto-submit selects, toasts, loading buttons |
| `public/assets/js/reader.js` | Reader controller (navigation, zoom, fit, fullscreen, thumbnails, keyboard, swipe, guest progress, copy deterrence) |

## Tokens
Colour: `--c-bg, --c-surface(-2/-3), --c-text, --c-muted, --c-border(-strong), --c-primary(-strong/-soft), --c-accent(-soft), --c-success/warning/danger(-soft), --c-star, --c-focus`.
Dark values apply with `data-theme="dark"`, or by `prefers-color-scheme: dark` when the visitor has not chosen.
Type scale `--fs-xs … --fs-3xl` (fluid `clamp`), spacing `--sp-1 … --sp-8`, radii, shadows, `--container`, `--gutter`, `--header-h`.

## Theme & language persistence
- Theme: `localStorage.dl_theme` + cookie `dl_theme` (server renders `data-theme` on first byte → no flash).
- Language: POST `/locale` (CSRF) → HttpOnly cookie `dl_locale`, 1 year. Default Arabic; `Accept-Language` deliberately ignored (spec §2).

## Components (`resources/views/components/*.php`, rendered with `component('name', [...])`)
`book-card`, `book-grid` (grid or horizontal rail), `book-row`, `cover` (generated cover when no image), `category-card`, `search-bar` (sm/md/lg), `section-head`, `breadcrumbs`, `pagination`, `empty-state`, `skeleton-grid` (loading), `alert`, `rating`, `lang-switch`, `theme-toggle`.

## Breakpoints
- ≤ 1060px: header search moves into the drawer.
- ≤ 900px: catalogue filters become a drawer; admin sidebar becomes a drawer.
- ≤ 860px: main nav collapses into the mobile drawer (hamburger).
- ≤ 640px: secondary reader/admin tools hidden (`hide-sm`).
- ≤ 420px: compact reader toolbar.

## Demo data (clearly separated)
- Source: `app/Demo/DemoData.php` (fictional titles/authors/figures), served by `App\Demo\DemoLibraryRepository`.
- Enabled only when `APP_DEMO_UI=true` **and** `APP_ENV != production` (`config/ui.php`). A striped banner labels every page.
- Off: `UnavailableLibraryRepository` returns empty results → honest empty states; demo routes (`/styleguide`, `/api/demo/...`) are not registered; `/admin` and `/read/*` return 404; `/account` redirects to `/login`.
- Phase 3 hook: `App\Repositories\LibraryRepositoryFactory::make()` returns the MySQL repository (implements `App\Contracts\LibraryRepository`). Views and controllers do not change.

## Testing
- `php tests/run.php` — includes translation coverage (both languages), placeholder parity, CSP-safe markup, demo isolation, production lock-out.
- `node tests/browser/ui-check.js <base-url> [screenshot-dir]` — Playwright: 18 pages × 3 viewports × 2 languages (no horizontal scroll, no JS errors, correct `lang/dir`), mobile drawer, filters drawer, theme persistence, language switch, reader (image load, navigation, zoom, thumbnails, saved progress, context-menu block, print hiding, no PDF requests). Runs automatically from `BrowserUiTest` when Node + Playwright are present.
