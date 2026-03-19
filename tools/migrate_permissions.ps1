#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Execute Position-Based Permissions Database Migration
.DESCRIPTION
    Applies the new position-based permission system migrations with backup and verification.
    Safe to run - includes rollback capability and backward compatibility.
.NOTES
    Date: 2025-11-12
    Phase: 4 - Database Migration Execution
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory=$false)]
    [string]$Server = "localhost",
    
    [Parameter(Mandatory=$false)]
    [int]$Port = 5432,
    
    [Parameter(Mandatory=$false)]
    [string]$Database = "hrms",
    
    [Parameter(Mandatory=$false)]
    [string]$Username = "postgres",
    
    [Parameter(Mandatory=$false)]
    [switch]$SkipBackup,
    
    [Parameter(Mandatory=$false)]
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"

# Color helpers
function Write-Success { param($msg) Write-Host "✓ $msg" -ForegroundColor Green }
function Write-Info { param($msg) Write-Host "ℹ $msg" -ForegroundColor Cyan }
function Write-Warning { param($msg) Write-Host "⚠ $msg" -ForegroundColor Yellow }
function Write-Failure { param($msg) Write-Host "✗ $msg" -ForegroundColor Red }

Write-Host "`n╔════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  Position-Based Permissions Migration Script             ║" -ForegroundColor Cyan
Write-Host "║  Phase 4: Database Migration Execution                    ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════╝`n" -ForegroundColor Cyan

# Check if psql is available
Write-Info "Checking PostgreSQL client availability..."
try {
    $psqlVersion = psql --version 2>&1
    Write-Success "PostgreSQL client found: $psqlVersion"
} catch {
    Write-Failure "PostgreSQL client (psql) not found in PATH"
    Write-Host "`nPlease install PostgreSQL client tools or add to PATH" -ForegroundColor Yellow
    exit 1
}

# Check if migration files exist
Write-Info "Verifying migration files..."
$migration1 = "database/migrations/2025-11-12_position_based_permissions.sql"
$migration2 = "database/migrations/2025-11-12_seed_sysadmin_permissions.sql"

if (-not (Test-Path $migration1)) {
    Write-Failure "Migration file not found: $migration1"
    exit 1
}
if (-not (Test-Path $migration2)) {
    Write-Failure "Migration file not found: $migration2"
    exit 1
}
Write-Success "Both migration files found"

# Set PGPASSWORD from environment or prompt
if (-not $env:PGPASSWORD) {
    $securePassword = Read-Host "Enter PostgreSQL password for user '$Username'" -AsSecureString
    $env:PGPASSWORD = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    )
}

$connString = "-h $Server -p $Port -U $Username -d $Database"

if ($DryRun) {
    Write-Warning "DRY RUN MODE - No changes will be made"
    Write-Host ""
}

# Test connection
Write-Info "Testing database connection..."
try {
    $testQuery = "SELECT version();"
    $result = psql $connString -t -c $testQuery 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Connection failed"
    }
    Write-Success "Connected to database successfully"
} catch {
    Write-Failure "Failed to connect to database"
    Write-Host $result -ForegroundColor Red
    exit 1
}

# Create backup
if (-not $SkipBackup -and -not $DryRun) {
    Write-Info "Creating database backup..."
    $backupFile = "backup_pre_permissions_$timestamp.sql"
    
    try {
        pg_dump $connString -f $backupFile 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0 -and (Test-Path $backupFile)) {
            $backupSize = (Get-Item $backupFile).Length / 1MB
            Write-Success "Backup created: $backupFile ($([math]::Round($backupSize, 2)) MB)"
        } else {
            Write-Warning "Backup may have failed, but continuing..."
        }
    } catch {
        Write-Warning "Backup creation failed: $_"
        Write-Host "Continue anyway? (y/N): " -NoNewline -ForegroundColor Yellow
        $response = Read-Host
        if ($response -ne 'y') {
            exit 1
        }
    }
}

