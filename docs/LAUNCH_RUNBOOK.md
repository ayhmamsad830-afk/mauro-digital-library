# MAURO — first production launch runbook

> Status: **prepared, not executed.** Nothing has been deployed. This runbook is executed only after the
> owner supplies a domain, a host and a Google OAuth client ID, and explicitly approves each gated step (⛔).
> General reference: `docs/DEPLOYMENT.md`. Security model: `docs/SECURITY.md`.

## 0. Hosting requirements (verify before choosing a host)

| Requirement | Why | Check on the host |
|---|---|---|
| PHP **8.3+** (developed and tested on 8.4) | language features | `php -v` |
| Extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json`, `gd` **with WebP** | DB, uploads, Google token signatures, page images | `php bin/console env:check` |
| `proc_open` **not** disabled | server-side PDF rendering | `env:check` |
| Poppler `pdftoppm` + `pdfinfo` | PDF → WebP pages | `env:check` round-trip must say OK |
| MySQL 8+ / MariaDB 10.6+, InnoDB, utf8mb4 | data | `php bin/console db:check` |
| Shell (SSH) **or** a cron runner that can run `php bin/console …` | migrations, render worker, backups | — |
| Document root settable to `public/` (or the split layout in DEPLOYMENT §2) | private storage outside the web root | — |
| HTTPS certificate (e.g. Let's Encrypt) | Google Sign-In requires HTTPS; secure cookies | — |
| Disk: ≥ 2 GB free + ~15 MB per 100 rendered pages | originals, page images, backups (`PDF_MIN_FREE_DISK_MB=500` guard) | `df -h` |

**Recommendation:** a small VPS (1 vCPU / 1–2 GB RAM, Ubuntu 24.04 LTS, Nginx + PHP-FPM 8.4 + MySQL 8 +
`poppler-utils`). Typical shared hosting disables `proc_open` and has no Poppler, which would break
the protected reader; **do not** “fix” that by serving PDFs to the browser.

Ubuntu packages: `nginx php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-gd php8.4-xml php8.4-curl mysql-server poppler-utils`
(`fileinfo`, `openssl`, `json` are built into Ubuntu's PHP).

## 1. Server preparation

```bash
# as a sudo user on the server
sudo adduser --system --group --home /srv/mauro mauro
sudo -u mauro git clone https://github.com/ayhmamsad830-afk/mauro-digital-library.git /srv/mauro/app
cd /srv/mauro/app
sudo -u mauro cp .env.production.example .env          # then fill every <…>
sudo -u mauro php bin/console key:generate              # writes APP_KEY
sudo chown -R mauro:www-data storage && sudo find storage -type d -exec chmod 0750 {} + && sudo find storage -type f -exec chmod 0640 {} +
sudo chmod 0640 .env
```

The repository is public and contains **no** books, PDFs, page images, databases or secrets. Book files
are uploaded through the admin panel only and live in `storage/private/` on the server.

Web server: `deploy/nginx.conf` (or `deploy/apache-vhost.conf`) with root `/srv/mauro/app/public`;
PHP values from `deploy/php-production.ini`; HTTPS with `certbot --nginx -d <domain>`.

## 2. Database ⛔ (production database — explicit approval required)

```sql
-- as MySQL root, once
CREATE DATABASE mauro_library CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mauro_app'@'localhost' IDENTIFIED BY '<strong random password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON mauro_library.* TO 'mauro_app'@'localhost';
-- mysqldump (backup:run) also reads triggers/views of this database:
GRANT TRIGGER, SHOW VIEW ON mauro_library.* TO 'mauro_app'@'localhost';
-- staging rehearsal database (same server is fine)
CREATE DATABASE mauro_library_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON mauro_library_staging.* TO 'mauro_app'@'localhost';
```

Procedure (every time migrations run against production):

1. **Verify the target** — `php bin/console db:check` prints host, port and database name; confirm it is the intended one.
2. **Rehearse on staging** — `DB_DATABASE=mauro_library_staging php bin/console migrate` then `migrate:status` (all applied).
3. **Back up** — `php bin/console backup:run --db-only` (empty on first launch, still run it to prove the tool works).
4. **Verify the backup** — `php bin/console backup:verify storage/backups/db-<stamp>.sql.gz` → `complete SQL dump`.
5. ⛔ **Owner approval**, then `php bin/console migrate --production` and `php bin/console migrate:status`.
6. **Recovery** — migrations are additive (no DROP of user data). To recover: restore the verified dump into an
   empty database (DEPLOYMENT §8) and point `DB_DATABASE` at it.

## 3. Google Sign-In and the first administrator

Owner actions in Google Cloud Console (nothing here can be done without the owner's Google account):

1. Create a project → *APIs & Services* → *OAuth consent screen*: External, app name **MAURO**, support e-mail,
   home page `https://<domain>`, privacy `https://<domain>/privacy`, terms `https://<domain>/terms`.
