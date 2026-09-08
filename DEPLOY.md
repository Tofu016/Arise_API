# Deploying the ARISE API

Repo: `github.com/Tofu016/Arise_API` · branch `main`

This is a **manual runbook**. You SSH into the server and run the commands
yourself. Part A is done once per server; Part B is every update.

The app is CodeIgniter 3.1.13 (PHP), talking to MySQL/MariaDB, with
uploaded images on a local disk. It expects **Apache + `mod_rewrite`** —
the root `.htaccess` forwards the `Authorization` header to PHP, which
bearer-token auth depends on. nginx works but you'd have to translate
that rule yourself.

Everything environment-specific lives in `.env` (git-ignored). The code
reads it via `index.php`; `.env.example` is the committed template.

---

## Prerequisites on the server

| Need | Notes |
|---|---|
| PHP 8.1 | CI3 3.1.13 supports 7.2–8.1. 8.2/8.3 run but emit deprecation noise. |
| PHP extensions | `mysqli`, `curl`, `mbstring`, `xml`, `gd`, `intl`, `fileinfo`, `openssl`, `zip` |
| Composer 2 | to install `phpmailer` + `vlucas/phpdotenv` |
| MySQL 8 / MariaDB 10.4+ | `utf8mb4` |
| Apache 2.4 + `mod_rewrite` | `AllowOverride All` on the site directory so `.htaccess` is honoured |
| Git | for clone / pull |

Ubuntu example:

```bash
sudo apt update
sudo apt install -y apache2 mysql-server git unzip \
  php8.1 libapache2-mod-php8.1 php8.1-cli \
  php8.1-mysql php8.1-curl php8.1-mbstring php8.1-xml php8.1-gd php8.1-intl php8.1-zip
sudo a2enmod rewrite
# Composer:
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

---

## Part A — First-time server setup

### A1. Get the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/Tofu016/Arise_API.git arise-api
sudo chown -R $USER:www-data /var/www/arise-api
cd /var/www/arise-api
```

### A2. Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

`composer.lock` is committed, so this installs the exact versions used in
development. `vendor/` is git-ignored and never committed.

### A3. Create the upload directories

For now the uploaded images live **inside** the deployment folder
(`uploads/` and `protected-uploads/`, both git-ignored). Create them and
make them writable by the web server:

```bash
cd /var/www/arise-api
mkdir -p uploads protected-uploads
sudo chown -R www-data:www-data uploads protected-uploads
sudo chmod -R 775 uploads protected-uploads
```

- `uploads/` — public tour images. Sits under DocumentRoot, so Apache
  serves it directly at `https://…/uploads/…` (no extra config).
- `protected-uploads/` — login-gated images, streamed only through
  `IndoorUploads_API::serve()` after an auth check. It **also** sits
  under DocumentRoot, so A7 and A8 explicitly forbid Apache from serving
  it — that deny rule is the only thing keeping those images private.
  (A sturdier option — moving this folder outside the web root entirely —
  was deliberately deferred; revisit it before this handles anything
  genuinely sensitive.)

> **Rollback caution:** because these folders are git-ignored, `git clean
> -fdx` would delete every uploaded image. Part C uses `git reset` only,
> never `git clean`.

### A4. Configure `.env`

```bash
cp .env.example .env
nano .env
```

Set for production (see the full key reference at the bottom):

```
CI_ENV=production
BASE_URL=https://api.yourdomain.edu.ph/
CORS_ORIGIN=https://app.yourdomain.edu.ph
DB_HOST=localhost
DB_USER=arise
DB_PASS=<a real password>
DB_NAME=arise_web
UPLOAD_ROOT=/var/www/arise-api/uploads/
PROTECTED_UPLOAD_ROOT=/var/www/arise-api/protected-uploads/
SMTP_HOST=<transactional provider>
SMTP_PORT=587
SMTP_USER=<...>
SMTP_PASS=<...>
SMTP_FROM_EMAIL=noreply@sdca.edu.ph
SMTP_FROM_NAME="ARISE Campus Navigator"
```

- **`CI_ENV=production`** turns off `db_debug` and hides PHP errors. Do
  not skip this — `development` leaks SQL and file paths on any error.
- **`BASE_URL`** must match what the front-end was built against
  (`VITE_API_BASE_URL` minus the `/index.php`). URLs keep `/index.php/`
  in the path unless you add a rewrite rule (see notes).
