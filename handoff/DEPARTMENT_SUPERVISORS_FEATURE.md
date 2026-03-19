# Department Supervisors Feature

## Overview
The Department Supervisors feature enables department-level leave approval workflows. Each department can have multiple supervisors who are authorized to approve leave requests for employees in their department.

## Key Features

### 1. Multiple Supervisors per Department
- Departments can have one or more supervisors
- No limit on the number of supervisors per department
- Each supervisor assignment is tracked with timestamp and assigning admin

### 2. Same-Department Supervision
- Supervisors can be employees from the same department
- Most common scenario: promote a team lead to supervisor
- Automatically manages leave requests within their own team

### 3. Cross-Department Supervision (Override)
- Admins can assign supervisors from other departments
- Useful for matrix organizations or shared services
- Marked with "Override" flag to distinguish from same-department supervisors
- Example: HR manager supervising multiple departments

### 4. Filtered Leave Management
- Department supervisors see only leave requests from their supervised departments
- Admins and HR users see all leave requests (global view)
- Automatically filters the Leave Management dashboard based on user role

### 5. Permission Model
- **Admin/HR**: Full access to all leave requests and supervisor management
- **Department Supervisors**: Can approve/reject leave for their department(s) only
- **Employees**: Can only view their own leave requests

## Database Schema

### Table: `department_supervisors`
```sql
- id (PK)
- department_id (FK to departments)
- supervisor_user_id (FK to users)
- is_override (BOOLEAN) - TRUE if supervisor is from different department
- assigned_by (FK to users) - Admin who made the assignment
- assigned_at (TIMESTAMP)
- UNIQUE constraint on (department_id, supervisor_user_id)
```

## User Interface

### 1. Departments Index (`/modules/departments/index`)
- Added "Supervisors" column showing count of supervisors
- Clickable link to manage supervisors for each department
- Icon indicates supervisor count visually

### 2. Supervisor Management (`/modules/departments/supervisors`)
- **Left Panel**: Form to assign new supervisors
  - Dropdown grouped by:
    - Same department users (default)
    - Other department users (override)
    - Users without department
  - Auto-checks "Override" checkbox for other-department users
  - Only shows active users not already assigned

- **Right Panel**: List of current supervisors
  - Shows supervisor name, email, role, department
  - "Override" badge for cross-department supervisors
  - Assignment date
  - Remove button with confirmation

### 3. Leave Management Admin (`/modules/leave/admin`)
- Shows banner for department supervisors indicating which departments they supervise
- Automatically filters requests to show only their department(s)
- Admins/HR see all requests with no filtering

### 4. Leave Request View (`/modules/leave/view`)
- Checks if viewer is authorized (admin, HR, or department supervisor)
- Department supervisors can only approve requests from their supervised departments
- Shows/hides approval buttons based on permissions

## Workflow Example

### Scenario: Daniel (Employee) and Wayne (Supervisor) in IT Department

1. **Setup Phase**:
   - Admin navigates to Departments → IT Department → Supervisors
   - Assigns Wayne as supervisor for IT Department
   - Wayne appears in the supervisors list

2. **Leave Request Phase**:
   - Daniel files a leave request through `/modules/leave/create`
   - Request enters "Pending" status

3. **Approval Phase**:
   - Wayne logs in and visits Leave Management
   - Sees banner: "You are supervising: IT Department"
   - Sees only leave requests from IT Department employees (including Daniel)
   - Opens Daniel's request at `/modules/leave/view?id=X`
   - Approves or rejects with optional reason
   - Daniel receives notification of the decision

4. **Admin/HR Oversight**:
   - Admin/HR users can still see ALL leave requests
   - Can override decisions or manage supervisors
   - Full audit trail maintained

## API Changes

### `api_admin_list.php` Updates
- Queries `department_supervisors` to get user's supervised departments
- If user is department supervisor (not admin/HR), adds WHERE filter:
  ```sql
  WHERE e.department_id IN (supervised_departments)
  ```
- Stats (counts by status) also filtered by department
- Admins/HR bypass this filter (see all)

## Helper Functions Added

### `includes/utils.php`
```php
is_department_supervisor($pdo, $userId, $departmentId): bool
// Checks if user is supervisor for specific department

get_supervised_departments($pdo, $userId): array
// Returns array of department IDs supervised by user
```

## Security Considerations

1. **Authorization Checks**:
   - Every approval action verifies supervisor relationship
   - Can't approve requests outside supervised departments
   - CSRF tokens required on all forms

2. **Audit Trail**:
   - All supervisor assignments logged via `audit()`
   - Assignment metadata includes who assigned and when
   - Leave approvals track acted_by user

3. **Role Hierarchy**:
   - Admins can manage all supervisors
   - HR can manage supervisors with `require_role(['admin','hr'])`
   - Department supervisors cannot assign other supervisors

4. **Data Isolation**:
   - SQL queries enforce department boundaries
   - API endpoints validate permissions before returning data
   - No client-side filtering (all filtering server-side)

## Migration Steps

1. **Database Migration**:
   ```bash
   # Run the migration SQL
   psql -h localhost -U your_user -d hrms -f database/migrations/2025-11-10_department_supervisors.sql
   ```

2. **Assign Initial Supervisors**:
   - Navigate to each department
   - Click on "X supervisors" link
   - Assign appropriate users as supervisors

3. **Test Workflow**:
   - Log in as supervisor
   - Verify filtered leave list shows only their department
   - Create test leave request as employee
   - Approve as supervisor
   - Confirm notifications sent

## Future Enhancements (Optional)

1. **Approval Hierarchy**:
   - Multi-level approvals (supervisor → manager → HR)
   - Configurable approval chains

2. **Delegation**:
   - Temporary delegation when supervisor is on leave
   - Backup supervisors

3. **Supervisor Dashboard**:
   - Dedicated dashboard for supervisors
   - Quick stats and pending items
   - Team leave calendar view

4. **Email Notifications**:
   - Email supervisors when new leave request filed
   - Reminder emails for pending approvals

5. **Bulk Actions**:
   - Approve/reject multiple requests at once
   - Export supervisor reports

## Troubleshooting

### Supervisor doesn't see any leave requests
- Verify they are assigned as supervisor in `department_supervisors` table
- Check employee's `department_id` matches supervised department
- Ensure supervisor is not logged in with 'employee' role exclusively

### Can't assign supervisor
- Verify user exists and status is 'active'
- Check for duplicate assignment (unique constraint)
- Ensure admin/HR role for the assigning user

### Approval button not showing
- Verify request status is 'pending'
- Check user is supervisor of employee's department
- Confirm `has_module_access($uid, 'leave', 'write')` returns true

## Files Modified/Created

### Created:
- `database/migrations/2025-11-10_department_supervisors.sql`
- `modules/departments/supervisors.php`
- `handoff/DEPARTMENT_SUPERVISORS_FEATURE.md` (this file)

### Modified:
- `modules/departments/index.php` - Added supervisor count column
- `modules/leave/admin.php` - Added supervisor filtering and banner
- `modules/leave/api_admin_list.php` - Added department filtering logic
- `modules/leave/view.php` - Added supervisor permission checks
- `includes/utils.php` - Added helper functions

## Support
For issues or questions about this feature, check:
1. System logs via `sys_log()` entries
2. Audit trail in `audit_logs` table
3. Database constraints and foreign keys
4. User role and module access permissions
