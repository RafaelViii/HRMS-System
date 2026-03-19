# HRMS System – Technical Handoff (as of 2025-09-04)

This document summarizes the system, environment, architecture, major fixes, and all material changes made so the next agent can continue seamlessly.

## Overview

- Stack: Plain PHP 8.x (PDO), PostgreSQL, Apache (Heroku or local), Tailwind via CDN, vanilla JS, Chart.js.
- App root: repository root is deployable; on Heroku, `DATABASE_URL` is parsed automatically.
- Goal: Modular HRMS with role-based auth, CRUD (Departments, Positions, Employees, Attendance, …), PDFs, backups, notifications, audit logs, clean URLs, and a professional UI.

## Key Architecture & Conventions

- Routing/URLs
  - `.htaccess` provides extensionless URLs and safe rewrites. Keep rules simple to avoid Windows path leaks.
  - `BASE_URL` is computed in `includes/config.php` and must prefix all internal links and redirects.
  - Modules live under `modules/<module>/` with their own `index.php`, `create.php`, `edit.php`, etc.

- Includes
  - `includes/header.php` contains the layout (sidebar, header, main), CSS/JS imports, and SPA nav wiring.
  - `includes/footer.php` closes layout.
  - `includes/auth.php` provides `require_login()`, `require_role()`, per-module access helpers, and the Quick Authorization Override.
  - `includes/db.php` exposes `get_db_conn()` using PDO (PostgreSQL-first; MySQL fallback supported but unused).
  - `includes/utils.php` has helpers (CSRF, pagination, uploads, audit wrappers, etc.).
  - `includes/pdf.php` contains PDF helpers (TCPDF/FPDF fallback approach).

- UI/UX
  - Tailwind (CDN) + `assets/css/app.css` custom tweaks.
  - `assets/js/app.js` implements a minimal SPA for progressive navigation, loaders, form helpers, validators, and sidebar behavior.
  - Sidebar is sticky; content column scrolls independently.

- Security
  - Sessions hardened (cookie flags, fingerprint, rotation, idle/absolute timeouts) handled in `includes/session.php` and used by `includes/auth.php`.
  - CSRF tokens on POST forms.
  - All SQL via prepared statements.

- PDFs
  - FPDF bundled at `assets/fpdf186`; loaded via `includes/pdf.php`.
  - Admin templates at `modules/admin/pdf/index.php` with settings in `pdf_templates`.
  - Module PDF links open in a new tab.

- Backups & reversible actions
  - Backup tables mirror core tables (e.g., `employees_backup`, `users_backup`, etc.).
  - Destructive flows (employee delete, account unbind) write to backup tables first, then delete.
  - Action Log supports reversals of key destructive actions using backup data.

## Recent Issues and Fixes (Important)

1) Clean URLs and proxy/HTTPS handling
   - Cause: Incorrect `BASE_URL` detection and over-aggressive rewrite rules produced URLs like `localhost/C:/xampp/htdocs/try/try/login`.
   - Fixes:
    - Hardened `BASE_URL` in `includes/config.php`; proxy-aware HTTPS redirects on Heroku.
    - `.htaccess` uses extensionless routes; links updated across modules.
    - SPA nav resolves relative links via `new URL(href, window.location.href)`.

2) “Headers already sent” on form submits
   - Cause: Including `includes/header.php` (which outputs HTML) before `header('Location: ...')` redirects.
   - Fixes: Moved header inclusion below POST handlers on all affected pages. Pattern: handle POST → possibly redirect → then include header and render.

3) Cursor disappears over text inputs
   - Symptom: On hover/focus of text fields, mouse cursor looked invisible on some Windows themes.
   - Fix: In `assets/css/app.css`, explicitly set a visible cursor and caret on inputs/textarea. Current choice uses default arrow for visibility, plus `caret-color` for a clear text caret.

4) Sticky sidebar and layout scroll behavior
   - CSS updates ensure sidebar stays fixed and only the content pane scrolls.

## Files Changed (PostgreSQL/PDO migration and UI updates)

