# First-run database setup

`schema.sql` only creates empty tables. A fresh database has **no admin
accounts**, and nothing in the app can create the first one: a registration
is only `pending` until an admin approves it, and every admin-management
endpoint needs a signed-in admin. So the very first admin is created from the command line, on the
server, with a CodeIgniter CLI controller (`Admins_CLI`).

Only admins exist. There are no other kinds of account.

(On this laptop's local DB there are already admins. These steps are for
a brand-new database: a new server, or a teammate's fresh clone.)

## 1. Create the database and load the schema

```bash
mysql -u root -e "CREATE DATABASE arise_web CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
mysql -u root arise_web < schema.sql
```

On XAMPP the client is `C:\xampp\mysql\bin\mysql`. Use the same
DB name / credentials you put in `.env` (`DB_NAME`, `DB_USER`, `DB_PASS`).

## 2. Create the first admin

From the API's directory (where `index.php` lives):

```bash
php index.php Admins_CLI create
```

It prompts for the email (must end in `@sdca.edu.ph`), the full name and
a password of at least 8 characters, then creates the admin. For a
scripted deploy, supply the values as environment variables instead and
it will not prompt:

```bash
ADMIN_EMAIL=you@sdca.edu.ph ADMIN_NAME="Your Name" ADMIN_PASSWORD='...' php index.php Admins_CLI create
```

They are not command-line arguments because CodeIgniter rejects `@` in
the URI that CLI arguments become. Like `Cron_API`, the controller
refuses to run over HTTP: shell access to the server is the credential.

## 3. Verify

Log in through the front-end (or `POST /Auth_API/login`) with that email
and password. You should get a bearer token back and be able to reach
admin-only endpoints. From then on, admins add each other from the User
Panel.

## Lost password

The sign-in page's "Forgot password?" emails a reset link. If email is not
reaching the admin (PHP `mail()` often lands in spam, see `.env.example`), or
no admin can sign in, set a password from the command line:

```bash
php index.php Admins_CLI setPassword
```

(prompts for the email and the new password; `ADMIN_EMAIL` and
`ADMIN_PASSWORD` work here too). It also ends that admin's active
sessions.

## Upgrading an existing database

A database created before the users and email concepts were removed
(it has `users`, `saved_rooms`, `password_resets`, `email_queue`) is
brought up to date with `migrations/2026-10-04_admins_only_no_email.sql`.
Back up first. It deletes every non-admin account and the dropped tables.