2. *Credentials* → *Create credentials* → *OAuth client ID* → **Web application**.
3. *Authorized JavaScript origins*: `https://<domain>` (plus `https://staging.<domain>` if used).
   No redirect URI is needed (ID-token flow posts to `POST /api/auth/google`).
4. Copy the **Client ID** into `GOOGLE_CLIENT_ID` in the server `.env`. The client **secret is not used** — do not store it anywhere.
5. Leave `GOOGLE_ALLOWED_HD` **empty** to accept any verified Google account (personal Gmail included). Set it only to
   restrict sign-in to a Google Workspace domain (e.g. `example.org`).
6. Publishing status: while the consent screen is in *Testing*, only listed test users can sign in — add the owner's
   e-mail as a test user, or *Publish app* before opening sign-in to the public (basic scopes need no Google review).

The server verifies every ID token itself: RS256 signature against Google's JWKS, `iss` = accounts.google.com,
`aud` = `GOOGLE_CLIENT_ID`, `exp`/`iat`/`nbf` (60 s leeway), the per-session nonce, and `email_verified`.

First admin (there is no automatic promotion and no development login in production):

```bash
# 1. the owner signs in once on https://<domain>/login with their real Google account
# 2. on the server:
php bin/console user:promote <owner-google-email>     # refuses unknown or suspended accounts
```

`admin@mauro.local` exists only in the local development SQLite file; it is never migrated and cannot sign in.

## 3b. Administrator username/password sign-in (`/admin/login`)

Independent of Google. Accounts are created and reset **only from a shell on the server** — there is no public
registration and no automatic promotion. Passwords are typed at a hidden prompt, stored only as Argon2id hashes
(bcrypt where Argon2id is unavailable) and never accepted from arguments, files, pipes or `.env`.

```bash
# after `php bin/console migrate --production` (migration 0011 adds the columns)
php bin/console admin:create --name="Moein khairy" --username=moein.khairy
#   → shows the target database, asks you to type its name, then a temporary password twice (hidden)
# sign in at https://<domain>/admin/login → you must choose a new private password immediately
php bin/console admin:reset-password moein.khairy     # lost password: new temporary password, forced change
```

Protections: CSRF + Origin checks, 5 attempts per username and 20 per IP per 15 minutes (HTTP 429), generic
“username or password is incorrect” messages (no account probing), session id regenerated at sign-in, Secure +
HttpOnly + SameSite=Lax cookies on HTTPS (`SESSION_SECURE_COOKIE=true`), absolute 8-hour admin session
(`ADMIN_SESSION_MAX`), audit log entries `admin.login`, `admin.login_failed`, `admin.password_changed`,
`admin.password_reset`, `admin.logout`. Members, suspended and demoted accounts cannot use it.

## 4. Publish the first book — «رُكون الظلام»

Re-create it through the admin panel on production (the same validated pipeline as locally), using the
exact metadata in `docs/launch/rukun-al-zalam.metadata.json`:

