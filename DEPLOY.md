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
| Composer 2 | to install `vlucas/phpdotenv` |
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

`composer.json` has `post-install-cmd` and `post-update-cmd` scripts that run
`sed` on a file inside the dev-only `vfsStream` package. With `--no-dev` that
file is absent, so Composer may report a script error after installing. The
packages are installed regardless; add `--no-scripts` to silence it (nothing
in those scripts is needed in production). Untested here: confirm on the
server.

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

- `uploads/` — public images (kiosk signage). Sits under DocumentRoot, so Apache
  serves it directly at `https://…/uploads/…` (no extra config).
- `protected-uploads/` — indoor images, streamed only through
  `IndoorUploads_API::serve()`, which has no auth check (anyone who knows
  a path can view it; uploading is admin-only). It **also** sits under
  DocumentRoot, so A7 and A8 explicitly forbid Apache from serving it —
  that deny rule is the only thing stopping Apache handing the files out
  directly, around `serve()`.
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
FRONTEND_URL=https://app.yourdomain.edu.ph
EMAIL_PROTOCOL=smtp
EMAIL_FROM=noreply@yourdomain.edu.ph
EMAIL_SMTP_HOST=smtp.yourdomain.edu.ph
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USER=<smtp user>
EMAIL_SMTP_PASS=<smtp password>
```

- **`CI_ENV=production`** turns off `db_debug` and hides PHP errors. Do
  not skip this — `development` leaks SQL and file paths on any error.
- **`BASE_URL`** must match what the front-end was built against
  (`VITE_API_BASE_URL` minus the `/index.php`). URLs keep `/index.php/`
  in the path unless you add a rewrite rule (see notes).
- **`CORS_ORIGIN`** is the exact scheme + host of the deployed web app,
  no trailing slash. Native apps (Expo) don't need it.
- **`FRONTEND_URL` and `EMAIL_*`**: registration, approval and
  password-reset emails are sent straight from the request. `FRONTEND_URL`
  is the web app's address, used for the reset link (blank means the first
  `CORS_ORIGIN`). The default `EMAIL_PROTOCOL=mail` uses PHP `mail()`, which
  from a server usually lands in spam or never arrives, so point it at a real
  SMTP server (`EMAIL_PROTOCOL=smtp` and the `EMAIL_SMTP_*` values). A failed
  send is logged to `application/logs/` and does not fail the request. If
  email cannot work, admins recover with `php index.php Admins_CLI setPassword`
  (see `SEED.md`).
- **`UPLOAD_ROOT` / `PROTECTED_UPLOAD_ROOT`** — set both explicitly to
  absolute paths (trailing slash). Don't leave them blank on the server:
  the blank-value fallback for `PROTECTED_UPLOAD_ROOT` resolves to two
  directories *above* `index.php`, which won't be what you want here.
  Every upload, serve and gallery endpoint reads these through one place
  (`MY_Controller::photoStore()`). Before that was consolidated,
  `PROTECTED_UPLOAD_ROOT` was ignored and indoor photos went to that
  fallback location — so if a server ran an older version with these
  variables set, move any files found in `<two levels above index.php>/
  protected-uploads/` into `PROTECTED_UPLOAD_ROOT` once.

### A5. Create the database

```bash
sudo mysql -e "CREATE DATABASE arise_web CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
sudo mysql -e "CREATE USER 'arise'@'localhost' IDENTIFIED BY '<same password as DB_PASS>'"
sudo mysql -e "GRANT ALL PRIVILEGES ON arise_web.* TO 'arise'@'localhost'; FLUSH PRIVILEGES"
mysql -u arise -p arise_web < schema.sql
```

Then create the first admin account from the command line, following
**`SEED.md`** (a fresh database has no accounts, and there is no
approved admin, so nobody can create one through the app).

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

    # Public images — served directly (the folder is under DocumentRoot)
    <Directory /var/www/arise-api/uploads>
        Require all granted
        Options -Indexes
    </Directory>

    # Indoor images — Apache must NEVER serve these directly; the only
    # legitimate way in is IndoorUploads_API::serve().
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
this still holds. Both exist because nothing else stops Apache serving these files directly.

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
upload_max_filesize = 100M
post_max_size       = 128M
memory_limit        = 256M
max_execution_time  = 120
max_input_time      = 120
```

