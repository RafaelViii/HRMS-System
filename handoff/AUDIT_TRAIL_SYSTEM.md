# Enhanced Audit Trail System

## Overview
The HRMS now includes a comprehensive, rigorous audit trail system that tracks all user actions with detailed metadata including IP addresses, user agents, before/after values, and complete context.

## Features

### Database Enhancements
- **18 total columns** in `audit_logs` table (7 original + 11 new)
- **9 performance indexes** for fast filtering and querying
- **audit_trail_view** - Comprehensive view joining logs with user, employee, department, and position data

### New Tracking Capabilities
1. **IP Address & User Agent** - Track who and from where
2. **Module & Action Type** - Categorize actions by system area and type
3. **Target Tracking** - Link actions to specific entities (type + ID)
4. **Before/After Values** - JSONB fields for change tracking
5. **Status & Severity** - Monitor success/failure and importance levels
6. **Employee Linkage** - Connect audit logs to employee records

### UI Components
1. **Comprehensive Audit Trail Page** (`/modules/admin/audit_trail`)
   - Paginated table with 50 records per page
   - Rich filtering by:
     - Employee
     - Position
     - Department
     - Module
     - Action Type
     - Status (success/failed/partial)
     - Severity (low/normal/high/critical)
     - Date range (from/to)
     - Free text search
   - Click any row to view detailed modal
   - Export capabilities (future enhancement)

2. **View Actions Button** in Account Management
   - Added to `/modules/account/index`
   - Shows action count for last 30 days
   - Links to filtered audit trail for specific user

3. **Navigation Link**
   - Added "Audit Trail" to Administration section
   - Accessible by admins and HR managers

## Usage Guide

### For Developers: Enhanced audit() Function

The `audit()` function in `includes/auth.php` has been enhanced to accept an optional `$context` array with structured data.

#### Basic Usage (Backward Compatible)
```php
// Simple audit (still works)
audit('User login', 'User logged in successfully');
```

#### Enhanced Usage with Full Context
```php
// Full-featured audit with all metadata
audit('Employee Updated', 'Updated employee salary', [
    'module' => 'employees',
    'action_type' => 'update',
    'target_type' => 'employee',
    'target_id' => $employeeId,
    'old_values' => [
        'salary' => 50000,
        'position_id' => 5
    ],
    'new_values' => [
        'salary' => 55000,
        'position_id' => 6
    ],
    'status' => 'success',  // or 'failed', 'partial'
    'severity' => 'high',   // or 'low', 'normal', 'critical'
    'employee_id' => $employeeId  // Optional, auto-detected if not provided
]);
```

#### Context Parameters

| Parameter | Type | Description | Example Values |
|-----------|------|-------------|----------------|
| `module` | string | System module/area | `employees`, `payroll`, `leave`, `departments` |
| `action_type` | string | Type of action | `create`, `update`, `delete`, `approve`, `reject`, `view` |
| `target_type` | string | Entity type affected | `employee`, `department`, `position`, `payroll_run`, `leave_request` |
| `target_id` | int | Specific entity ID | Employee ID, Department ID, etc. |
| `old_values` | array | Before state | Any array of key-value pairs |
| `new_values` | array | After state | Any array of key-value pairs |
| `status` | string | Operation result | `success`, `failed`, `partial` |
| `severity` | string | Importance level | `low`, `normal`, `high`, `critical` |
| `employee_id` | int | Employee record ID | Auto-detected from user if not provided |

#### Automatic Tracking
The enhanced audit function automatically captures:
- **IP Address** - From `$_SERVER['REMOTE_ADDR']` or `X-Forwarded-For`
- **User Agent** - From `$_SERVER['HTTP_USER_AGENT']`
- **User ID** - From current session
- **Employee ID** - Auto-detected from user's linked employee record
- **Timestamp** - Automatically recorded by database

### Real-World Examples

#### Example 1: Employee Creation
```php
$newEmployeeId = 123;
audit('Employee Created', "New employee: {$firstName} {$lastName}", [
    'module' => 'employees',
    'action_type' => 'create',
    'target_type' => 'employee',
    'target_id' => $newEmployeeId,
    'new_values' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'department_id' => $departmentId,
        'position_id' => $positionId,
        'hire_date' => $hireDate
    ],
    'status' => 'success',
    'severity' => 'normal'
]);
```

