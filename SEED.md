# First-run database setup

`schema.sql` only creates empty tables. A fresh database has **no user
accounts**, and the API never lets anyone give themselves the `admin`
role — registration always creates `role = 'pending'`. So the very
first admin has to be inserted by hand, directly in MySQL.

(On this laptop's local DB there is already an admin:
`cristopher.pondoc@sdca.edu.ph`. These steps are for a brand-new
database — a new server, or a teammate's fresh clone.)

## 1. Create the database and load the schema

```bash
mysql -u root -e "CREATE DATABASE arise_web CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
mysql -u root arise_web < schema.sql
```

On XAMPP the client is `C:\xampp\mysql\bin\mysql`. Use the same
DB name / credentials you put in `.env` (`DB_NAME`, `DB_USER`, `DB_PASS`).

## 2. Create the first admin account

`users.password_hash` holds a PHP `password_hash()` value (bcrypt,
`PASSWORD_DEFAULT`) — the same thing `password_verify()` checks at
login. Generate one:

```bash
php -r "echo password_hash('YourStrongPasswordHere', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste the output (starts with `$2y$`) into this INSERT and run it
against `arise_web`:

```sql
-- Use a real @sdca.edu.ph address (the API's own registration flow
-- only accepts that domain; a manual INSERT isn't checked, but stay
-- consistent). created_at / updated_at have DB defaults and can be omitted.
INSERT INTO users (email, password_hash, name, role)
VALUES ('you@sdca.edu.ph', '<paste-hash-here>', 'Your Name', 'admin');
```

## 3. Verify

Log in through the front-end (or `POST /Auth_API/login`) with that
email + password. You should get a bearer token back and be able to
reach admin-only endpoints. From then on, new users register as
`pending` and an admin approves/promotes them from inside the app.

## Promoting someone later, without the app

```sql
UPDATE users SET role = 'admin' WHERE email = 'someone@sdca.edu.ph';
-- roles: 'pending' (just registered), 'user' (approved), 'admin'
```