```bash
sudo systemctl restart apache2
```

### A10. Cron — housekeeping

`Cron_API::purgeExpired` deletes expired login tokens and rate-limit hits
older than a day (`rate_limit_hits`). CLI-only
(`is_cli_request()` guards it). Once a day is plenty:

```cron
10 3 * * * cd /var/www/arise-api && /usr/bin/php index.php Cron_API purgeExpired >> /var/log/arise-cron.log 2>&1
```

Test once by hand: `php index.php Cron_API purgeExpired` →
`Purged: 0 login tokens.` then `Purged: 0 rate limit hits.`

`Cron_API::closeStaleSessions` closes web Analytics sessions
(`analytics_sessions.platform = 'web'`, i.e. any session not from a paired
kiosk) that have gone 30 minutes with
no new tracked event and no `ended_at` yet — a web visitor has no
reliable "tab closed" signal, unlike kiosk (see useAnalytics.js), so this
is what actually ends those sessions for the Analytics dashboard's average
duration and funnel. Same CLI-only guard, same cadence is fine:

```cron
15 3 * * * cd /var/www/arise-api && /usr/bin/php index.php Cron_API closeStaleSessions >> /var/log/arise-cron.log 2>&1
```

A closed session's `ended_at` is its last tracked event, not the time the
cron ran, so running it once a day doesn't skew durations. It only means
still-open web sessions stay out of the average duration until the next
run. If that lag matters, run it every 30 minutes instead:

```cron
*/30 * * * * cd /var/www/arise-api && /usr/bin/php index.php Cron_API closeStaleSessions >> /var/log/arise-cron.log 2>&1
```

Test once by hand: `php index.php Cron_API closeStaleSessions` →
`Closed: 0 stale web analytics sessions.`

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
# From the deployed web app: sign in, load the map, upload a test panorama.
```

Signing in is the check that matters most after a schema change: login
reads and writes `rate_limit_hits`, so a missing migration shows up there
first. Also confirm a password-reset email actually arrives.

---

## Part B — Routine redeploy (every update)

```bash
cd /var/www/arise-api
git pull --ff-only
composer install --no-dev --optimize-autoloader
```

That's it, **unless** the pull included:

- **a schema change** → **never re-import `schema.sql` on a live
  database**: every table in it starts with `DROP TABLE IF EXISTS`, so
  it wipes all data (it is only for building a fresh database). Run the
  specific `ALTER TABLE` by hand instead, **before** pulling the new
  code — see [Schema changes for a live database](#schema-changes-for-a-live-database)
  below, and take a backup first.
- **a new `.env` key** (check `git log -p -- .env.example`) → add it to
  the server's `.env`.
- **a `composer.json` change** → already covered by the `composer install` above.

CI3 has no build step and no cache to clear for normal code changes.

### Schema changes for a live database

`schema.sql` always describes the *current* schema, and a fresh install
gets everything from it. A database that already exists is brought up to
date with the statements below, oldest first, by hand. Apply an entry
**before** deploying the code that needs it. Each is additive (new columns
with defaults), so the previous code keeps working against the changed
table and a rollback needs no schema change. **Exceptions are marked
"not additive" or "destructive": take a backup and deploy the code with
the migration.**

Migration files in `migrations/`, in the order to apply them to a database
that predates them (the entries below carry the details; the earliest steps,
from before `migrations/` existed, are inline SQL):

| File | Kind |
|---|---|
| `2026-10-04_admins_only_no_email.sql` | not additive, back up first |
| `2026-10-04_rate_limit_hits.sql` | additive, **required before the current code** |
| `2026-10-05_pending_and_password_reset.sql` | additive |
| `2026-10-05_rename_type_ids.sql` | not additive, deploy with web and mobile |
| `2026-10-06_node_discharges_outside.sql`, `2026-10-07_rename_discharges_outside.sql` | additive |
| `2026-10-08_placard_photos.sql`, `2026-10-09_photo_thumb_focus.sql` | additive |
| `2026-10-10_emergency_exit_markers.sql` | not additive, back up first |
| `2026-10-11_signage_category.sql` | additive |
| `2026-10-12_drop_virtual_tour.sql` | drops tables, dump first |
| `2026-10-13_analytics_mobile_platform.sql` | additive |
| `2026-10-14_directory_settings.sql` | additive |
| `2026-10-15_room_photo_kinds.sql` + `Photos_CLI purgeRoomPhotos` | **destructive**, back up first |
| `2026-10-16_ocr_placard_names.sql` | additive |

Entries for steps that a later migration undoes (`leads_to_floor`,
`leads_to_floors`) are kept only so a database at that older stage can be
walked forward; a fresh install needs none of this.

**Starting node per floor** — adds `is_starting_node` to `nodes`, the node
an admin flags as where the kiosk drops visitors who pick that building floor:

```sql
ALTER TABLE nodes
  ADD COLUMN is_starting_node tinyint(1) NOT NULL DEFAULT 0 AFTER leads_to_floor;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE nodes ADD COLUMN is_starting_node tinyint(1) NOT NULL DEFAULT 0 AFTER leads_to_floor"
