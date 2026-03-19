# HRIS Local Setup (Super Simple)

This guide is for a 1st year IT student using Windows and PowerShell.

Goal:

- Run HRIS locally
- Use normal local PostgreSQL first (no Docker)
- Keep Docker as Option 2

## Quick Answer

- Docker is not required.
- PostgreSQL is required.
- For beginners, Option 1 (normal install) is easier to understand.

## Option 1 (Recommended): Local PostgreSQL Install (No Docker)

## 0. Install Required Tools

Open PowerShell as Administrator and run:

```powershell
winget install --id PostgreSQL.PostgreSQL -e
winget install --id PHP.PHP.8.3 -e
winget install --id Composer.Composer -e
winget install --id OpenJS.NodeJS.LTS -e
winget install --id Git.Git -e
```

If one command fails, install that tool manually from its official website.

Then restart terminal and check:

```powershell
psql --version
php -v
composer --version
node -v
npm -v
git --version
```

## 1. Enable PHP PostgreSQL Dependency

HRIS needs these PHP extensions:

- pdo
- pdo_pgsql
- pgsql

Check loaded PHP config:

```powershell
php --ini
```

Open the loaded php.ini file and make sure these lines are enabled (remove ; if commented):

```ini
extension=pdo_pgsql
extension=pgsql
```

Verify:

```powershell
php -m | Select-String -Pattern "pdo_pgsql|pgsql"
```

If nothing is shown, extension is not enabled yet.

## 2. Open Project and Install Project Dependencies

```powershell
cd c:\workspace\hrms-sysfunda
composer install
npm install
```

Notes:

- composer install: PHP dependencies and dev tools
- npm install: frontend tooling (needed when rebuilding CSS)

## 3. Create Database

Use your PostgreSQL superuser (example: postgres):

```powershell
psql -U postgres -d postgres -c "CREATE DATABASE hrms WITH ENCODING 'UTF8';"
```

If database already exists, continue to next step.

## 4. Import Base Schema

Use this schema file:

```text
database/schema_postgre.sql
```

Import:

```powershell
psql -U postgres -d hrms -f database/schema_postgre.sql
```

## 5. Set Environment Variables (Current Terminal)

```powershell
$env:DATABASE_URL = "postgres://postgres:YOUR_DB_PASSWORD@localhost:5432/hrms"
$env:SUPERADMIN_DEFAULT_PASSWORD = "ChangeMeNow123!"
```

Optional:

```powershell
$env:SUPERADMIN_EMAIL = "admin@hrms.local"
$env:HRMS_TOOL_SECRET = "local-dev-tool-secret"
```

## 6. Apply Migrations and Reset Admin Password

```powershell
php tools/migrate.php
php tools/reset_admin.php
```

## 7. Start the App

```powershell
php -S localhost:8000 router.php
```

Open:

- http://localhost:8000/login

Login:

- Email: admin@hrms.local
- Password: value of SUPERADMIN_DEFAULT_PASSWORD

## 8. Use GitHub Copilot to Auto-Guide Setup

In VS Code Copilot Chat (Agent mode), paste this:

```text
Set up this HRIS project on Windows using local PostgreSQL (no Docker).
Show each command before running it.

Requirements:
- Verify psql and php are installed
- Verify PHP has pdo_pgsql and pgsql enabled
- Create database hrms if missing
- Import database/schema_postgre.sql
- Set env vars DATABASE_URL and SUPERADMIN_DEFAULT_PASSWORD in current terminal
- Run composer install and npm install if needed
- Run php tools/migrate.php
- Run php tools/reset_admin.php
- Start app with php -S localhost:8000 router.php
- Stop and explain clearly if any step fails
```

You can also ask Copilot to generate a script:

```text
Create a PowerShell script named tools/setup-local.ps1 to automate this setup with clear step-by-step output.
```

## Option 2 (Optional): PostgreSQL with Docker

Use this only if you prefer containers.

```powershell
docker pull postgres:16
docker run --name hris-postgres -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=hrms -p 5432:5432 -d postgres:16
Get-Content database/schema_postgre.sql | docker exec -i hris-postgres psql -U postgres -d hrms
$env:DATABASE_URL = "postgres://postgres:postgres@localhost:5432/hrms"
$env:SUPERADMIN_DEFAULT_PASSWORD = "ChangeMeNow123!"
php tools/migrate.php
php tools/reset_admin.php
php -S localhost:8000 router.php
```

If container already exists:

```powershell
docker start hris-postgres
```

## Fast Troubleshooting

### psql command not found

- PostgreSQL bin folder is not in PATH
- Reopen terminal or add PostgreSQL bin to PATH

### PHP cannot connect to PostgreSQL

- Check DATABASE_URL value
- Check pdo_pgsql extension is enabled
- Check PostgreSQL service is running

### Login fails

Run again:

```powershell
php tools/reset_admin.php
```

Then log in with your current SUPERADMIN_DEFAULT_PASSWORD.

## One Copy-Paste Block (Option 1)

Use this from project root after PostgreSQL and PHP dependencies are installed:

```powershell
cd c:\workspace\hrms-sysfunda
composer install
npm install
psql -U postgres -d postgres -c "CREATE DATABASE hrms WITH ENCODING 'UTF8';"
psql -U postgres -d hrms -f database/schema_postgre.sql
$env:DATABASE_URL = "postgres://postgres:YOUR_DB_PASSWORD@localhost:5432/hrms"
$env:SUPERADMIN_DEFAULT_PASSWORD = "ChangeMeNow123!"
php tools/migrate.php
php tools/reset_admin.php
php -S localhost:8000 router.php
```

Done. Open http://localhost:8000/login
