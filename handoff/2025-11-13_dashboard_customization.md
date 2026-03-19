# Dashboard Customization by Position & Access Level

**Date**: November 13, 2025  
**Module**: Dashboard (`index.php`)  
**Status**: ✅ Implemented

## Overview

The dashboard now dynamically adapts its content based on each user's position, role, and granular permissions. Instead of showing all widgets to all staff members, the system now only displays relevant metrics, actions, and charts based on what the user can actually access.

---

## Permission-Based Visibility

### Key Permissions Checked

The dashboard queries the following permissions for each user:

| Permission Variable | Domain | Resource | Level | Purpose |
|-------------------|---------|----------|-------|----------|
| `$canManageEmployees` | `hr_core` | `employees` | `manage` | Employee management access |
| `$canManagePayroll` | `payroll` | `payroll_cycles` | `manage` | Payroll cycle management |
| `$canViewPayroll` | `payroll` | `payroll_cycles` | `read` | View payroll data |
| `$canApproveLeaves` | `hr_core` | `leave_approval` | `write` | Approve leave requests |
| `$canViewAttendance` | `hr_core` | `attendance` | `read` | View attendance records |
| `$canManageAttendance` | `hr_core` | `attendance` | `write` | Record/manage attendance |
| `$canViewReports` | `reporting` | `hr_reports` | `read` | Access HR reports |
| `$canManageRecruitment` | `hr_core` | `recruitment` | `write` | Manage recruitment |
| `$canManageSystem` | `system` | `system_settings` | `manage` | System administration |

---

## Dashboard Sections & Customization

### 1. Header Section

**Before**: Generic "People Operations Pulse" for all non-employee users  
**After**: Position-specific title and subtitle

```php
// Dynamic title based on position
$dashboardTitle = 'Dashboard';
if ($userPosition) {
    $dashboardTitle = htmlspecialchars($userPosition) . ' Dashboard';
    $dashboardSubtitle = 'Tools and insights for your role';
} elseif ($role === 'admin') {
    $dashboardTitle = 'People Operations Pulse';
    $dashboardSubtitle = 'Monitor workforce trends, approvals, and payroll releases...';
}
```

**Examples**:
- Administrator → "Administrator Dashboard"
- HR Supervisor → "HR Supervisor Dashboard"
- Finance Officer → "Finance Officer Dashboard"
- Generic admin → "People Operations Pulse"

### 2. Hero Stats (Top-right summary)

**Visibility Rules**:

| Stat | Shown When |
|------|-----------|
| Employees | `$canManageEmployees` OR `$canViewReports` |
| Pending Leaves | `$canApproveLeaves` |
| Present Today | `$canViewAttendance` |
| Payroll Releases | `$canViewPayroll` |

**Fallback**: If no permissions match, shows "Welcome to your dashboard" message

### 3. Metric Cards (Main KPI grid)

**Dynamic Grid**: Cards are built into an array and only rendered if permissions allow

```php
$metricCards = [];

if ($canManageEmployees || $canViewReports) {
    $metricCards[] = [
        'title' => 'Total Employees',
        'value' => $totalEmployees,
        'icon' => '👥',
        'href' => '/modules/employees/index'
    ];
}
// ... more cards based on permissions
```

**Grid Layout**:
- 1 card → Single column (max-w-md)
- 2 cards → 2 columns
- 3 cards → 3 columns
- 4 cards → 4 columns

**Possible Cards**:
1. Total Employees (HR/Reports access)
2. Pending Leaves (Leave approval access)
3. Present Today (Attendance view access)
4. Payroll Released Today (Payroll view access)

### 4. Action Center

**Visibility Rules**:

| Action | Permission Required | Link |
|--------|-------------------|------|
| Add a new employee | `$canManageEmployees` | `/modules/employees/create` |
| Run payroll cycle | `$canManagePayroll` | `/modules/payroll/index` |
| Broadcast announcement | `$canManageSystem` | `/modules/admin/notification_create` |
| Review leave queue | `$canApproveLeaves` | `/modules/leave/admin` |
| Record attendance | `$canManageAttendance` | `/modules/attendance/index` |
| Manage recruitment | `$canManageRecruitment` | `/modules/recruitment/index` |
| View reports | `$canViewReports` | `/modules/admin/index` |

