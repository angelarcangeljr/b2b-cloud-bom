# Solution Summary Builder: PHP + MySQL

The same 7-step builder (customer, pain points, scope of services, provider, high-level diagram, Solution Summary and Bill of Materials), served by PHP. It adds user sign-in, and it saves every project to a MySQL or MariaDB database.

## What is saved in the database

| Table | Holds |
|---|---|
| `users` | Architects and administrators: username, name, email, password hash (bcrypt), role, active flag, last sign-in |
| `projects` | One row per project: every builder input as JSON (`state`), plus customer, project title, provider, version, monthly USD, one-time USD and person-days for listing and reporting |
| `bom_templates` | The uploaded BOM workbooks (.xlsx): each architect's own template, and one company default |

The builder saves about a second after each change. The header shows "Saving..." and then "All changes saved". If the same project was saved from another window in the meantime, the app stops saving and asks you to reload, so neither copy overwrites the other.

## Requirements

- PHP 8.1 or later, with the `pdo_mysql` and `mbstring` extensions
- MySQL 5.7+ or MariaDB 10.3+
- A browser with internet access. The PDF, Word and Excel libraries and the fonts load from cdnjs, jsDelivr and Google Fonts.

## Run on a Mac with a .env file

1. Install PHP and MySQL with [Homebrew](https://brew.sh), and start MySQL:
   ```sh
   brew install php mysql
   brew services start mysql
   ```
2. Create the database. A fresh Homebrew MySQL has a `root` user with no password:
   ```sh
   mysql -u root -e "CREATE DATABASE solution_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
3. Get the code and create your `.env`:
   ```sh
   git clone https://github.com/angelarcangeljr/b2b-cloud-bom.git
   cd b2b-cloud-bom
   cp .env.example .env
   ```
   Open `.env` in any editor and set `DB_USER` and `DB_PASS`. Git ignores `.env`, so your password stays on your Mac.
4. Start the app:
   ```sh
   ./start-mac.sh
   ```
   It opens http://localhost:8000. The first time, it sends you to the setup page, which creates the tables and your administrator account. Press Ctrl+C in the Terminal window to stop the app; your projects stay in MySQL.

`start-mac.sh` runs PHP's built-in server through `router.php`, which blocks `.env`, `inc/` and the other private files, because the built-in server ignores `.htaccess`. To use another port, change `APP_PORT` in `.env`. Real environment variables take priority over `.env`, and the app falls back to `config.php` when neither sets `DB_NAME` or `DB_USER`.

## Install on a server

1. Copy this repository (or the folder you cloned it into) into your web root as `solution-builder`. Examples: `C:\xampp\htdocs\solution-builder` on XAMPP, or `/var/www/html/solution-builder` on Linux.
2. Create the database and an app user. You can use phpMyAdmin (SQL tab) or run:
   ```sql
   CREATE DATABASE solution_builder CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'ssb_app'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON solution_builder.* TO 'ssb_app'@'localhost';
   ```
3. Copy `.env.example` to `.env` (or `config.sample.php` to `config.php`) and enter the host, database name, user and password.
4. Open `http://your-server/solution-builder/setup.php`. It creates the tables from `schema.sql` and asks for the first administrator account. Once an account exists, the page redirects to sign-in.
5. Sign in, go to **Users** and add your architects with temporary passwords. Each person can change their own password from their name in the top bar.
6. Open any project, go to step 7 and upload your BOM template with **Upload as company default for everyone** ticked. Every architect then uses that template unless they upload their own.

On XAMPP, the database user is often `root` with an empty password, and the host is `127.0.0.1`. For a shared server, create a dedicated user as shown in step 2 instead.

## Roles

- **Architect:** creates, opens, duplicates and deletes their own projects, and can upload a personal BOM template.
- **Administrator:** everything an architect can do, plus seeing every architect's projects ("Show every architect's projects"), managing users (add, reset password, change role, deactivate) and setting the company BOM template.

## Security notes

- Passwords are stored with PHP's `password_hash` (bcrypt). Five failed sign-ins lock an account for 15 minutes, and an administrator's password reset clears the lock.
- Every form and API call carries a CSRF token. Every database query uses prepared statements.
- Sessions sign out after `session_minutes` of inactivity (8 hours by default).
- The included `.htaccess` files block web access to `config.php`, `schema.sql`, `README.md` and the `inc/` folder on Apache. On Nginx, add an equivalent rule:
  `location ~ ^/solution-builder/(inc/|config\.php|config\.sample\.php|schema\.sql|README\.md) { deny all; }`
- Serve the app over HTTPS. The session cookie is then marked Secure automatically.

## Backups

Everything lives in the database, so a normal `mysqldump solution_builder > backup.sql` captures all users, projects and templates.

## Files

```
b2b-cloud-bom/
  setup.php        first-run: creates tables and the first administrator
  login.php        sign in      logout.php   sign out
  index.php        projects list: new, open, duplicate, delete, search
  app.php          the 7-step builder for one project
  api.php          JSON endpoints: save project, upload/remove BOM template
  users.php        user administration (administrators)
  account.php      your profile and password
  schema.sql       database tables
  .env.example     settings template: copy to .env (git-ignored)
  config.sample.php  alternative settings file: copy to config.php
  start-mac.sh     runs the app on a Mac with PHP's built-in server
  router.php       router for the built-in server (blocks private files)
  inc/             bootstrap (database, sessions, CSRF, helpers) and page layout
  assets/          builder.js and builder.css (the builder), site.css (the other pages)
  standalone/      solution-builder.html: the single-file browser version (no server, saves in the browser)
  tools/           build_php.py: regenerates assets/builder.js and builder.css from the standalone file
```

`assets/builder.js` is the same builder code as the web version. The only difference is in server mode: it saves to `api.php` instead of browser storage, keeps the BOM template in the database, and downloads exports as normal browser downloads. The "Rewrite with Claude" button on the Solution Summary only works inside Claude, so it does not appear here.