- **`CORS_ORIGIN`** is the exact scheme + host of the deployed web app,
  no trailing slash. Native apps (Expo) don't need it.
- **`UPLOAD_ROOT` / `PROTECTED_UPLOAD_ROOT`** — set both explicitly to
  absolute paths (trailing slash). Don't leave them blank on the server:
  the blank-value fallback for `PROTECTED_UPLOAD_ROOT` resolves to two
  directories *above* `index.php`, which won't be what you want here.

### A5. Create the database

```bash
sudo mysql -e "CREATE DATABASE arise_web CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
sudo mysql -e "CREATE USER 'arise'@'localhost' IDENTIFIED BY '<same password as DB_PASS>'"
sudo mysql -e "GRANT ALL PRIVILEGES ON arise_web.* TO 'arise'@'localhost'; FLUSH PRIVILEGES"
mysql -u arise -p arise_web < schema.sql
```

Then create the first admin account by hand — follow **`SEED.md`**
(a fresh database has no accounts, and nobody can self-promote to
`admin`).

### A6. Make the writable directories writable

```bash
sudo chown -R www-data:www-data application/logs application/cache
sudo chmod -R 775 application/logs application/cache
```

### A7. Apache virtual host

```apache
<VirtualHost *:80>
    ServerName api.yourdomain.edu.ph
    DocumentRoot /var/www/arise-api

    <Directory /var/www/arise-api>
        AllowOverride All
        Require all granted
    </Directory>

    # Public tour images — served directly (the folder is under DocumentRoot)
    <Directory /var/www/arise-api/uploads>
        Require all granted
        Options -Indexes
    </Directory>

    # Login-gated images — Apache must NEVER serve these directly; the only
    # legitimate way in is IndoorUploads_API::serve() after an auth check.
    <Directory /var/www/arise-api/protected-uploads>
        Require all denied
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/arise-api-error.log
    CustomLog ${APACHE_LOG_DIR}/arise-api-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite arise-api      # after saving as /etc/apache2/sites-available/arise-api.conf
sudo systemctl reload apache2
```

### A8. Block sensitive files from the web

DocumentRoot is the repo root, so `.env`, `.git/`, `schema.sql`,
`SEED.md`, `DEPLOY.md`, `composer.lock` are otherwise reachable by URL.
Append to the **root `.htaccess`**:

```apache
# --- deployment hardening ---
RewriteRule (^|/)\.(?!well-known)      - [F]      # any dotfile / dotdir (.env, .git)
RewriteRule ^protected-uploads/       - [F]      # belt-and-braces with the vhost <Directory> deny
<FilesMatch "\.(sql|md|lock)$">                  # schema.sql, *.md, composer.lock
    Require all denied
</FilesMatch>
# composer.json is intentionally left servable — blocking "*.json" would
# also block /.well-known/assetlinks.json, which Android App Links needs
# if the mobile app ships.
```

The `protected-uploads/` line repeats the vhost `<Directory>` deny from
A7 on purpose — if someone later edits the vhost and drops that block,
this still holds. Both exist because these images have no other guard.

Verify:

```bash
curl -I https://api.yourdomain.edu.ph/.env                                 # 403
curl -I https://api.yourdomain.edu.ph/protected-uploads/panoramas/x.jpg    # 403
```

### A9. PHP upload limits

Panoramas are 5–30 MB; PHP's defaults reject them. In the active
`php.ini` (`php --ini` to find it, usually
`/etc/php/8.1/apache2/php.ini`):

```ini
upload_max_filesize = 64M
post_max_size       = 72M
memory_limit        = 256M
max_execution_time  = 120
max_input_time      = 120
```

```bash
sudo systemctl restart apache2
```

### A10. Cron — the outgoing-email worker

`Cron_API::processEmails` sends anything queued in `email_queue`. It is
CLI-only (`is_cli_request()` guards it). `crontab -e`:

```cron
*/2 * * * * cd /var/www/arise-api && /usr/bin/php index.php Cron_API processEmails >> /var/log/arise-cron.log 2>&1
```

Test once by hand first: `cd /var/www/arise-api && php index.php Cron_API processEmails`
→ `Processed: 0 sent, 0 failed.`

### A11. HTTPS