**Fallback**: "No quick actions available for your role" if no permissions match

### 5. Attendance Chart (Last 7 days)

**Visibility**: Only shown if `$canViewAttendance` OR `$canManageAttendance`

**Chart Type**: Line chart with three series:
- Present (green)
- Late (amber)
- Absent (red)

**Data**: Last 7 days of attendance records

### 6. Headcount Trend (12 months)

**Visibility**: Only shown if `$canManageEmployees` OR `$canViewReports`

**Chart Type**: Bar chart  
**Data**: Active employee count per month (rolling 12 months)

### 7. Payroll Totals by Month

**Visibility**: Only shown if `$canManageEmployees` OR `$canViewReports`

**Chart Type**: Line chart  
**Data**: Net pay released per month (rolling 12 months)

**Note**: Currently wrapped in same condition as headcount (intentional pairing for HR/Admin viewers)

### 8. Leave Mix & Leave Types Charts

**Visibility**: Only shown if `$canApproveLeaves` OR `$canViewReports`

**Charts**:
1. **Leave Mix** (Doughnut): Status breakdown (pending, approved, rejected)
2. **Leave Types** (Pie): Distribution by leave type

**Data Period**: Last 90 days

### 9. Recent Notifications

**Visibility**: Always shown (all authenticated users)

**Data**: Last 6 notifications (global or user-specific)

---

## Position-Specific Dashboard Examples

### Example 1: Administrator Position

**Position**: Administrator  
**Typical Permissions**: Full access to all domains

**Dashboard Shows**:
- ✅ "Administrator Dashboard" header
- ✅ All 4 hero stats (employees, leaves, attendance, payroll)
- ✅ All 4 metric cards
- ✅ All 7 action center items
- ✅ Attendance chart (7 days)
- ✅ Headcount trend (12 months)
- ✅ Payroll totals (12 months)
- ✅ Leave mix charts (90 days)
- ✅ Recent notifications

**Total Widgets**: 9 sections

---

### Example 2: HR Supervisor Position

**Position**: HR Supervisor  
**Typical Permissions**:
- ✅ Manage employees
- ✅ Approve leaves
- ✅ View/manage attendance
- ✅ View reports
- ❌ Manage payroll
- ❌ System settings

**Dashboard Shows**:
- ✅ "HR Supervisor Dashboard" header
- ✅ 3 hero stats (employees, leaves, attendance)
- ✅ 3 metric cards (employees, leaves, attendance)
- ✅ 5 action center items (add employee, leave queue, record attendance, recruitment, reports)
- ✅ Attendance chart
- ✅ Headcount trend
- ✅ Payroll totals
- ✅ Leave mix charts
- ✅ Recent notifications

**Total Widgets**: 9 sections (payroll metrics visible for planning, but no payroll actions)

---

### Example 3: Finance Officer Position

**Position**: Finance Officer  
**Typical Permissions**:
- ✅ Manage payroll
- ✅ View payroll
- ✅ Override payroll operations
- ❌ Manage employees
- ❌ Approve leaves
- ❌ View attendance

**Dashboard Shows**:
- ✅ "Finance Officer Dashboard" header
- ✅ 1 hero stat (payroll releases)
- ✅ 1 metric card (payroll released today)
- ✅ 1 action center item (run payroll cycle)
- ❌ No attendance chart
- ❌ No headcount trend
- ❌ No payroll trend
- ❌ No leave charts
- ✅ Recent notifications

**Total Widgets**: 4 sections (focused dashboard for payroll-specific role)

---

### Example 4: Regulatory Compliance Officer Position

**Position**: Regulatory Compliance Officer III  
**Typical Permissions**:
- ✅ View reports
- ❌ All other manage permissions

**Dashboard Shows**:
- ✅ "Regulatory Compliance Officer III Dashboard" header
- ✅ 1 hero stat (employees)
- ✅ 1 metric card (total employees)
- ✅ 1 action center item (view reports)
- ❌ No attendance chart
- ✅ Headcount trend (for compliance monitoring)
- ✅ Payroll totals (for compliance monitoring)
- ✅ Leave mix charts (for compliance monitoring)
- ✅ Recent notifications

