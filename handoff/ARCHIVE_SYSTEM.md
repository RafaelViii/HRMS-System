# Archive Management System

## Overview
The Archive Management System provides a comprehensive soft-delete mechanism for the HRMS, ensuring that no data is permanently deleted without proper review and retention policies.

## Key Features

### 1. **Soft Delete (Archive)**
- All delete operations move records to an archive state instead of permanent deletion
- Archived records are hidden from normal views but remain in the database
- Full audit trail of who deleted what and when

### 2. **Recovery**
- Archived records can be recovered back to active state
- Recovery operations are fully audited
- Simple one-click recovery from the archive view

### 3. **Retention Policies**
- Configurable auto-delete period (default: 90 days)
- Automatic cleanup of old archived records
- Can be disabled by setting retention to 0 days

### 4. **Permanent Deletion**
- Manual permanent deletion requires explicit confirmation
- User must type "DELETE" to confirm
- Logged with critical severity in audit trail
- Creates detailed audit entry with full record snapshot

### 5. **Full Audit Trail**
- Every archive operation is logged
- Every recovery is logged
- Every permanent deletion is logged with critical severity
- Auto-cleanup operations are logged

## Database Schema

### System Settings Table
```sql
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER REFERENCES employees(id)
);
```

### Soft Delete Columns (added to all archivable tables)
```sql
deleted_at TIMESTAMP NULL
deleted_by INTEGER REFERENCES employees(id)
```

## Supported Tables
- `employees`
- `departments`
- `positions`
- `leaves`
- `memos`
- `documents`

## File Structure

### Core Pages
- `modules/admin/system/archive.php` - Main archive management dashboard
- `modules/admin/system/archive_view.php` - View archived records by table
- `modules/admin/system/archive_recover.php` - Recovery endpoint (API)
- `modules/admin/system/archive_delete_permanent.php` - Permanent deletion endpoint (API)
- `modules/admin/system/backup_database.php` - Database backup endpoint

### Utilities
- `includes/utils.php` - Helper functions (`soft_delete()`, `is_archived()`)
- `tools/archive_cleanup.php` - Automated cleanup script

### Database
- `database/migrations/2025-01-09_archive_system.sql` - Schema migration

## Usage

### For Developers: Implementing Soft Delete

**Old way (permanent delete):**
```php
$stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
$stmt->execute([$employeeId]);
```

**New way (soft delete):**
```php
require_once __DIR__ . '/../../includes/utils.php';

$user = current_user();
$success = soft_delete($pdo, 'employees', $employeeId, $user['id']);

if ($success) {
    flash_success('Employee archived successfully');
} else {
    flash_error('Failed to archive employee');
}
```

### For Developers: Excluding Archived Records

**Add to all SELECT queries:**
```php
// Before
$stmt = $pdo->query("SELECT * FROM employees");

// After
$stmt = $pdo->query("SELECT * FROM employees WHERE deleted_at IS NULL");
```

**For COUNT queries:**
```php
// Before
$stmt = $pdo->query("SELECT COUNT(*) FROM departments");

// After
$stmt = $pdo->query("SELECT COUNT(*) FROM departments WHERE deleted_at IS NULL");
```

### Archive Management Dashboard

**Access:** System Management → Archive Management

**Features:**
- View total archived records across all tables
- Configure retention policies
- Enable/disable archive system
- Browse archived records by table type
- Bulk statistics and monitoring

### Archive View Page

**Access:** Archive Management → Click on any table

**Features:**
- List all archived records from selected table
- View when and by whom records were deleted
- Recover individual records
- Permanently delete individual records (with confirmation)
- Table-specific column display

### Recovery Process

1. Navigate to Archive View for desired table
2. Click "Recover" button on target record
3. Confirm recovery in modal
4. Record is restored to active state
5. Operation is logged in action log

### Permanent Deletion Process

1. Navigate to Archive View for desired table
2. Click "Delete Forever" button on target record
3. Type "DELETE" in confirmation modal
4. Confirm permanent deletion
5. Record is permanently removed from database
6. Operation is logged with critical severity