Get a certificate before going live — the PWA service worker and any
future mobile app require it:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d api.yourdomain.edu.ph
```

(Or a Cloudflare Origin Certificate with SSL mode "Full (strict)".)

### A12. Smoke test

```bash
curl -i https://api.yourdomain.edu.ph/index.php/Welcome                    # 200, CI welcome page
curl -i https://api.yourdomain.edu.ph/.env                                 # 403
curl -i https://api.yourdomain.edu.ph/protected-uploads/panoramas/x.jpg    # 403
# From the deployed web app: sign in, load a tour, upload a test panorama.
```

---

## Part B — Routine redeploy (every update)

```bash
cd /var/www/arise-api
git pull --ff-only
composer install --no-dev --optimize-autoloader
```

That's it, **unless** the pull included:

- **a schema change** → apply it: `mysql -u arise -p arise_web < schema.sql`
  only rebuilds from scratch; for a live DB, run the specific
  `ALTER TABLE` by hand (or adopt migrations later).
- **a new `.env` key** (check `git log -p -- .env.example`) → add it to
  the server's `.env`.
- **a `composer.json` change** → already covered by the `composer install` above.

CI3 has no build step and no cache to clear for normal code changes.

---

## Part C — Rollback

```bash
cd /var/www/arise-api
git log --oneline -5                 # find the last good commit
git reset --hard <good-commit-sha>   # reset only — never `git clean`, it deletes uploads/
composer install --no-dev --optimize-autoloader
```

If the bad deploy included a schema change, restore the DB from the
nightly dump (see Backups).

---

## Backups (set up once)

```cron
# nightly DB dump, kept 14 days
15 3 * * * mysqldump -u arise -p'<DB_PASS>' arise_web | gzip > /var/backups/arise_web_$(date +\%F).sql.gz
20 3 * * * find /var/backups -name 'arise_web_*.sql.gz' -mtime +14 -delete
```

Also back up the uploaded images — `/var/www/arise-api/uploads/` and
`/var/www/arise-api/protected-uploads/` — and a copy of the server's
`.env`. Neither is in git or in the DB dump.

```cron
# nightly image sync, kept alongside the DB dumps
30 3 * * * tar czf /var/backups/arise_uploads_$(date +\%F).tar.gz -C /var/www/arise-api uploads protected-uploads
35 3 * * * find /var/backups -name 'arise_uploads_*.tar.gz' -mtime +14 -delete
```

---

## `.env` key reference

| Key | Example (production) | Notes |
|---|---|---|
| `CI_ENV` | `production` | `development` on laptops only — it exposes errors |
| `BASE_URL` | `https://api.yourdomain.edu.ph/` | trailing slash; must match the front-end build |
| `CORS_ORIGIN` | `https://app.yourdomain.edu.ph` | exact origin, no trailing slash |
| `DB_HOST` | `localhost` | co-locate DB with PHP — CI3 opens one connection per request |
| `DB_USER` / `DB_PASS` / `DB_NAME` | `arise` / … / `arise_web` | |
| `UPLOAD_ROOT` | `/var/www/arise-api/uploads/` | absolute, trailing slash; folder is under DocumentRoot and served directly |
| `PROTECTED_UPLOAD_ROOT` | `/var/www/arise-api/protected-uploads/` | absolute, trailing slash; under DocumentRoot but Apache is told to deny it (A7 + A8) |
| `SMTP_HOST` … `SMTP_FROM_NAME` | Brevo / Mailgun / Postmark | quote values with spaces |
| `SENTRY_DSN` | *(blank)* | optional, error tracking (item 10) |

Blank or missing keys fall back to the hard-coded development defaults in
the code.

---

## Notes / gotchas

- **`/index.php/` in URLs.** `config['index_page']` is `'index.php'`, so
  API paths look like `…/index.php/Nodes_API/getAll`. To drop it: add a
  `RewriteRule` to `.htaccess` routing everything to `index.php`, then set
  `config['index_page'] = ''` — and rebuild the front-end with the new
  `VITE_API_BASE_URL`. Not required; just cosmetic.
- **Authorization header.** The root `.htaccess` already forwards it.
  If auth mysteriously fails with 401 on Apache, confirm that file wasn't
  lost and `AllowOverride All` is set.
- **`application/` and `system/`** ship their own deny-all `.htaccess` —
  leave them.
- **Shared hosting (cPanel):** skip the Apache/vhost steps (the panel
  owns them), set the document root to the repo folder, use the panel's
  Cron and PHP-settings UIs for A9/A10, and run `composer install` over
  SSH if available — otherwise commit `vendor/` on a branch as a fallback.
- **Regenerating `schema.sql`** after a local schema change: the command
  is in the header of `schema.sql` itself.