**Total Widgets**: 6 sections (read-only analytical view)

---

### Example 5: IT Supervisor Position

**Position**: IT Supervisor  
**Typical Permissions**:
- ✅ System settings (manage)
- ❌ All HR-specific permissions

**Dashboard Shows**:
- ✅ "IT Supervisor Dashboard" header
- ❌ No hero stats
- ❌ No metric cards
- ✅ 1 action center item (broadcast announcement)
- ❌ No charts
- ✅ Recent notifications

**Total Widgets**: 2 sections (minimal dashboard, system-focused)

---

## Benefits

### 1. **Reduced Clutter**
Users only see metrics and actions relevant to their job function. No more confusion about inaccessible features.

### 2. **Enhanced Security**
Dashboard doesn't hint at features the user can't access, reducing information disclosure.

### 3. **Position-Aware UX**
Dashboard header immediately communicates the user's role context.

### 4. **Scalable Permissions**
New permissions can be easily added to show/hide dashboard widgets without modifying core logic.

### 5. **Performance Optimization**
Database queries only run for visible widgets, reducing unnecessary load.

---

## Technical Implementation

### Permission Checks

All permission checks use the centralized permission system:

```php
require_once __DIR__ . '/includes/permissions.php';

$userPositionId = get_user_position_id($user['id']);
$canManageEmployees = user_has_access($user['id'], 'hr_core', 'employees', 'manage');
```

### Data Fetching

Queries are conditional based on permissions:

```php
$totalEmployees = $canManageEmployees || $canViewReports 
    ? scalar('SELECT COUNT(*) FROM employees') 
    : 0;
```

### Rendering

Sections wrapped in permission checks:

```php
<?php if ($canViewAttendance || $canManageAttendance): ?>
<section>
    <!-- Attendance chart -->
</section>
<?php endif; ?>
```

---

## Testing Scenarios

### Test Case 1: Full Admin
1. Login as admin@hrms.local
2. Verify all 9 dashboard sections visible
3. Verify all action center items present

### Test Case 2: Position-Based User
1. Create user linked to "HR Supervisor" position
2. Assign HR permissions to position
3. Login and verify position-specific dashboard title
4. Verify only HR-related widgets shown

### Test Case 3: Minimal Permissions
1. Create user with only "View Reports" permission
2. Verify minimal dashboard (only headcount/payroll trend charts)
3. Verify no action center items except "View reports"

### Test Case 4: No Permissions
1. Create staff user with no special permissions
2. Verify empty hero stats, no metric cards, no charts
3. Verify only "Recent Notifications" section shows

---

## Future Enhancements

### Potential Additions

1. **Widget Customization**
   - Allow users to pin/unpin widgets
   - Save preferences per user

2. **Department-Specific Dashboards**
   - Show department-specific metrics for department heads
   - Filter attendance/leave by managed department

3. **Performance Metrics**
   - Show individual performance KPIs for managers
   - Display team performance trends

4. **Quick Filters**
   - Add date range selectors for charts
   - Department/position filters for headcount

5. **Mobile Optimization**
   - Simplify chart rendering on mobile devices
   - Collapsible sections for small screens

---

## Migration Notes

### Backward Compatibility

- ✅ Employee dashboard (role='employee') unchanged
- ✅ Full admin (role='admin' with all permissions) sees full dashboard
- ✅ No database schema changes required
- ✅ Uses existing permission system

### Rollback Plan

If issues arise:
1. Replace `index.php` with version before dashboard customization
2. No database rollback needed (no schema changes)
3. All permission configurations remain intact

---

## Files Modified

| File | Lines Changed | Purpose |
|------|--------------|---------|
| `index.php` | +50 lines | Added permission checks, position detection, conditional rendering |

---

## Related Documentation

- [Permission System Guide](./2025-11-12_permission_system.md)
- [Position-Based Access Control](./2025-11-12_position_permissions.md)
- [Dashboard Architecture](./2025-11-13_dashboard_architecture.md)

---

**End of Documentation**