Navigation/redirect and header-order fixes:
- `modules/departments/index.php`
  - Moved `header.php` include below POST delete handling.
  - Delete redirect uses `BASE_URL` absolute path.
- `modules/departments/create.php`
  - POST-first, then include header; redirect on success uses `BASE_URL`.
  - Cancel link uses `BASE_URL`.
- `modules/departments/edit.php`
  - POST-first, then include header; Not-found page shown with layout.
  - Success redirect and Cancel link use `BASE_URL`.

- `modules/positions/index.php`
  - Moved `header.php` include below POST delete handling.
  - Delete redirect uses `BASE_URL`.
- `modules/positions/create.php`
  - POST-first, then include header; success redirect uses `BASE_URL`.
  - Cancel link uses `BASE_URL`.
- `modules/positions/edit.php`
  - POST-first, then include header; Not-found shown with layout.
  - Success redirect and Cancel link use `BASE_URL`.

- `modules/employees/index.php`
  - POST-first, then include header; delete redirect uses `BASE_URL`.
  - Action links (Add/View/Edit/PDF) use `BASE_URL`.
- `modules/employees/create.php`
  - POST-first, then include header; redirect to `view.php?id=...` uses `BASE_URL`.
  - Cancel link uses `BASE_URL`.
- `modules/employees/edit.php`
  - POST-first, then include header; redirect to `view.php?id=...` uses `BASE_URL`.
  - Cancel link uses `BASE_URL`.
- `modules/employees/view.php`
  - Edit/PDF links and upload form action use `BASE_URL`.
- `modules/employees/upload.php`
  - Redirect uses `BASE_URL`.

- `modules/attendance/create.php`
  - Success redirect uses `BASE_URL` with query message; Cancel link updated.
- `modules/attendance/import.php`
  - Success redirect uses `BASE_URL` with query message; Cancel link updated.
- `modules/attendance/index.php`
  - Add/Import/PDF links use `BASE_URL`.

- `modules/admin/backup.php`
  - Streams ZIP without prior output (moved header include below POST block). Rendering uses layout as usual when not downloading.

UI/JS/CSS improvements:
- `assets/js/app.js`
  - SPA navigation resolves relative links against `window.location.href`.
  - Active nav detection updated to use normalized paths.
  - Form helpers: dirty-watch, simple validators (email domain, PH phone), confirmation prompts.
- `assets/css/app.css`
  - Sidebar sticky and layout scrolling rules (`#layoutRoot`, `.sidebar`).
  - Input focus animation and error styles.
  - Phone input UI classes.
  - Cursor visibility fix on inputs/textarea (`cursor: default !important; caret-color: #111827`).

Config/routing:
- `.htaccess` updated for extensionless routes and Heroku proxy HTTPS handling.
- `includes/config.php` `BASE_URL` computation hardened.

Core DB/Compatibility:
- Removed MySQLi usage; standardized on PDO with Postgres-safe SQL (ILIKE, RETURNING, ON CONFLICT, date casts).
- Central connection in `includes/db.php` parses `DATABASE_URL`.

Account module:
- `modules/account/index.php`, `create.php`, `edit.php`: fully PDO; templates and per-module permission levels with upsert; fixed parse error and 500s; extensionless links.

Admin system logs and PDF templates:
- `modules/admin/system_log*.php`: PDO, ILIKE, proper date range filters, pagination via LIMIT/OFFSET binds.
- `modules/admin/pdf/index.php`: PDO; ON CONFLICT(report_key) upsert.

Dashboard fixes:
- `index.php`: fixed MySQLi remnants; correct quoting; headcount and payroll charts now query via PDO and safe date ranges.

Notifications UI:
- Added a bell icon with unread badge next to the account menu in `includes/header.php`.
- Dropdown shows recent notifications (global and per-user) with link to `modules/notifications/index.php`.
- JS toggles in `assets/js/app.js`.

Diagnostics:
- `tools/env_check.php` (optional) prints PHP, server vars, and computed `BASE_URL` to debug environment issues.

## Database