1. *Authors* → create, in this order: **مُعِين خيري أبوعائشة** (`moein-khairy-abu-aisha`), **محمد سليمان اليونسي** (`mohammed-suleiman-al-younsi`).
2. *Categories* → **روايات / Novels** (`novels`).
3. *Books* → new: titles, slug `rukun-al-zalam`, publisher, edition, ISBN, year, both descriptions (paste from the JSON),
   tick authors **first مُعِين, then محمد** (the form keeps that order), category, public, featured, rights `owned`.
4. Upload the cover (`rukun-al-zalam-cover.jpg`, kept by the owner) and the original PDF.
5. Before upload, confirm the file is the original:
   `sha256sum "رُكون الظلام.pdf"` → `29f235d1aaed2ec7cd9fa14e736d3b3808011cd6411836f2c909319ff4d5d472`.
6. Rendering (164 pages > `PDF_SYNC_MAX_PAGES`): the cron worker processes it within minutes, or run
   `php bin/console render:work --max=50`. `/admin/files` must show **Ready, 164 pages**.
7. Publish. Then open the reader as a guest and turn pages; confirm the watermark.

## 5. Cron (render worker, cleanup, backups)

```
* * * * *   cd /srv/mauro/app && php bin/console render:work --max=5   >> storage/logs/cron.log 2>&1
15 * * * *  cd /srv/mauro/app && php bin/console reader:cleanup        >> storage/logs/cron.log 2>&1
30 2 * * *  cd /srv/mauro/app && php bin/console backup:run --keep=14  >> storage/logs/cron.log 2>&1
```

Install with `sudo -u mauro crontab -e`. Copy `storage/backups/*` off the server daily (another provider/region).

## 6. Health checks after deployment

```bash
php bin/console env:check                      # no FAIL
php bin/console migrate:status                 # all applied
curl -sI https://<domain>/ | head -1           # 200
curl -sI https://<domain>/.env | head -1       # 403/404
curl -sI https://<domain>/storage/ | head -1   # 403/404
curl -sI https://<domain>/books/rukun-al-zalam | grep -i 'strict-transport\|content-security\|x-frame'
```

Browser: home, catalogue, book page (both authors, AR and EN), reader page 1 → 164, sign-in, `/admin` as admin
(and 403 as a normal member).

## 7. Deployment and rollback

- Deploy a release: `git fetch && git checkout <tag>` → `php bin/console migrate --production` (after §2 steps) → reload PHP-FPM.
- Roll back code: `git checkout <previous tag>` → reload PHP-FPM. Migrations are additive, so older code keeps working
  with the newer schema; if not, restore the pre-deploy dump (§2.6).
- Never deploy without a verified backup from the same day.

## 8. Go / no-go checklist (all must be ✅ before the public announcement)

| # | Check | How |
|---|---|---|
| 1 | Host meets §0 (PHP 8.3+, `proc_open`, Poppler, GD WebP, MySQL 8+/MariaDB 10.6+, cron, HTTPS, ≥ 2 GB free) | `php bin/console env:check` → no FAIL |
| 2 | 32 MySQL integration tests pass on a `*_test` database | `php tests/run.php MySql` (staging) |
| 3 | Migrations rehearsed on staging, then applied to production with approval | `migrate:status` all applied |
| 4 | Fresh DB + files backup taken and verified | `backup:run`, `backup:verify` |
| 5 | Restore rehearsed into an empty staging DB | DEPLOYMENT §8 |
| 6 | `.env`: production, debug off, HTTPS URL, secure cookies, demo off, unique APP_KEY | `env:check` |
| 7 | `/.env`, `/storage/`, `/app/` return 403/404 | §6 curl checks |
| 8 | Google sign-in works on the real domain; owner promoted with `user:promote` | sign in, open `/admin` |
| 9 | A normal member gets 403 on `/admin` | second Google account |
| 10 | «رُكون الظلام»: SHA-256 matches, `/admin/files` Ready 164 pages, both authors in order, published | §4 |
| 11 | Reader as guest: page 1 → 164, watermark visible, no PDF request in DevTools | browser |
| 12 | Cron running (render, cleanup, nightly backup) and backups copied off-server | `storage/logs/cron.log` |