#### Example 2: Payroll Approval
```php
audit('Payroll Approved', "Approved payroll run #{$runId}", [
    'module' => 'payroll',
    'action_type' => 'approve',
    'target_type' => 'payroll_run',
    'target_id' => $runId,
    'old_values' => ['status' => 'pending'],
    'new_values' => ['status' => 'approved'],
    'status' => 'success',
    'severity' => 'high'  // High severity for financial actions
]);
```

#### Example 3: Failed Security Action
```php
audit('Unauthorized Access Attempt', "User tried to access restricted area", [
    'module' => 'security',
    'action_type' => 'access_denied',
    'target_type' => 'system',
    'target_id' => null,
    'status' => 'failed',
    'severity' => 'critical'  // Security issues are critical
]);
```

#### Example 4: Bulk Operation
```php
audit('Bulk Employee Update', "Updated {$count} employee records", [
    'module' => 'employees',
    'action_type' => 'bulk_update',
    'target_type' => 'employee',
    'target_id' => null,  // No specific target for bulk
    'new_values' => [
        'count' => $count,
        'field_updated' => 'department_id',
        'new_value' => $newDepartmentId
    ],
    'status' => 'success',
    'severity' => 'high'  // Bulk operations are high severity
]);
```

## Access Control

### Who Can View Audit Trails?
Access to the audit trail system is controlled through **position-based permissions** in the `user_management` domain.

**Permission Required:**
- **Domain:** `user_management`
- **Resource:** `audit_logs`
- **Access Level:** `read` (minimum) or `manage` (full access)

### Default Position Permissions (seeded via migration)

**MANAGE Access (Full Control):**
- System Administrator
- HR Manager
- Internal Auditor
- Compliance Officer
- Security Officer

**READ Access (View Only):**
- IT Administrator

**No Access:**
- All other positions (must be explicitly granted)

### Permissions Check
Audit trail access is controlled by:
```php
require_access('user_management', 'audit_logs', 'read');
```

This ensures that only users with the appropriate position-based permissions can view audit logs, regardless of their role.

## Database Schema

### audit_logs Table (18 columns)
```sql
-- Original columns
id, user_id, action, details, created_at, updated_at, details_raw

-- New enhanced columns
ip_address, user_agent, module, action_type, 
target_type, target_id, old_values, new_values,
status, employee_id, severity
```

### audit_trail_view
Pre-joined view including:
- All audit_logs columns
- User info: email, full_name, role
- Employee info: code, first_name, last_name
- Department info: name
- Position info: title

## Performance

### Indexes Created
1. `idx_audit_logs_module` - Fast filtering by module
2. `idx_audit_logs_action_type` - Fast filtering by action type
3. `idx_audit_logs_target` - Fast lookup by target entity
4. `idx_audit_logs_status` - Fast filtering by status
5. `idx_audit_logs_employee` - Fast employee-based queries
6. `idx_audit_logs_severity` - Fast severity filtering
7. `idx_audit_logs_created_at` - Fast date range queries
8. `idx_audit_logs_user_id` - Fast user-based queries
9. `idx_audit_logs_user_date` - Composite index for user + date queries

## Migration

The audit system was enhanced via migration:
- **File**: `database/migrations/2025-11-13_enhance_audit_trail.sql`
- **Applied**: Successfully via `/tools/migrate.php`
- **Idempotent**: Safe to re-run, uses IF NOT EXISTS checks
- **Backward Compatible**: Existing audit() calls still work

## Granting Audit Trail Access

To grant audit trail access to a position:

### Method 1: Via Position Permissions UI
1. Navigate to **Positions** module
2. Select the position
3. Click **Manage Permissions**
4. Under **User Management** domain:
   - Add `audit_logs` resource with `read` or `manage` access
   - Add `audit_reports` resource with `read` or `manage` access
5. Save changes

