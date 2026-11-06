# Project Audit — Digital Library (Phase 0)

Date: 2026-11-05
Specification: `docs/Cahier_des_Charges_Digital_Library_AR_v1.2.docx` (v1.2, read in full: sections 1–19 and appendices A–B).
Overriding instructions: the developer brief of 2026-11-05 (user clarifications override the document where they conflict).

## 1. Workspace state before work started

| Check | Result |
|---|---|
| Existing project code | **None.** The workspace contained only user dotfiles (`.cache`, `.config`, `.npm`, …). No PHP project, no `composer.json`, no database dump. |
| Git | Workspace was **not** a Git repository. A new repository was initialised in `digital-library/` (branch `main`). Nothing was reset, discarded or overwritten. No commits were made yet. |
| Specification | Copied unchanged into `docs/`. |

The project is therefore a **greenfield build**.

## 2. Development environment (this build sandbox — NOT the production host)

| Capability | Found | Spec / brief requirement | Status |
|---|---|---|---|
| PHP | 8.3.6 CLI | 8.4+ (prefer 8.5) | ⚠️ Older than target. Code is written to run on **8.3 and 8.4/8.5** (no 8.4-only syntax such as property hooks). Production must use 8.4+. |
| Composer | Installed | Required | ✅ Works offline for `dump-autoload`. ❌ Package downloads impossible (no outbound network for the shell). |
| PHP extensions | pdo_mysql, mbstring, intl, fileinfo, openssl, curl, gd, sodium, json, zip | PDO MySQL, Fileinfo, OpenSSL, cURL, mbstring, JSON, GD/Imagick | ✅ All required extensions present. Imagick absent (GD is sufficient). |
| GD WebP | Yes | WebP preferred | ✅ |
| GD AVIF | No | Optional | ➖ Not needed; WebP chosen. |
| Poppler `pdftoppm` / `pdftocairo` / `pdfinfo` | 24.02.0 | Poppler or MuPDF | ✅ Available locally. |
| MuPDF `mutool` | Not installed | Alternative | ➖ Not required. |
| `proc_open` / `exec` | Enabled | Needed to call Poppler from PHP | ✅ |
| MySQL / MariaDB server | **Not installed**, Docker daemon not running | MySQL InnoDB | ❌ **Blocker for live DB tests in this sandbox.** Migrations are written and syntax-reviewed but cannot be executed here. |
| PHPUnit | Not installed, cannot be downloaded | PHPUnit | ⚠️ A dependency-free test runner (`tests/run.php`) is used now; `phpunit/phpunit` is declared in `composer.json` `require-dev` for environments with network access. |
| Web server | PHP built-in server (`php -S`) | Apache or Nginx | ✅ For local verification; Apache `.htaccess` and an Nginx sample config are provided. |
| upload_max_filesize / post_max_size | 2M / 8M (CLI defaults) | ≥ 10 MB PDFs | ⚠️ Must be raised on the host (documented). |

## 3. Production hosting

No hosting provider, domain, SSH, cPanel or credentials were supplied. **Nothing was deployed** and no production database was touched.

The hosting decision is the single largest infrastructure risk (spec §14.1, §18.1): the protected reader requires

1. a PDF rasteriser (Poppler `pdftoppm`/`pdftocairo` or MuPDF `mutool`) callable from PHP (`proc_open` not disabled);
2. GD (with WebP) or Imagick for watermarking;
3. storage outside the document root;
4. cron (or a worker) for background rendering of large books.

Many low-cost shared hosts disable `proc_open` and do not ship Poppler. The project includes a capability checker (`php bin/console env:check`) that must be run **on the candidate host before purchase/launch**. If the host fails it, the compatible alternatives (documented in `IMPLEMENTATION_PLAN.md` §5) are: a VPS, or a separate trusted render worker that writes page images into private storage. A client-side PDF.js fallback is **rejected** because it would expose the original PDF.

## 4. Conflicts and clarifications between spec and brief/environment

| # | Item | Resolution |
|---|---|---|
| C1 | Spec §14.3 example lists `public_html/pdfjs/` | Contradicts §2, §7.1 and the brief. **No PDF.js anywhere.** Treated as a stale example. |
| C2 | Spec §12.2 lists `GET /api/books/{id}/metadata` *and* `/reader-manifest` | Both kept; `metadata` returns public catalogue data only; `reader-manifest` requires a reader session. Neither returns file paths or the PDF. |
| C3 | Spec says AVIF/WebP; brief says "WebP, AVIF or other" | WebP via GD (AVIF unsupported in this GD build). JPEG fallback when WebP is unavailable. |
| C4 | Spec §8.3 bullet about publishing rules sits inside the OAuth section | Treated as a book-lifecycle rule (§9.3). |
| C5 | PHP 8.4+ required; sandbox has 8.3 | Code kept 8.3-compatible; `composer.json` requires `>=8.3`; production docs require 8.4+. |
| C6 | Brief module list includes "Website settings"; spec has no settings table | A `settings` key/value table is added. |
| C7 | Spec requires rate limiting; no Redis guaranteed on shared hosting | A DB/file-backed limiter will be used (Phase 6), no external service required. |
| C8 | Google ID token verification "with a trusted library" | Preferred: `google/apiclient` or `firebase/php-jwt` via Composer once network is available. Fallback: native OpenSSL RS256 verification against Google JWKS (to be reviewed). Decision in Phase 5. |

## 5. Blocked by missing external configuration

- MySQL server (local or hosted) and credentials → needed to run migrations and all DB-backed features.
- Google OAuth Web Client ID and authorised JavaScript origins → Google Sign-In cannot be operational until configured.
- Hosting provider and domain → deployment, HTTPS, OAuth origins, capability verification.
- Project brand name → watermark text uses `APP_NAME` placeholder `Digital Library`.
- Legally cleared book content → no real books are bundled; no fake data is seeded in production.