## Configuration

### Archive Settings

**Location:** Archive Management → Archive Settings section

**Settings:**
- **Auto-Delete After (Days):** Number of days to retain archived records (0 = never delete)
- **Enable Archive System:** Master toggle for archive functionality

**Default Values:**
```php
'archive_enabled' => true
'archive_auto_delete_days' => 90
```

## Automated Cleanup

### CLI Usage
```bash
php tools/archive_cleanup.php
```

### Cron Setup
```bash
# Run daily at 2 AM
0 2 * * * cd /path/to/hrms && php tools/archive_cleanup.php >> logs/archive_cleanup.log 2>&1
```

### Web Interface
The cleanup script can also be triggered via web:
```
GET /tools/archive_cleanup.php
```
(Requires system_management write permission)

### Cleanup Process
1. Check if archive system is enabled
2. Get auto-delete days setting
3. Calculate cutoff date (current date - retention days)
4. Call `cleanup_old_archives()` database function
5. Log results for each table
6. Create summary action log entry

## Security & Permissions

### Required Permissions
- **View Archive:** `system_management` read access
- **Recover Records:** `system_management` write access
- **Permanent Delete:** `system_management` write access
- **Configure Settings:** `system_management` write access

### CSRF Protection
All POST operations require valid CSRF token:
```php
csrf_verify($_POST['csrf_token'] ?? '');
```

### Audit Logging
Every operation is logged with appropriate severity:
- **Soft Delete:** `severity => 'info'`
- **Recovery:** `severity => 'info'`
- **Permanent Delete:** `severity => 'critical'`
- **Auto-Cleanup:** `severity => 'warning'`

## Database Functions

### `archive_record(table_name, record_id, deleted_by)`
Safe function to soft delete a record.

**Parameters:**
- `table_name`: Table name (validated against whitelist)
- `record_id`: ID of record to archive
- `deleted_by`: Employee ID performing deletion

**Returns:** Boolean (true on success)

**Example:**
```sql
SELECT archive_record('employees', 123, 456);
```

### `cleanup_old_archives()`
Automatically deletes archived records older than retention period.

**Returns:** Table of (table_name, records_deleted)

**Example:**
```sql
SELECT * FROM cleanup_old_archives();
```

## UI Components

### Archive Management Dashboard
- **Stats Cards:** Total archived, auto-delete period, system status
- **Settings Form:** Configure retention and enable/disable
- **Table Browser:** Grid of all archivable tables with counts

### Archive View Page
- **Table-Specific Columns:** Relevant fields for each entity type
- **Action Buttons:** Recover and Delete Forever
- **Confirmation Modals:** Safe confirmation for destructive actions
- **Real-time Feedback:** Loading states and flash messages

## Best Practices

### For Developers
1. **Always use `soft_delete()`** instead of direct DELETE queries
2. **Add `WHERE deleted_at IS NULL`** to all SELECT queries
3. **Test archive/recovery flows** for new features
4. **Document archivable entities** in feature specs
5. **Use appropriate severity levels** in action logs

### For Administrators
1. **Review archived records regularly** before permanent deletion
2. **Set appropriate retention periods** based on compliance requirements
3. **Monitor auto-cleanup logs** for unexpected deletions
4. **Create database backups** before mass permanent deletions
5. **Test recovery process** periodically

### For System Administrators
1. **Set up cron job** for automated cleanup
2. **Monitor disk usage** (archived records consume space)
3. **Configure backup before cleanup** in production
4. **Review action logs** for audit compliance
5. **Test disaster recovery** procedures

## Migration Guide

### Applying the Migration
```bash
# Via web interface
php tools/migrate.php

# Or direct PostgreSQL
psql -U username -d database_name -f database/migrations/2025-01-09_archive_system.sql
```

### Migrating Existing Delete Operations

