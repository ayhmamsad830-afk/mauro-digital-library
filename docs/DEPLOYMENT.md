# Deployment, Database, Storage and Google OAuth

> Nothing has been deployed. These instructions are for when a host, domain and credentials are confirmed.

## 1. Verify the host first

Upload the project to a **non-public** folder and run (SSH, or the host's "PHP CLI"/cron runner):

```bash
php bin/console env:check
```

Mandatory: PHP ≥ 8.3 (8.4+ target), required extensions, writable storage outside the docroot, `proc_open` enabled, `pdftoppm` + `pdfinfo` present, and a passing **PDF → image round-trip**. If rendering fails, do not work around it with client-side PDF viewing — see `IMPLEMENTATION_PLAN.md` "Hosting fallback".

Recommended `php.ini` / panel values: `upload_max_filesize=510M`, `post_max_size=520M`, `max_input_time=1800`, `memory_limit=256M`, `max_execution_time=120`, `display_errors=Off`, `expose_php=Off`.

## 2. Folder layout

**A. Document root can point to `public/` (preferred)** — upload the whole project, set the vhost/docroot to `.../digital-library/public`.

**B. Host forces `public_html/`** — keep the application in a sibling private folder:

```
/home/account/
├── library_private/      app/ bin/ config/ database/ resources/ routes/ storage/ vendor/ .env
└── public_html/          contents of public/  (index.php, .htaccess, assets/, favicon.svg)
```

Then edit `public_html/index.php` so the bootstrap path points to the private folder:
`require '/home/account/library_private/app/bootstrap.php';` and set `STORAGE_PATH=/home/account/library_private/storage` and `PUBLIC_PATH=/home/account/public_html` in `.env`.
Never place `.env`, `storage/`, PDFs, page images or backups inside `public_html`.

## 3. Web server

Ready-made files in `deploy/`:

| File | Use |
|---|---|
| `deploy/nginx.conf` | Nginx server block: HTTPS redirect, docroot `public/`, only `index.php` executes, 520 MB body limit |
| `deploy/apache-vhost.conf` | Apache 2.4 vhost (docroot `public/`, everything else denied) |
| `public/.htaccess` | Front-controller rewrite, hidden/sensitive files denied (Apache, `AllowOverride All`) |
| `.htaccess` (project root) | Safety net if the whole project was uploaded into the web root by mistake: serves only `public/`, denies `app/`, `storage/`, `.env`… |
| `deploy/php-production.ini` | Recommended PHP values (errors off, upload limits, secure session cookies, `proc_open` allowed) |
| `deploy/crontab.example` | Render worker, cleanup, nightly backup |

## 4. Database installation

```sql
CREATE DATABASE mauro_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dl_app'@'localhost' IDENTIFIED BY '<strong random password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON mauro_library.* TO 'dl_app'@'localhost';
```

Put the credentials in `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), then:

```bash
php bin/console db:check
php bin/console migrate
php bin/console migrate:status
```

Optional, development only: `php bin/console db:seed-dev --yes` loads the fictional sample catalogue into an EMPTY dev database (refused in production; every row is marked `DEV FIXTURE` in `rights_source`).

First administrator: sign in once with Google (Phase 5), then run `php bin/console user:promote <email>`.

After migrating you may revoke `CREATE, ALTER, DROP, INDEX, REFERENCES` from the runtime user and use a separate migration user.
On hosts without a shell, the `.sql` files in `database/migrations` can be imported in order via phpMyAdmin; then insert their names into the `migrations` table.

## 5. Storage

```
storage/private/original-pdf     original uploads (random names)
storage/private/rendered-pages   page masters per file version
storage/private/thumbnails       thumbnails per file version
storage/private/watermark-cache  short-lived per-session images (auto-cleaned)
storage/private/covers           uploaded covers (served through a controller)
storage/private/temp             processing scratch space
storage/logs | backups | cache | sessions
```

Permissions: directories `0750`, files `0640`, owned by the PHP user. `storage/.htaccess` denies web access as defence in depth, but the real protection is that `storage/` is outside the docroot.

## 6. Google Sign-In (Google Identity Services)

1. Google Cloud Console → create a project → *APIs & Services* → *OAuth consent screen* (External; app name = final brand; add privacy policy and terms URLs, support email).
2. *Credentials* → *Create credentials* → *OAuth client ID* → type **Web application**.
3. *Authorized JavaScript origins*: `https://<production-domain>` (and the staging origin, e.g. `https://staging.<domain>`; `http://localhost:8000` only for local testing).
4. The ID-token flow posts the credential to our backend (`POST /api/auth/google`), so no redirect URI is required for the popup/One Tap flow.
5. Copy the **Client ID** into `GOOGLE_CLIENT_ID` in `.env`. The client **secret is not used** by this flow — do not store it in the repo, `.env.example` or JavaScript.
6. The backend verifies the ID token signature (Google JWKS), `iss`, `aud` (= client ID), `exp`, and uses the `sub` claim as the stable account key.

Google Sign-In is **not operational** until steps 1–5 are done on a real domain with HTTPS.

## 6b. E-mail (password recovery, contact notifications)

E-mail is **disabled by default** (`MAIL_DRIVER=disabled`). While it is disabled the site stays honest:
`/password/forgot` says recovery is unavailable, and `/contact` messages are only stored for the
administrators (`/admin/messages`). Nothing is ever claimed as "sent".

Owner decision (2026-11-20): use the **hosting provider's SMTP**. When choosing the host, confirm it offers
SMTP mailboxes on your domain, allows outbound 587/465 from PHP, and lets you publish SPF and DKIM records.

To enable it:

1. Verify the sender domain at the provider: **SPF**, **DKIM** (and a **DMARC** record, `p=none` to start).
2. Server-side `.env` only: `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT` (587 with `MAIL_ENCRYPTION=tls` =
   STARTTLS, or 465 with `ssl`), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` (an address of the
   verified domain), `MAIL_FROM_NAME`. Plain-text SMTP is never used; certificates are verified.
3. `php bin/console env:check` → the `E-mail delivery` line shows `smtp host:port (tls)`.
4. Optional: admin → Settings → `site.contact_email` to receive a copy of each contact message.
5. Test once: request a reset for your own account; the `mail_log` table records only
   kind/status/error (never the body or the token).

Related limits (`config/security.php`): password reset 10/h per IP and 3/h per address, contact 5/h per IP,
member password change 6 per 15 min. Reset links expire after 60 minutes, are single-use, stored only as a
SHA-256 hash, and every other session of the account is signed out after the change.

Migration `0012` creates `password_resets`, `contact_messages` and `mail_log` (run `php bin/console migrate`).

## 7. Cron

See `deploy/crontab.example`:

```
* * * * *   php bin/console render:work --max=5      # PDF render queue
15 * * * *  php bin/console reader:cleanup           # expire reader sessions, purge rate limits + stale temp
30 2 * * *  php bin/console backup:run --keep=14     # database + original PDFs/covers → storage/backups
```

Without cron, admins can still press "Process now" for books up to `PDF_SYNC_MAX_PAGES` pages.

## 8. Backup and recovery

`php bin/console backup:run [--db-only|--files-only] [--keep=14]` writes to `storage/backups/` (outside the docroot):

- `db-<UTC stamp>.sql.gz` — `mysqldump --single-transaction --routines --triggers` (credentials passed through a temporary 0600 defaults file, never on the command line), checked for `-- Dump completed`.
- `files-<UTC stamp>.tar.gz` — original PDFs, covers, author photos (rendered pages are regenerated from originals and are not included).
- each archive has a `.sha256` sidecar; `php bin/console backup:verify <file>` re-checks checksum and completeness; archives older than `--keep` days are pruned.

**Copy the archives off the server** (another provider/region) with retention (e.g. 7 daily, 4 weekly, 6 monthly), and keep an encrypted copy of `.env` (`gpg --symmetric .env`).

### Restore (manual, deliberate — on a staging copy first)

```bash
php bin/console backup:verify storage/backups/db-YYYYmmdd-HHMMSS.sql.gz
gunzip -c storage/backups/db-YYYYmmdd-HHMMSS.sql.gz | mysql --defaults-extra-file=/root/restore.cnf <empty_database>
tar -xzf storage/backups/files-YYYYmmdd-HHMMSS.tar.gz -C <STORAGE_PATH>     # restores private/original-pdf, covers, author-photos
php bin/console migrate:status
php bin/console render:rebuild          # re-queues every current version whose page images are missing
php bin/console render:work --max=50    # or let the cron worker render them
```

Restoring overwrites data: never run it against production without a fresh backup and explicit approval. A backup that has never been restored is not proven — test a restore monthly.

> Status in this build (2026-11-19): the files backup and its verification are tested with both GNU tar and Windows tar. MySQL 8.0.46 is now available locally and manual `mysqldump` backups of `mauro_library_dev` have been made and checksummed, but **`backup:run` (the application's MySQL dump) has not yet been run against MySQL** — planned for the switch to MySQL, and to be verified again on staging/production (including a test restore) before launch. Launch steps: `docs/LAUNCH_RUNBOOK.md`.

## 9. Production checklist

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `SESSION_SECURE_COOKIE=true`, unique `APP_KEY` (`php bin/console key:generate`), `APP_DEMO_UI=false`, MySQL credentials, `GOOGLE_CLIENT_ID`, `TRUSTED_PROXIES` if behind a proxy/CDN.
2. Docroot = `public/` (or the split layout of §2). `.env`, `storage/`, `vendor/` not reachable: `curl -I https://<domain>/.env` → 403/404, `/storage/…` → 403/404, `/app/…` → 403/404.
3. PHP values from `deploy/php-production.ini`; `proc_open` allowed; Poppler installed.
4. `php bin/console env:check` → no `FAIL` (it checks PHP, limits, storage outside docroot, rendering round-trip, APP_KEY/DEBUG/URL, secure cookie, demo off, DB connection + pending migrations, mysqldump, live HTTPS fetch of Google's ID-token signing keys with certificate verification).
5. `php bin/console migrate` then `php bin/console migrate:status` (all applied).
6. Google Cloud: authorised JavaScript origin `https://<domain>`; sign in once, then `php bin/console user:promote <your-email>`.
7. Cron from `deploy/crontab.example` installed; check `storage/logs/cron.log` after a few minutes.
8. Upload one real (licensed) PDF, process it, open the reader as guest and as member; confirm watermark and that `/admin/files` shows it "Ready".
9. Run `php bin/console backup:run`, copy the archive off the server, and test a restore on staging.
10. E-mail: leave `MAIL_DRIVER=disabled` until the provider is approved and its domain verified (§6b).
11. HTTPS with HSTS (sent automatically on HTTPS); staging stays `noindex` (automatic when `APP_ENV != production`); submit `https://<domain>/sitemap.xml` to search engines when ready.