- Schema file: `database/schema_postgre.sql` (authoritative for deployment). Legacy MySQL `database/schema.sql` retained for reference only.
- Core tables: `users`, `departments`, `positions`, `employees`, `documents`, `document_assignments`, `attendance`, `leave_requests`, `payroll_periods`, `payroll`, `performance_reviews`, `recruitment`, `audit_logs`, `notifications`, `pdf_templates`, `system_logs`, `action_reversals`.
- Backup tables: `employees_backup`, `payroll_backup`, `leave_requests_backup`, `departments_backup`, `positions_backup`, `users_backup`.
- Access control (created if missing by demo script): `user_access_permissions`, `access_templates`, `access_template_permissions`, `user_access_modules`.
- Demo data scripts: `database/dummydata.sql` (seed) and `database/removedummydata.sql` (cleanup).

Migrations
- Files live in `database/migrations/*.sql` and are applied by `/tools/migrate.php`.
- Runner keeps a `schema_migrations` registry with filename + sha256; safe to re-run.
- 2025-09-04: Added `2025-09-04_add_notification_reads.sql` to create `notification_reads` (composite PK (notification_id, user_id), read_at timestamp, and index on user_id). This formalizes the per-user notification read tracking that the runtime helper already supports.

## How to Run (Local or Heroku)

Local
1) Follow `docs/local-setup.md` for the full local run guide.
2) Import `database/schema_postgre.sql` and apply `php tools/migrate.php`.
3) Set `DATABASE_URL` and `SUPERADMIN_DEFAULT_PASSWORD`, then run `php tools/reset_admin.php`.
4) Start the recommended local server with `php -S localhost:8000 router.php` and sign in using the configured superadmin email and password.

Heroku
1) Provision a Postgres add-on; `DATABASE_URL` is injected.
2) Ensure PHP extensions pdo and pdo_pgsql are enabled via composer.json (in repo).
3) Deploy; system enforces HTTPS behind proxy and uses extensionless routes.

Seeding and cleanup (optional)
- Seed: run `database/dummydata.sql` to create demo departments/positions/users/employees and generate 30 days of attendance for 20 demo employees.
- Clean: run `database/removedummydata.sql` to remove all demo rows (FK-safe order).

## Known Behavior & Testing Notes

- POST pages must follow this pattern to avoid header warnings:
  1) `require` DB/utils
  2) Handle `$_POST` (validate/insert/update/delete)
  3) On success: `header('Location: ' . BASE_URL . '...'); exit;`
  4) Only then include `includes/header.php` and render HTML

- All internal links and redirects should use `BASE_URL` to avoid mixed relative paths under rewrites.

- Backup download responds with a ZIP file; ensure no whitespace or HTML is sent before headers.

## Open TODOs / Next Steps

- Modules marked “stub” (Documents, Payroll, Performance, Recruitment) need full CRUD/reporting implementations.
- Expand PDFs: finalize TCPDF/FPDF integration for all reports; ensure `pdf.php` endpoints follow template settings.
- Add unit/integration tests (none present). Consider simple smoke routes and auth tests.
- Refine validation and error messaging; unify error banners across modules.
- Consider adding `.env` style config and secrets handling.
- Improve logging (file- or DB-based) for production diagnostics.
- Optimize pagination sizes and add server-side filters for large datasets.
- Optional: Service workers or caching for assets; dark theme support.

## 2025-09-03..2025-09-04 – Major Migration to PostgreSQL/PDO + Security/UX Enhancements

