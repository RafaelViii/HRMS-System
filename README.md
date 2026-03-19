HRMS (PHP, PostgreSQL, Tailwind) — Heroku/Apache or Localhost

## System Overview

A modular Human Resource Management System built with plain PHP (PDO), PostgreSQL, and TailwindCSS. Runs on Heroku (Apache/PHP) or locally. Provides role-based authentication, core HR CRUD modules, reporting (including PDFs), backups, notifications, and audit logs, with a responsive UI and clean URLs.

Highlights
- Plain PHP (PDO), PostgreSQL, TailwindCSS via CDN
- Modular under `modules/` (Departments, Positions, Employees, Attendance, etc.)
- Hardened sessions, CSRF on POST, prepared statements
- PDF exports via bundled FPDF
- Clean URLs via `.htaccess` (extensionless); links/redirects use `BASE_URL`
- Minimal SPA navigation and loaders for smoother UX

## System Functions

- Authentication & Roles: Login/logout; roles include admin, hr, manager, employee, accountant
- Dashboard & Analytics: Chart.js metrics
- Employees: CRUD, view profiles, documents upload, PDF list/profile
- Departments: CRUD, search/list, PDF export
- Positions: CRUD, search/list, PDF export
- Attendance: Manual entry with computed overtime, CSV import (upsert), PDF export
- Leave: List by status (stub for full CRUD/PDF)
- Payroll: Views (e.g., released today) (stub for calculations/PDF)
- Performance: Placeholder for reviews/KPIs (stub)
- Recruitment: Manage applicants, files, and transitions to employees
- Documents & Memos: Placeholder, exports planned (stub)
- Audit Logs: Recent actions, PDF export
- Admin: PDF templates editor; backup/export ZIP (CSV/SQL)

## Quick Setup (Local)
The full local setup guide is in `docs/local-setup.md`.

Short version:

1) Create a PostgreSQL database (12+) and import `database/schema_postgre.sql`.
2) Apply migrations with `php tools/migrate.php`.
3) Set `DATABASE_URL` and `SUPERADMIN_DEFAULT_PASSWORD` in your shell.
4) Run `php tools/reset_admin.php` so the admin account uses a known password.
5) Start the app with `php -S localhost:8000 router.php`.

Use `docs/local-setup.md` for the complete Windows-first guide, troubleshooting, Apache/XAMPP caveats, demo data, and CSS rebuild steps.

## Running Locally While Using the Production Database
There are times when you only need to tweak and preview UI changes locally but keep the live database, so every change reflects real data without duplicating the schema. Follow these steps carefully to avoid unintended schema changes:

1. **Clone the repository locally**
   - Work from a feature branch (`git checkout -b ui-tweak-local`) so production deploys stay clean.

2. **Point your local app to the remote database**
   - Set the connection string in your shell before starting PHP. For PowerShell:
     ```powershell
     setx DATABASE_URL "postgres://<user>:<password>@<prod-host>:5432/<dbname>"
     ```
     Open a new terminal afterwards so the variable is available. If the database is only reachable inside the prod network, create an SSH tunnel instead:
     ```powershell
     ssh -L 5432:<prod-host>:5432 <bastion-user>@<bastion-host>
     ```
     then point `DATABASE_URL` at `postgres://<user>:<password>@127.0.0.1:5432/<dbname>`.

3. **Start the local PHP server**
   - From the project root run `php -S localhost:8000 router.php` (or use Apache/Nginx pointing to the same directory). The router replicates the `.htaccess` rewrites so extensionless routes work without loops while requests hit the remote PostgreSQL through the DSN above.

4. **Avoid schema or data migrations**
   - Do **not** run `/tools/migrate.php`, seed scripts, or destructive tools while connected to the live DB. Stick to front-end and layout work so production data stays safe.

5. **Commit/UI workflow**
   - Verify UI updates locally, commit on your branch, then merge/deploy once validated. When you need to go back to a local database, unset or change `DATABASE_URL` and restart the server.

These steps let you iterate on the interface locally without replicating the database, provided you keep schema operations off the live environment.

## Heroku Deployment
- Add required PHP extensions (pdo, pdo_pgsql) via composer.json (already included).
- Set `DATABASE_URL` in Heroku config; app parses it automatically.
- App enforces HTTPS behind proxy and supports extensionless routes.
 - After deploy, visit `/tools/migrate.php` once to apply pending migrations.

## PDF Library
- FPDF is bundled under `assets/fpdf186` and used for exports.

## Notes
- File uploads are stored under `assets/uploads`. Ensure the folder is writable.
- Tailwind via CDN for simplicity; switch to local build if preferred.
- Use absolute URLs with `BASE_URL` under clean URL rewrites.
- For POST pages, handle POST + redirects before `include/header.php` to avoid header warnings.

## Database Migrations

- Base schema: `database/schema_postgre.sql` (authoritative starting point)
- Versioned migrations: `database/migrations/*.sql`
- Runner: `/tools/migrate.php` records applied files in `schema_migrations` and is safe to re-run.
- Current migrations include:
   - `2025-09-04_add_notification_reads.sql` – adds `notification_reads` for per-user read tracking of global notifications.