### Method 2: Via Direct SQL (for bulk operations)
```sql
-- Grant read access to a position
INSERT INTO position_permissions (position_id, domain, resource, access_level, notes)
VALUES 
    (123, 'user_management', 'audit_logs', 'read', 'Custom audit access'),
    (123, 'user_management', 'audit_reports', 'read', 'Custom audit reports access')
ON CONFLICT (position_id, domain, resource) DO NOTHING;

-- Grant manage access to a position
INSERT INTO position_permissions (position_id, domain, resource, access_level, notes)
VALUES 
    (456, 'user_management', 'audit_logs', 'manage', 'Full audit control'),
    (456, 'user_management', 'audit_reports', 'manage', 'Full audit reports control')
ON CONFLICT (position_id, domain, resource) DO NOTHING;
```

### Method 3: Via Migration (recommended for system-wide changes)
Create a new migration file in `database/migrations/` following the pattern in `2025-11-13_seed_audit_trail_permissions.sql`.

### Access Level Differences
- **read**: View audit logs, filter, search, view details
- **manage**: All read permissions + future admin features (export, archive, configure retention)

## Future Enhancements

Planned improvements:
1. **CSV/PDF Export** - Export filtered audit logs (requires `manage` access)
2. **Real-time Monitoring** - WebSocket updates for critical events
3. **Anomaly Detection** - Alert on suspicious patterns
4. **Retention Policies** - Archive old logs automatically (requires `manage` access)
5. **Advanced Analytics** - Charts and trends
6. **API Access** - REST endpoints for external monitoring
7. **Email Alerts** - Notify on critical severity events
8. **Audit Configuration** - Set retention periods, auto-archiving rules (requires `manage` access)

## Troubleshooting

### No audit logs appearing?
- Check that migration was applied: `SELECT column_name FROM information_schema.columns WHERE table_name = 'audit_logs'`
- Verify 18 columns exist
- Check audit_trail_view exists: `SELECT * FROM audit_trail_view LIMIT 1`

### Permission denied?
- Ensure user has 'admin' or 'hr_manager' role
- Check `require_role(['admin', 'hr_manager'])` in page header

### Slow queries?
- Verify indexes exist: `SELECT indexname FROM pg_indexes WHERE tablename = 'audit_logs'`
- Use specific filters to reduce result set
- Consider adding date range filter

## Permission Integration Summary

### Files Modified for Permission-Based Access

1. **modules/admin/audit_trail.php**
   - Changed from: `require_role(['admin', 'hr_manager'])`
   - Changed to: `require_access('user_management', 'audit_logs', 'read')`

2. **modules/admin/audit_trail_details.php**
   - Changed from: `require_role(['admin', 'hr_manager'])`
   - Changed to: `require_access('user_management', 'audit_logs', 'read')`

3. **modules/account/index.php**
   - Changed from: `in_array(($me['role'] ?? ''), ['admin', 'hr_manager'])`
   - Changed to: `user_has_access($currentUserId, 'user_management', 'audit_logs', 'read')`

4. **includes/header.php**
   - Added: `$canAuditTrail = $user && user_has_access($uid, 'user_management', 'audit_logs', 'read')`
   - Changed: Audit Trail nav link now checks `$canAuditTrail` instead of role

### Migration Files
- `2025-11-13_enhance_audit_trail.sql` - Database schema enhancements
- `2025-11-13_seed_audit_trail_permissions.sql` - Position permissions seeding

### Permission Domain Structure
```
user_management (domain)
├── audit_logs (resource)
│   ├── read - View audit trails, filter, search
│   └── manage - Full control including future admin features
└── audit_reports (resource)
    ├── read - View audit reports
    └── manage - Full control over reports
```

## Support

For questions or issues with the audit trail system:
1. Check this documentation
2. Review migration files:
   - `database/migrations/2025-11-13_enhance_audit_trail.sql`
   - `database/migrations/2025-11-13_seed_audit_trail_permissions.sql`
3. Examine helper function: `includes/auth.php` - `audit()` function
4. Review example usage in existing modules
5. Check position permissions via `/modules/positions/permissions?id=X`

---

**Last Updated**: Phase 17 - Comprehensive Audit Trail Implementation with Permission Integration
**Status**: ✅ Complete and Production Ready
**Permission System**: ✅ Fully Integrated