Summary
- Role-based access per module: `none`, `read`, `write`, `admin`, stored in `user_access_permissions`.
- Access templates: `access_templates` + `access_template_permissions`; can apply a template to users to prefill module levels.
- Quick Authorization Override: single-action admin authorization without elevating session. Validates admin credentials, issues single-use token, audits attempts/grants/usage.
- UI wiring: global Authorization modal and custom Confirm modal in layout; animated, auto-dismissing notifications for success/error.
- Header notifications bell with unread count and dropdown.
- Menu visibility: modules with `none` access are hidden client- and server-side.
- PDFs: FPDF vendor bundled; report links open in new tabs.
- Data integrity: one account per employee enforced; deleting an employee backs up and deletes any bound account; unbind removes the user after backup; backups live in `users_backup` and `employees_backup`.
- Action Log: renamed from Audit Log; reversible actions with prevention of double reversal via `action_reversals`; reversed entries are marked; domain restore for employee deletes and account unbinds uses backups.
- Demo data: `dummydata.sql` seeds sample notifications and attendance history; `removedummydata.sql` cleans demo rows.

Key Touchpoints
- Authorization & Access: `includes/auth.php` (access checks, override issue/consume), `includes/session.php` (session hardening).
- Notifications & Modals: `includes/header.php`/`includes/footer.php`, `assets/js/app.js` (confirm/authorization flow, auto-dismiss banners, SPA helpers).
- Backups & Utilities: `includes/utils.php` (flash helpers, backup_then_delete).
- Action Log (reversible): `modules/audit/index.php`, `modules/audit/pdf.php`.
- PDFs: `includes/pdf.php`, vendor at `assets/fpdf186`.

Operational Notes
- Leave module access defaults to `write` for all logged-in users; others follow `user_access_permissions` or templates.
- Override tokens are single-use and tied to the action flow (e.g., delete/edit) and fully audited.
- All destructive actions should call backup helpers before delete to maintain reversibility.

## 2025-09-03 – Recruitment & Leave Enhancements (Follow-up)

Recruitment
- Index/list page with navigation to Create, Templates (admin), and per-applicant View.
- Applicant Create supports template selection; required basic fields enforced per template.
- Applicant View shows missing required file labels per template and supports uploading multiple labeled files.
- Transition to Employee (admin-only):
  - Validations: first/last name, unique employee_code, unique email; optional check for required files satisfied.
  - Transaction creates an `employees` row and marks the applicant as `status=hired` with `converted_employee_id` set.
  - Confirmation prompt and comprehensive error handling; audit and system logs on failures.

Leave
- File Leave (all users): validates type/date ranges and blocks overlaps with pending/approved requests.
- Leave Index: non-admins see only their own requests; admins see all. Links to File Leave and per-request View.
- Leave View: Admins can approve or reject with a reason (required on reject). Decisions are recorded in `leave_request_actions` and shown in history. Confirmation prompts added.

Access Control Summary
- Recruitment: Admin can create applicants, manage templates, upload files, and transition to employees; Write can upload files; Read can view only.
- Leave: All can file leave. Viewing allowed for read+; approvals restricted to Admin by default (adjustable via access templates).

Schema Updates
- `recruitment` table extended with `template_id` and `converted_employee_id`.
- Portable ALTER blocks at end of `database/schema.sql` auto-add these columns on existing databases.

## Troubleshooting

- If you see clean URL issues or redirect loops, re-check `BASE_URL` and proxy/HTTPS flags in `includes/config.php`, and the `.htaccess` rules.
- If you get “Cannot modify header information… headers already sent,” move `includes/header.php` below any POST redirect logic.
- If mouse cursor seems invisible on inputs, confirm the `assets/css/app.css` cursor/`caret-color` rules are loaded (hard refresh with Ctrl+F5).

## Contact Points in Code

- Layout: `includes/header.php`, `includes/footer.php`
- Auth/session: `includes/auth.php`, `includes/session.php`
- DB: `includes/db.php`
- Utils: `includes/utils.php`
- PDFs: `includes/pdf.php`, `modules/**/pdf*.php`, `modules/admin/pdf/index.php`
- UI: `assets/css/app.css`, `assets/js/app.js`
- Core modules: `modules/departments/*`, `modules/positions/*`, `modules/employees/*`, `modules/attendance/*`, `modules/audit/*`, `modules/admin/*`

---

This handoff reflects the repository on branch `main-production` at the time noted above. Continue using the POST-first-then-render pattern, absolute URLs via `BASE_URL`, and keep `.htaccess` minimal and extensionless.