```

Until it is applied, `Nodes_API update` fails (unknown column
`is_starting_node`) whenever a node is saved with that field — apply it first.

**Default view per hotspot link, and per starting node** — adds
`default_yaw`/`default_pitch` to `node_neighbors` (the camera view a visitor
lands facing when arriving via that specific link), and
`starting_view_yaw`/`starting_view_pitch` to `nodes` (the view for a floor's
starting node when reached from the floor/building picker). This step once
altered `tour_stop_neighbors` the same way; skip that, the Virtual Tour and
its tables are gone (see below):

```sql
ALTER TABLE node_neighbors
  ADD COLUMN default_yaw   float DEFAULT NULL AFTER pitch,
  ADD COLUMN default_pitch float DEFAULT NULL AFTER default_yaw;

ALTER TABLE nodes
  ADD COLUMN starting_view_yaw   float DEFAULT NULL AFTER is_starting_node,
  ADD COLUMN starting_view_pitch float DEFAULT NULL AFTER starting_view_yaw;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE node_neighbors ADD COLUMN default_yaw float DEFAULT NULL AFTER pitch, ADD COLUMN default_pitch float DEFAULT NULL AFTER default_yaw"
mysql -u arise -p arise_web -e "ALTER TABLE nodes ADD COLUMN starting_view_yaw float DEFAULT NULL AFTER is_starting_node, ADD COLUMN starting_view_pitch float DEFAULT NULL AFTER starting_view_yaw"
```

Until applied, `updateNeighborDefaultView`/`clearNeighborDefaultView` fail
(unknown column) and `Nodes_API update` fails whenever a node is saved with
`starting_view_yaw`/`starting_view_pitch` — apply it first.

**Campus entrance** — adds `is_campus_entrance` to `nodes`, the single node
an admin flags as representing a whole campus (GD1/GD2/GD3 share one;
Digital Campus has its own), driving the cross-campus minimap:

```sql
ALTER TABLE nodes
  ADD COLUMN is_campus_entrance tinyint(1) NOT NULL DEFAULT 0 AFTER starting_view_pitch;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE nodes ADD COLUMN is_campus_entrance tinyint(1) NOT NULL DEFAULT 0 AFTER starting_view_pitch"
```

Until it is applied, `Nodes_API update` fails (unknown column
`is_campus_entrance`) whenever a node is saved with that field — apply it first.

**Building entrance** — adds `is_building_entrance` to `nodes`, the single
node an admin flags as representing one specific building (independent of
campus entrance — a building entrance and its campus entrance can be the
same node or two different ones):

```sql
ALTER TABLE nodes
  ADD COLUMN is_building_entrance tinyint(1) NOT NULL DEFAULT 0 AFTER is_campus_entrance;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE nodes ADD COLUMN is_building_entrance tinyint(1) NOT NULL DEFAULT 0 AFTER is_campus_entrance"