**Step 1:** Find all DELETE queries
```bash
grep -r "DELETE FROM" modules/
```

**Step 2:** Replace with soft_delete()
```php
// Before
$pdo->exec("DELETE FROM employees WHERE id = $id");

// After
soft_delete($pdo, 'employees', $id, $user['id']);
```

**Step 3:** Add WHERE clauses to SELECTs
```php
// Before
SELECT * FROM employees

// After
SELECT * FROM employees WHERE deleted_at IS NULL
```

**Step 4:** Test thoroughly
- Verify records are archived, not deleted
- Confirm recovery works
- Test permanent deletion
- Check audit logs

## Troubleshooting

### Records Not Appearing in Archive
- Check if `deleted_at IS NOT NULL` in query
- Verify table has `deleted_at` column
- Run migration if column missing

### Cannot Recover Record
- Verify user has system_management write permission
- Check if record still exists in database
- Review error logs in action_log

### Auto-Cleanup Not Running
- Verify cron job is configured correctly
- Check archive system is enabled
- Ensure auto-delete days > 0
- Review cleanup logs

### Database Backup Fails
- Verify pg_dump is in PATH
- Check PostgreSQL credentials
- Ensure sufficient disk space
- Review system error logs

## API Endpoints

### Recovery Endpoint
**URL:** `/modules/admin/system/archive_recover.php`
**Method:** POST
**Parameters:**
- `table`: Table name
- `id`: Record ID
- `csrf_token`: CSRF token

**Response:**
```json
{
  "success": true,
  "message": "Record recovered successfully"
}
```

### Permanent Delete Endpoint
**URL:** `/modules/admin/system/archive_delete_permanent.php`
**Method:** POST
**Parameters:**
- `table`: Table name
- `id`: Record ID
- `csrf_token`: CSRF token

**Response:**
```json
{
  "success": true,
  "message": "Record permanently deleted"
}
```

### Database Backup Endpoint
**URL:** `/modules/admin/system/backup_database.php`
**Method:** GET

**Response:** SQL file download

## Compliance & Audit

### Retention Compliance
- Configurable retention periods meet compliance requirements
- Auto-deletion provides automated data lifecycle management
- Full audit trail for regulatory compliance

### Audit Trail
All operations logged with:
- User ID performing action
- Timestamp of operation
- Full record state (before/after)
- Operation result (success/error)
- Severity level

### Data Recovery
- Point-in-time recovery via archive system
- Database backups for disaster recovery
- Granular recovery at record level

## Performance Considerations

### Indexes
All `deleted_at` columns are indexed:
```sql
CREATE INDEX idx_employees_deleted_at ON employees(deleted_at);
```

### Query Performance
Adding `WHERE deleted_at IS NULL` is optimized via indexes and should not impact performance.

### Storage
Archived records consume database space. Monitor with:
```sql
SELECT 
    table_name,
    COUNT(*) as archived_count
FROM (
    SELECT 'employees' as table_name, COUNT(*) FROM employees WHERE deleted_at IS NOT NULL
    UNION ALL
    SELECT 'departments', COUNT(*) FROM departments WHERE deleted_at IS NOT NULL
    -- ... etc
) counts
GROUP BY table_name;
```

## Future Enhancements

### Planned Features
- [ ] Archive export to external storage
- [ ] Batch recovery operations
- [ ] Archive search and filtering
- [ ] Custom retention periods per table
- [ ] Archive analytics dashboard
- [ ] Email notifications for auto-cleanup
- [ ] Archive compression for storage optimization

### Integration Opportunities
- Integration with backup system
- Integration with notification system
- Integration with reporting system
- Integration with compliance audits

## Support

### Logging
- **Action Logs:** View in Action Log page
- **System Logs:** Check `sys_log` table
- **Cleanup Logs:** Review cron job output

### Debugging
Enable detailed logging:
```php
// In includes/config.php
define('DEBUG_ARCHIVE', true);
```

### Contact
For issues or questions, contact the system administrator or review the action log for detailed operation history.