## 2025-09-02 – Error Handling & Logging Sweep (This Session)

Summary
- Implemented standardized system error logging across key modules using `sys_log(code, message, meta)` in `includes/utils.php`.
- Preserved human-friendly validation messages; masked system errors for end users with generic prompts or flash notices.
- Added targeted logging codes by domain: `DB2xxx` (CRUD/queries), `AUTH300x` (login), `GEN47xx` (PDF vendor or generic), `DB27xx` (PDF template queries), `DB29xx` (view/audit).
- Added lightweight flash banners on list pages for delete/upload outcomes.

Key Edits
- includes/pdf.php
  - require utils; log DB/template prepare/execute failures (`DB2701/DB2702`), and vendor-missing fallback (`GEN4701`).
- includes/auth.php
  - log prepare failures during login and audit insert (`AUTH3001/AUTH3002`, `DB2901`).
- modules/employees
  - create.php: log prepare/execute failures (`DB2001/DB2002`); user sees generic error if DB fails; duplicate handled.
  - edit.php: log prepare/execute failures (`DB2101/DB2102`); user sees generic error; duplicate handled.
  - index.php: log count/list prepare failures (`DB2202/DB2203/DB2204`); flash messages on delete (`DB2201` on failure).
  - upload.php: log documents/doc_assign prepare/execute failures (`DB2301/DB2302/DB2303`); flash on success/failure; upload failure logs `GEN4301`.
  - view.php: log view/docs list prepare failures (`DB2911/DB2912`); display flash from upload.
  - pdf_list.php/pdf_profile.php: log query/prepare failures (`DB2801/DB2802`).
- modules/attendance
  - create.php: log prepare/execute failures (`DB2411/DB2412`), show generic error; duplicate entry message preserved.
  - import.php: log prepare failures (`DB2401/DB2402`); continue per-row; final audit + redirect with count.
  - pdf.php: log prepare failure (`DB2811`).
- modules/departments
  - create.php: log prepare/execute failures (`DB2501/DB2502`); duplicates handled; generic message otherwise.
  - edit.php: log prepare/execute failures (`DB2511/DB2512`).
  - index.php: log count/list prepare failures (`DB2522/DB2523/DB2524`); flash on delete (`DB2521` on failure).
- modules/positions
  - create.php: log prepare/execute failures (`DB2601/DB2602`).
  - edit.php: log prepare/execute failures (`DB2611/DB2612/DB2613`).
  - index.php: log count/list prepare failures (`DB2622/DB2623/DB2624`); flash on delete (`DB2621` on failure).

User-Facing Behavior
- Validation (human) errors remain inline (yellow/red banners as before).
- System errors now show a short generic message or a flash banner; technical details go to Admin → System Log.
- Delete/upload actions show a success/failure flash on the list/detail pages.

Admin System Log
- Access via sidebar (Admin only). Supports search, filters, pagination, and export to CSV/PDF.
- Codes follow: DB**** (database), AUTH**** (auth/session), PAY**** (payroll), GEN**** (generic).

Notes
- All logging calls are no-throw and won’t break user flow even if logging fails (best-effort).
 - Continue instrumenting remaining stub modules (Leave, Payroll, Performance, Recruitment, Documents) using the same code standards.

## 2025-09-04 – UI polish, CSV exports, and loader fixes (this session)

Summary
- Final UI polish and accessibility improvements across list pages, including replacing text "Search" buttons with compact icon-only buttons and adding consistent action-link spacing/dividers.
- Implemented CSV export endpoints for multiple modules, added a reusable CSV streamer helper, and ensured CSV downloads do not leave the global loader stuck.

Key UX & behavior changes
- Search UX
  - Replaced verbose "Search" buttons with icon-only buttons (magnifier SVG) for a compact, consistent UI. Buttons include `aria-label="Search"` and `title` for accessibility.
  - Files: `modules/audit/index.php`, `modules/positions/index.php`, `modules/employees/index.php`, `modules/departments/index.php`, `modules/account/index.php`.