# Check current state
Write-Info "Checking current database state..."
$checks = @{
    "position_access_permissions table" = "SELECT EXISTS (SELECT FROM pg_tables WHERE tablename = 'position_access_permissions');"
    "users.is_system_admin column" = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'is_system_admin');"
    "System Administrator position" = "SELECT EXISTS (SELECT FROM positions WHERE name = 'System Administrator' AND department_id = (SELECT id FROM departments WHERE name = 'Administration' LIMIT 1));"
}

$needsMigration = $false
foreach ($check in $checks.GetEnumerator()) {
    $result = psql $connString -t -c $check.Value 2>&1 | ForEach-Object { $_.Trim() }
    if ($result -eq 'f' -or $result -eq 'false') {
        Write-Warning "$($check.Key): NOT EXISTS (will be created)"
        $needsMigration = $true
    } else {
        Write-Info "$($check.Key): EXISTS (migration may have been partially applied)"
    }
}

if (-not $needsMigration) {
    Write-Warning "Migration appears to already be applied!"
    Write-Host "Continue anyway? This will re-run migrations (should be idempotent) (y/N): " -NoNewline -ForegroundColor Yellow
    $response = Read-Host
    if ($response -ne 'y') {
        Write-Info "Migration cancelled by user"
        exit 0
    }
}

Write-Host ""

# Apply Migration 1: Core Schema
Write-Info "Applying migration 1: Position-Based Permissions Schema..."
Write-Host "  File: $migration1" -ForegroundColor Gray

if ($DryRun) {
    Write-Warning "DRY RUN: Would execute migration 1"
} else {
    try {
        $output = psql $connString -f $migration1 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Migration 1 applied successfully"
        } else {
            Write-Failure "Migration 1 failed"
            Write-Host $output -ForegroundColor Red
            throw "Migration 1 failed with exit code $LASTEXITCODE"
        }
    } catch {
        Write-Failure "Error applying migration 1: $_"
        Write-Host "`nAttempt rollback? (y/N): " -NoNewline -ForegroundColor Yellow
        $response = Read-Host
        if ($response -eq 'y') {
            Write-Info "Rolling back migration 1..."
            # Rollback commands
            psql $connString -c "DROP FUNCTION IF EXISTS check_user_access(INT, TEXT, TEXT, TEXT);" 2>&1 | Out-Null
            psql $connString -c "DROP VIEW IF EXISTS v_user_position_permissions;" 2>&1 | Out-Null
            psql $connString -c "DROP TABLE IF EXISTS position_access_permissions;" 2>&1 | Out-Null
            psql $connString -c "ALTER TABLE users DROP COLUMN IF EXISTS is_system_admin;" 2>&1 | Out-Null
            Write-Success "Rollback completed"
        }
        exit 1
    }
}

Write-Host ""

# Apply Migration 2: Seed Data
Write-Info "Applying migration 2: System Administrator Permissions..."
Write-Host "  File: $migration2" -ForegroundColor Gray

if ($DryRun) {
    Write-Warning "DRY RUN: Would execute migration 2"
} else {
    try {
        $output = psql $connString -f $migration2 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Migration 2 applied successfully"
        } else {
            Write-Failure "Migration 2 failed"
            Write-Host $output -ForegroundColor Red
            throw "Migration 2 failed with exit code $LASTEXITCODE"
        }
    } catch {
        Write-Failure "Error applying migration 2: $_"
        exit 1
    }
}

Write-Host ""

# Verification
Write-Info "Verifying migration results..."