```

Until it is applied, `Nodes_API update` fails (unknown column
`is_building_entrance`) whenever a node is saved with that field — apply it first.

**Stairs/fire-exit nodes can lead to more than one floor** — renames
`leads_to_floor` (a single int) to `leads_to_floors` (comma-joined text,
same storage convention as `elevators.accessible_floors` — see
`Elevators_Model::parseFloors`/`joinFloors`), since a mid-building
stairwell typically connects both up and down, not just one direction:

```sql
ALTER TABLE nodes
  CHANGE COLUMN leads_to_floor leads_to_floors varchar(255) DEFAULT NULL;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE nodes CHANGE COLUMN leads_to_floor leads_to_floors varchar(255) DEFAULT NULL"
```

Existing single-floor values convert automatically (MySQL casts the old int
into the new varchar as-is, e.g. `3` stays `"3"`, which `parseFloors` reads
fine). Until applied, `Nodes_API create`/`update` fail (unknown column
`leads_to_floors`) whenever a node is saved with that field — apply it
first. Not additive like the others above, since it also renames the
column — deploy the code and the migration together.

**Analytics tables** — adds `analytics_sessions` and `analytics_events`
behind `Analytics_API` (the Analytics dashboard, replacing the plain
Feedback admin page): one row per kiosk/web session, one row per
tracked action (stage reached, room searched, go-to, directions requested,
walk/jump, feedback submitted, session end). `analytics_sessions` must be
created first, since `analytics_events.session_id` references it:

```sql
CREATE TABLE analytics_sessions (
  id char(36) NOT NULL,
  platform enum('kiosk','web') NOT NULL,
  campus varchar(64) DEFAULT NULL,
  building varchar(64) DEFAULT NULL,
  started_at datetime NOT NULL DEFAULT current_timestamp(),
  ended_at datetime DEFAULT NULL,
  end_reason enum('feedback','idle_timeout','inactivity_timeout') DEFAULT NULL,
  furthest_stage enum('start','campus','building','floor','exploring','feedback') NOT NULL DEFAULT 'start',
  gave_feedback tinyint(1) NOT NULL DEFAULT 0,
  feedback_id int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (id),
  KEY platform_started (platform, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE analytics_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id char(36) NOT NULL,
  event_type enum('stage_reached','room_searched','go_to','directions_requested','move','feedback_submitted','session_end') NOT NULL,
  stage varchar(32) DEFAULT NULL,
  node_id varchar(64) DEFAULT NULL,
  from_node_id varchar(64) DEFAULT NULL,
  to_node_id varchar(64) DEFAULT NULL,
  room_query varchar(255) DEFAULT NULL,
  matched tinyint(1) DEFAULT NULL,
  move_kind enum('walk','jump') DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY session_id (session_id),
  KEY event_type_created (event_type, created_at),
  KEY from_to (from_node_id, to_node_id),
  CONSTRAINT analytics_events_ibfk_1 FOREIGN KEY (session_id) REFERENCES analytics_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```bash
mysql -u arise -p arise_web -e "CREATE TABLE analytics_sessions (id char(36) NOT NULL, platform enum('kiosk','web') NOT NULL, campus varchar(64) DEFAULT NULL, building varchar(64) DEFAULT NULL, started_at datetime NOT NULL DEFAULT current_timestamp(), ended_at datetime DEFAULT NULL, end_reason enum('feedback','idle_timeout','inactivity_timeout') DEFAULT NULL, furthest_stage enum('start','campus','building','floor','exploring','feedback') NOT NULL DEFAULT 'start', gave_feedback tinyint(1) NOT NULL DEFAULT 0, feedback_id int(10) unsigned DEFAULT NULL, PRIMARY KEY (id), KEY platform_started (platform, started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
mysql -u arise -p arise_web -e "CREATE TABLE analytics_events (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_id char(36) NOT NULL, event_type enum('stage_reached','room_searched','go_to','directions_requested','move','feedback_submitted','session_end') NOT NULL, stage varchar(32) DEFAULT NULL, node_id varchar(64) DEFAULT NULL, from_node_id varchar(64) DEFAULT NULL, to_node_id varchar(64) DEFAULT NULL, room_query varchar(255) DEFAULT NULL, matched tinyint(1) DEFAULT NULL, move_kind enum('walk','jump') DEFAULT NULL, created_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (id), KEY session_id (session_id), KEY event_type_created (event_type, created_at), KEY from_to (from_node_id, to_node_id), CONSTRAINT analytics_events_ibfk_1 FOREIGN KEY (session_id) REFERENCES analytics_sessions (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
```

Purely additive: no existing table changes. Until it is applied, every
`Analytics_API` call fails (unknown table) — `Feedback_API` and the rest of
the API are unaffected. Apply it before shipping a front-end build that
tracks analytics events.

**Signage (kiosk advertisements)** adds `signage_slides` and
`signage_settings` behind `Signage_API`: the images, GIFs and looping
videos shown in the kiosk's bottom band, each with its crop, duration,
rotation position and optional run window, plus one settings row (rotation
order, transition, default duration). Media files go to
`UPLOAD_ROOT/signage/`, served directly by Apache. Named
"signage" rather than "ads" on purpose: ad blockers hide URLs and elements
that look like advertisements. `signage_settings` needs no seed row; the
API falls back to its defaults until the first save.

```sql
CREATE TABLE signage_slides (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  media_path varchar(500) NOT NULL,
  crop_x decimal(7,6) NOT NULL DEFAULT 0.000000,
  crop_y decimal(7,6) NOT NULL DEFAULT 0.000000,
  crop_w decimal(7,6) NOT NULL DEFAULT 1.000000,
  crop_h decimal(7,6) NOT NULL DEFAULT 1.000000,
  duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0,
  sort_order int(10) unsigned NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  starts_at datetime DEFAULT NULL,
  ends_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signage_settings (
  id tinyint(3) unsigned NOT NULL,
  rotation_order enum('sequence','shuffle') NOT NULL DEFAULT 'sequence',
  transition enum('fade','cut') NOT NULL DEFAULT 'fade',
  default_duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0,
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```bash
mysql -u arise -p arise_web -e "CREATE TABLE signage_slides (id int(10) unsigned NOT NULL AUTO_INCREMENT, title varchar(255) NOT NULL, media_path varchar(500) NOT NULL, crop_x decimal(7,6) NOT NULL DEFAULT 0.000000, crop_y decimal(7,6) NOT NULL DEFAULT 0.000000, crop_w decimal(7,6) NOT NULL DEFAULT 1.000000, crop_h decimal(7,6) NOT NULL DEFAULT 1.000000, duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0, sort_order int(10) unsigned NOT NULL DEFAULT 0, is_active tinyint(1) NOT NULL DEFAULT 1, starts_at datetime DEFAULT NULL, ends_at datetime DEFAULT NULL, created_at datetime NOT NULL DEFAULT current_timestamp(), updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (id), KEY active_order (is_active, sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
mysql -u arise -p arise_web -e "CREATE TABLE signage_settings (id tinyint(3) unsigned NOT NULL, rotation_order enum('sequence','shuffle') NOT NULL DEFAULT 'sequence', transition enum('fade','cut') NOT NULL DEFAULT 'fade', default_duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0, updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
```

Purely additive: no existing table changes. Until it is applied,
`Signage_API` calls fail (unknown table), the kiosk simply shows its plain
bottom band, and the rest of the API is unaffected. Videos count against
`upload_max_filesize`/`post_max_size` like any upload (the A9 values
above allow 100 MB); a 1080 x 336 loop of 15-30 seconds is normally a few MB.

Times on screen are in tenths of a second (`decimal(4,1)`, e.g. 7.5). A
database that got an earlier draft of these tables, with whole-second
`smallint` columns, converts in place (existing values keep their number):

```bash
mysql -u arise -p arise_web -e "ALTER TABLE signage_slides MODIFY duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0; ALTER TABLE signage_settings MODIFY default_duration_seconds decimal(4,1) NOT NULL DEFAULT 10.0"
```

**Room contact number replaces Use**: adds `contact_number` to `placard_dialogs`.
The Room Editor's "Use" field is gone and `PlacardDialogs_API` no longer
accepts `use`; the `use` column is left in place (unread, unwritten) so
this stays additive and a rollback keeps its old values:

```sql
ALTER TABLE placard_dialogs
  ADD COLUMN contact_number varchar(50) DEFAULT NULL AFTER link;
```

```bash
mysql -u arise -p arise_web -e "ALTER TABLE placard_dialogs ADD COLUMN contact_number varchar(50) DEFAULT NULL AFTER link"
```

Until it is applied, saving a room in the Room Editor fails (unknown column
`contact_number`), so apply it first.

**Kiosks** — adds `kiosks` and `kiosk_pair_failures` behind `Kiosks_API`:
the physical devices an admin registers (each tied to a map node) and pairs
once with a short-lived, single-use code. Only the hashes of the code and
of the device's token are stored; `kiosk_pair_failures` is the per-IP log
that rate-limits wrong codes:

```sql
CREATE TABLE kiosks (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL,
  node_id varchar(64) DEFAULT NULL,
  pairing_code_hash char(64) DEFAULT NULL,
  pairing_expires_at datetime DEFAULT NULL,
  token_hash char(64) DEFAULT NULL,
  paired_at datetime DEFAULT NULL,
  last_seen_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_kiosks_code (pairing_code_hash),
  KEY idx_kiosks_token (token_hash),
  KEY node_id (node_id),
  CONSTRAINT kiosks_ibfk_1 FOREIGN KEY (node_id) REFERENCES nodes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kiosk_pair_failures (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  ip varchar(45) NOT NULL,
  attempted_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_pair_failures_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Purely additive. Until it is applied, every `Kiosks_API` call fails, and so
does any `Analytics_API::track` call that carries a `kiosk_token`.

**Analytics platform `desktop` becomes `web`** — the platform is now decided
by the server: `kiosk` only for a paired kiosk's session, `web` for every
other (a browser that merely shows the Compact layout included).
Not additive: the old code writes `desktop`, which the new enum rejects, so
deploy the code and this migration together, in this order (the enum is
widened first so the existing rows stay valid while they are converted):

```sql
ALTER TABLE analytics_sessions MODIFY platform enum('kiosk','desktop','web') NOT NULL;
UPDATE analytics_sessions SET platform = 'web' WHERE platform = 'desktop';
ALTER TABLE analytics_sessions MODIFY platform enum('kiosk','web') NOT NULL;
```

Existing `kiosk` rows keep that value even though they predate pairing, so
the dashboard's kiosk figures include them until you delete or reclassify
them by hand.

**Analytics platform `mobile`** — the mobile app's sessions. Purely additive;
run `migrations/2026-10-13_analytics_mobile_platform.sql` (or the one-liner
below). Until it is applied a mobile session is silently not recorded (the
insert is rejected and the app ignores the failure); the web and kiosk are
unaffected, and either order of deploying the app and the API is safe.

```sql
ALTER TABLE analytics_sessions MODIFY platform enum('kiosk','web','mobile') NOT NULL;
```

**Admins only, no email** — turns `users` into `admins` (no `role` column,
non-admin accounts deleted), renames `auth_tokens.user_id` to `admin_id`,
and drops `saved_rooms`, `password_resets` and `email_queue`. Not
additive: back up first and deploy the code together with the migration.
Run it once from the project root:

```bash
mysql -u arise -p arise_web < migrations/2026-10-04_admins_only_no_email.sql
```

After it, `Cron_API processEmails` and the `SMTP_*` settings no longer
exist: remove that cron entry. A fresh install needs none of this, only
the first admin from `SEED.md`.

**Account approval and emailed password reset** — adds `admins.status`
(`pending` or `approved`; existing admins stay `approved`) and the
`password_resets` table. Run once, after the migration above:

```bash
mysql -u arise -p arise_web < migrations/2026-10-05_pending_and_password_reset.sql
```

Then set the `EMAIL_*` and `FRONTEND_URL` values from `.env.example`. The
default, PHP `mail()`, needs no other service but its messages often land in
spam or never arrive from a shared host or XAMPP; point `EMAIL_PROTOCOL=smtp`
at a real mail server if that happens. Mail is sent straight from the
request (no queue, no cron): a failed send is logged to
`application/logs/` and the request still succeeds.

**Type ids match their labels** — renames the node types (`transition` to
`stairs`, `transitionExit` to `fire_exit`, `openArea` to `open_area`, `portal`
to `building_transition`) and the marker types (`exit` to `emergency_exit`,
`hydrant` to `fire_extinguisher`), and renames every node whose id follows
the generated `{building}_f{floor}_{type}{NN}` pattern so its type part
matches (`gd1_f2_transition01` becomes `gd1_f2_stairs01`). Custom-named
nodes keep their ids. Take a backup first. Not additive: the old web and
mobile builds do not know the new ids, so run it together with deploying the
API, web and mobile builds:

```bash
mysqldump -u arise -p arise_web > backup-before-rename.sql
mysql -u arise -p arise_web < migrations/2026-10-05_rename_type_ids.sql
```

**Emergency Exit Destination Point flag** (additive): adds `is_emergency_destination` to
`nodes`, the admin's statement that someone who reaches that node is out of danger. "Nearest
Exit" ends its route at ticked nodes only: nothing is automatic, so every Open Area, Parking,
Lobby, Entrance and Fire Exit node starts unticked and a building with none ticked gets no
route. The web app limits the tick to those five types and to Floor 1 or Underground. Apply
both migrations, in order, before deploying the web code that saves the field (a database
that already has the older `discharges_outside` column only needs the second):

```bash
mysql -u arise -p arise_web < migrations/2026-10-06_node_discharges_outside.sql
mysql -u arise -p arise_web < migrations/2026-10-07_rename_discharges_outside.sql
```

Then tick each building's real exits in the node editor.

**Virtual Tour markers removed** — the Virtual Tour no longer has markers or
marker photos. Drop the two tables (check they are empty first; any photos
under `uploads/tourmarker/` are no longer referenced):

```sql
DROP TABLE tour_stop_marker_photos;
DROP TABLE tour_stop_markers;
```

**Virtual Tour removed entirely** — the public `/tour` page, its two admin
editors and the `TourStops_API`/`TourSections_API`/`TourUploads_API`
endpoints are all gone. Drop the remaining three tables (take a dump first
if the stops still hold anything you want):

```bash
mysqldump -u arise -p arise_web tour_sections tour_stops tour_stop_neighbors > tour-tables-backup.sql
mysql -u arise -p arise_web < migrations/2026-10-12_drop_virtual_tour.sql
```

Photos under `uploads/tourpanorama/` and `uploads/tourcover/` are simply
unreferenced afterwards — delete those folders by hand once you are sure.

**Mobile analytics sessions are covered above** (`migrations/2026-10-13_analytics_mobile_platform.sql`).

**Rate limiting** (`migrations/2026-10-04_rate_limit_hits.sql`, additive) adds `rate_limit_hits`, the
hit log behind the limits on login, registration, password reset, feedback submit and analytics track
(`application/libraries/Rate_limit.php`). **Apply it before deploying any code from 2026-10-04 on:** the
limits run on every one of those requests and the API does not skip them when the table is missing, so
without it admin login, feedback and tracking all fail. `Cron_API purgeExpired` trims old hits.

```bash
mysql -u arise -p arise_web < migrations/2026-10-04_rate_limit_hits.sql
```

**Extra Room photos and thumbnail focus** (additive, apply in this order; the second needs the first's
table). Adds `placard_photos` and then `thumb_x`/`thumb_y` to it and to `placard_dialogs`. The next
entry replaces most of this, but a database that predates it still needs both to reach it:

```bash
mysql -u arise -p arise_web < migrations/2026-10-08_placard_photos.sql
mysql -u arise -p arise_web < migrations/2026-10-09_photo_thumb_focus.sql
```

**Signage category** (additive) adds `signage_slides.category` (`footer` or `starting`): which kiosk
surface a slide plays on. Existing slides become `footer`. Until it is applied, saving or listing
slides fails (unknown column `category`), so apply it before deploying:

```bash
mysql -u arise -p arise_web < migrations/2026-10-11_signage_category.sql
```

**Directory settings** (additive) adds `directory_settings`, one JSON row holding which parts of the web
sidebar's Directory visitors see. No seed row: a missing row means everything is shown. Until it is
applied the Directory admin page and the settings endpoint fail:

```bash
mysql -u arise -p arise_web < migrations/2026-10-14_directory_settings.sql
```

**One ordered photo list per Room, flat or 360** (**destructive**, 2026-10-15). Adds `placard_photos.kind`,
**deletes every existing flat Room photo reference**, turns each 360 photo into a `360` row, and drops
`placard_dialogs.photo_path`, `photo_360_path`, `thumb_x` and `thumb_y`. The API still returns
`photo_path` and `photo_360_path` (first flat, first 360), so the mobile app is unaffected. Take a backup,
deploy the API and web together (the old web build reads the dropped columns), then run the purge **once,
immediately, before any new upload** (later it would also delete a photo uploaded but not yet saved):

```bash
mysqldump -u arise -p arise_web > backup-before-room-photo-kinds.sql
mysql -u arise -p arise_web < migrations/2026-10-15_room_photo_kinds.sql
cd /var/www/arise-api && php index.php Photos_CLI purgeRoomPhotos
```

**OCR Management** (additive, 2026-10-16) adds `placard_dialogs.ocr_enabled` and `placard_name`, and
`placard_search_terms.is_extra`. The scanner in the mobile app matches only rooms and facilities with
`ocr_enabled` set; their search terms are generated from the Placard name (`is_extra` = 0) or typed in by
an admin (`is_extra` = 1). Every room or facility that already had a search term starts out eligible,
with its room name as its Placard name, so scanning keeps working. Apply it before deploying the API
and web builds that use it: `PlacardDialogs_API saveOcr` and the OCR Management page fail without it.
The mobile build that reads `ocr_enabled` treats a missing flag as eligible, so against an older API
that does not send it, it falls back to matching every room, as before:

```bash
mysql -u arise -p arise_web < migrations/2026-10-16_ocr_placard_names.sql
```

**Emergency Exit markers replace the Fire Exit node type and `leads_to_floors`** (2026-10-10, not
additive): a fire exit node is now a node carrying an `emergency_exit` marker, and the marker lists
where its hidden fire stairs come out in the new `node_marker_landings` table (one directed row per
landing; `Nodes_API addMarker/updateMarker` take `landings`, an array of node ids, and `getAll` returns
each marker's `landings`, lowest floor first). The migration creates that table, deletes the old
`emergency_exit` markers (the "Assembly Point" signs the mobile app used to look for: Nearest Exit now
uses ticked Emergency Exit Destination Points), turns every `fire_exit` node into the type its id names
(hallway if it names none), adds a marker to each with its cross-floor neighbor links as landings (those
links are removed so ordinary directions cannot take the fire stairs), and drops `nodes.leads_to_floors`.
It prints what it is about to convert first. Take a backup, deploy the API with it, and deploy the web
and mobile builds together: the old builds read `leads_to_floors` and the `fire_exit` type.

```bash
mysqldump -u arise -p arise_web > backup-before-emergency-exit-markers.sql
mysql -u arise -p arise_web < migrations/2026-10-10_emergency_exit_markers.sql
```

Converted fire doors get a marker at yaw 0 and pitch 0 and no landings: place them in the Virtual Map
Navigation Editor. Emergency Coverage lists every marker that leads nowhere.

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

`protected-uploads/_previews/` is a cache of downscaled copies for the mobile
app and is rebuilt on demand, so it can be skipped. Also back up the uploaded images — `/var/www/arise-api/uploads/` and
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
| `FRONTEND_URL` | `https://app.yourdomain.edu.ph` | no trailing slash; used for the password-reset link; blank = first `CORS_ORIGIN` |
| `EMAIL_PROTOCOL` | `smtp` | `mail` (default) is PHP `mail()`, unreliable from a server |
| `EMAIL_FROM` / `EMAIL_FROM_NAME` | `noreply@yourdomain.edu.ph` / `ARISE Campus Navigator` | sender of account emails |
| `EMAIL_SMTP_HOST` / `_PORT` / `_USER` / `_PASS` / `_CRYPTO` | … / `587` / … / … / `tls` | only read when `EMAIL_PROTOCOL=smtp` |

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
- **Regenerating `schema.sql`** after a local schema change: it is a plain
  `mysqldump` of the development database (the file has no header command),
  so re-dump it and commit a matching file in `migrations/` alongside it.
- **Leftover Virtual Tour photos.** `uploads/tourpanorama/` and
  `uploads/tourcover/` are unreferenced since the Virtual Tour was removed;
  delete them once you are sure.