- Action link spacing / dividers
  - Introduced an `.action-links` utility that renders inline action links with subtle vertical dividers for readability (View / PDF / Edit / Delete groups).
  - Files updated: `assets/css/app.css` (new `.action-links` rules), `modules/employees/index.php`, `modules/positions/index.php`, `modules/departments/index.php`, `modules/recruitment/index.php`, `modules/leave/index.php`.

- Compact icon buttons
  - Added `.btn-icon` class in `assets/css/app.css` for fixed-square icon buttons used by Search and Filter actions.

- Filter buttons
  - Converted text "Filter" buttons to funnel icon buttons on pages where present (Attendance and System Log) to match the new compact style.
  - Files: `modules/attendance/index.php`, `modules/admin/system_log.php`.

- CSV exports & streaming
  - Added a reusable CSV streaming helper `output_csv()` in `includes/utils.php` which streams UTF-8 CSV (with BOM) and terminates the request.
  - Implemented CSV endpoints for: Positions, Departments, Employees (list), Attendance, Recruitment, Leave, Audit.
    - New files (examples): `modules/positions/csv.php`, `modules/departments/csv.php`, `modules/employees/csv_list.php`, `modules/attendance/csv.php`, `modules/recruitment/csv.php`, `modules/leave/csv.php`, `modules/audit/csv.php`.
  - Wired Export dropdowns to use these CSV endpoints. CSV menu items now open in a new tab and include `data-no-loader` to avoid SPA/global loader conflicts.
  - Files updated: multiple `modules/*/index.php` files (Export menus) and `modules/admin/system_log.php`.

- Loader/stuck-on-download fix
  - `assets/js/app.js` updated so the global `beforeunload` loader is not shown when the initiating element opens a new tab or is explicitly marked with `data-no-loader`. This prevents the loading overlay from persisting after CSV downloads.
  - Ensures CSV links (which open a separate download tab) don't trigger and leave the app loader visible.

- Dropdown/Export UI polish
  - Added a dropdown caret `btn[data-dd-toggle]::after` to indicate Export menus and colored CSV (green) and PDF (red) menu items for visual affordance.
  - Files: `assets/css/app.css`, `assets/js/app.js` (dropdown toggler init already in place).

Files added or significantly edited (non-exhaustive)
- assets/css/app.css — added `.btn-icon`, `.action-links`, caret styles, CSV/PDF color classes, and small focus/cursor tweaks.
- assets/js/app.js — loader behavior fix, SPA refinements remain active, dropdown toggler initialization.
- includes/utils.php — added `output_csv()` helper and minor helpers already listed earlier.
- modules/positions/csv.php, modules/departments/csv.php, modules/employees/csv_list.php,
  modules/attendance/csv.php, modules/recruitment/csv.php, modules/leave/csv.php, modules/audit/csv.php — new CSV endpoints.
- modules/*/index.php updates — Export menus wired, CSV links marked `data-no-loader`, Search buttons replaced with icon-only buttons, action-links groups applied where relevant.

Notes & Follow-ups
- Audit CSV is implemented and wired; other module CSVs were added for the main list pages. Stub modules (Documents, Performance) remain without CSV until their lists are implemented.
- CSV links intentionally open in a new tab to avoid Ajax/SPA interception and to make downloads reliable across browsers. This also prevents the app loader from blocking the UI during download.
- If you prefer downloads to stream in the same tab, we can adjust the SPA logic to gracefully hide the loader once the navigation completes, but new-tab downloads are more robust for file downloads.

Validation & Tests performed
- Static lint/syntax checks: PASS across edited PHP/JS/CSS files.
- Manual smoke: opened Export CSV links (open in new tab) and confirmed files are served (headers, CSV contents), and the app loader remained hidden on the origin page.

If you want, I can now
- Apply `.action-links` to remaining modules that show inline actions (Documents, Leave/Recruitment expanded actions, Performance) — quick sweep.
- Convert remaining Filter/other text buttons to icon-only (if desired).
- Add small README snippet in `modules/*/csv.php` files showing query params supported.