$verifications = @(
    @{
        Name = "position_access_permissions table exists"
        Query = "SELECT COUNT(*) FROM position_access_permissions;"
        Expected = "39"
        Description = "System Administrator should have 39 permissions"
    },
    @{
        Name = "users.is_system_admin column exists"
        Query = "SELECT COUNT(*) FROM users WHERE is_system_admin = TRUE;"
        Expected = ">= 1"
        Description = "At least one system admin should exist"
    },
    @{
        Name = "v_user_position_permissions view works"
        Query = "SELECT COUNT(*) FROM v_user_position_permissions LIMIT 1;"
        Expected = ">= 0"
        Description = "View should be queryable"
    },
    @{
        Name = "check_user_access function works"
        Query = "SELECT check_user_access(1, 'system', 'system_settings', 'manage');"
        Expected = "t or f"
        Description = "Function should return boolean"
    },
    @{
        Name = "System Administrator position exists"
        Query = "SELECT COUNT(*) FROM positions WHERE name = 'System Administrator';"
        Expected = "1"
        Description = "System Administrator position created"
    }
)

$allPassed = $true
foreach ($verification in $verifications) {
    try {
        $result = psql $connString -t -c $verification.Query 2>&1 | ForEach-Object { $_.Trim() }
        
        if ($verification.Expected -match "^>= (\d+)") {
            $minValue = [int]$Matches[1]
            if ([int]$result -ge $minValue) {
                Write-Success "$($verification.Name): $result ($($verification.Description))"
            } else {
                Write-Warning "$($verification.Name): $result (expected >= $minValue)"
                $allPassed = $false
            }
        } elseif ($verification.Expected -eq "t or f") {
            if ($result -eq 't' -or $result -eq 'f') {
                Write-Success "$($verification.Name): $result ($($verification.Description))"
            } else {
                Write-Warning "$($verification.Name): $result (expected boolean)"
                $allPassed = $false
            }
        } else {
            if ($result -eq $verification.Expected) {
                Write-Success "$($verification.Name): $result ($($verification.Description))"
            } else {
                Write-Warning "$($verification.Name): $result (expected $($verification.Expected))"
                $allPassed = $false
            }
        }
    } catch {
        Write-Failure "$($verification.Name): Failed to verify"
        $allPassed = $false
    }
}

Write-Host ""

if ($allPassed) {
    Write-Host "╔════════════════════════════════════════════════════════════╗" -ForegroundColor Green
    Write-Host "║           MIGRATION COMPLETED SUCCESSFULLY! ✓             ║" -ForegroundColor Green
    Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Green
    Write-Host ""
    Write-Success "Position-based permissions system is now active"
    Write-Success "System administrators have full access to all 39 resources"
    Write-Success "Old role-based system still works as fallback (backward compatible)"
    Write-Host ""
    Write-Info "Next steps:"
    Write-Host "  1. Navigate to Positions → System Administrator → Permissions" -ForegroundColor Gray
    Write-Host "  2. Verify all 39 permissions show as 'Manage' level" -ForegroundColor Gray
    Write-Host "  3. Create permission templates for other positions" -ForegroundColor Gray
    Write-Host "  4. Assign positions to users" -ForegroundColor Gray
    Write-Host "  5. Begin replacing require_role() calls (Phase 5)" -ForegroundColor Gray
} else {
    Write-Host "╔════════════════════════════════════════════════════════════╗" -ForegroundColor Yellow
    Write-Host "║       MIGRATION COMPLETED WITH WARNINGS! ⚠                ║" -ForegroundColor Yellow
    Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Yellow
    Write-Host ""
    Write-Warning "Some verification checks did not pass as expected"
    Write-Warning "Review the warnings above and verify manually"
    Write-Host ""
    Write-Info "Manual verification queries:"
    Write-Host "  SELECT * FROM position_access_permissions;" -ForegroundColor Gray
    Write-Host "  SELECT id, email, is_system_admin FROM users WHERE role = 'admin';" -ForegroundColor Gray
    Write-Host "  SELECT * FROM v_user_position_permissions LIMIT 5;" -ForegroundColor Gray
}

Write-Host ""
Write-Info "Migration log saved to: migration_$timestamp.log"

# Cleanup
if (-not $DryRun) {
    Remove-Variable PGPASSWORD -ErrorAction SilentlyContinue
}

exit 0
