# Position Permissions Management UI - User Guide

**Date**: 2025-11-12  
**Status**: ✅ Complete  
**Module**: `modules/positions/permissions.php`

---

## Overview

The Position Permissions Management UI provides a comprehensive interface for administrators to assign fine-grained access controls to each position in the organization. This replaces the old role-based system with a more flexible position-based permission model.

---

## Accessing the UI

### From Position List
1. Navigate to **Positions** module
2. Find the position you want to manage
3. Click **"Permissions"** link in the Actions column

### From Position Edit Page
1. Navigate to **Positions** → **Edit** (any position)
2. Click the **"Permissions"** tab in the navigation bar

**Required Access**: System Administrator or users with 'manage' access to `system.system_settings`

---

## UI Components

### 1. Position Info Card
**Location**: Top of page  
**Displays**:
- Position name and department
- Number of employees in this position
- Active permissions count (e.g., "15 of 39 permissions configured")
- Real-time impact warning

**Copy from Template Feature**:
- Dropdown shows other positions with existing permissions
- Shows permission count for each template (e.g., "HR Manager (28 perms)")
- Confirmation prompt before replacing current permissions
- Useful for setting up similar positions quickly

### 2. Domain-Organized Permission Cards
**Layout**: One card per domain (9 total)

**Domains**:
1. **System Management** - Core system settings and configuration
2. **HR Core Functions** - Employees, departments, positions, recruitment
3. **Payroll Management** - Runs, approvals, releases, components
4. **Leave Management** - Requests, approvals, balances, policies
5. **Attendance Tracking** - Records, shifts, overtime
6. **Documents & Memos** - Document management and memo distribution
7. **Performance Reviews** - Evaluations and performance tracking
8. **Notifications** - View and manage system notifications
9. **Reports & Analytics** - Access various system reports

### 3. Permission Row Components
Each resource has:

**a) Resource Label and Description**
- Bold label (e.g., "Manage Employees")
- Gray description text explaining what this permission controls
- Self-service badge (blue) if resource is automatically available to all users

**b) Access Level Dropdown**
- **None** (gray) - No access
- **Read** (blue) - View-only access
- **Write** (green) - Create and edit capabilities
- **Manage** (red) - Full control including delete and admin functions

**c) Override Checkbox**
- Label: "Override"
- Appears next to access level dropdown
- Only enabled for Write and Manage levels
- When checked: User can authorize others to perform this action
- Useful for approval workflows and sensitive operations

### 4. Sticky Save Bar
**Location**: Bottom of screen (follows scroll)  
**Components**:
- **"Save Permissions"** button (blue) - Commits all changes
- **"Cancel"** link - Returns to position edit without saving
- **Change counter** - "X change(s) pending" - Real-time tracking

---

## Features

### Smart Defaults
- Self-service resources automatically marked (cannot be changed)
- Resources default to 'none' (no access)
- Override checkbox auto-disabled for 'none' and 'read' levels

### Change Tracking
- Tracks modifications in real-time
- Shows pending change count
- Warns before navigating away with unsaved changes
- Highlights save bar when changes detected

### Bulk Operations
**Copy from Template**:
1. Select a position from "Copy from template..." dropdown
2. Click "Copy" button
3. Confirm replacement of current permissions
4. All permissions copied instantly with attribution note

**Use Cases**:
- Setting up new position similar to existing one
- Standardizing permissions across similar roles
- Quick setup for new departments

### Validation
- Cannot remove self-service permissions
- Override only available for write/manage levels
- Empty dropdowns prevented by required attribute
- CSRF protection on all form submissions

### Audit Trail
Every change logs:
- Who made the change (from session)
- What position was modified
- Number of permissions saved
- Timestamp
- Action logged in both `audit_logs` and `action_logs` tables

---

## Workflow Examples

### Example 1: Setting Up HR Manager Position

**Scenario**: Create permissions for a new HR Manager position

**Steps**:
1. Navigate to Positions → HR Manager → Permissions
2. Select "Copy from template..." → "HR Supervisor" (if exists)
3. Or manually set:
   - **HR Core Functions**:
     - Employees: **Manage** ✓ Override
     - Departments: **Write**
     - Positions: **Write**
     - Recruitment: **Manage** ✓ Override
   - **Leave Management**:
     - Leave Requests: **Manage** ✓ Override
     - Leave Approvals: **Manage**
     - Leave Balances: **Write**
   - **Attendance Tracking**:
     - Attendance Records: **Write**
     - Shift Management: **Read**
   - **Documents & Memos**:
     - Documents: **Write**
     - Memos: **Write**
   - **Notifications**:
     - View Notifications: **Read** (self-service already)
4. Click "Save Permissions"
5. Verify "28 active permissions" message

**Result**: HR Manager can now manage employees and recruitment, approve leave, and has override authority for sensitive operations.

### Example 2: Payroll Officer Setup

**Scenario**: Grant payroll access without HR functions

**Steps**:
1. Navigate to Positions → Payroll Officer → Permissions
2. Set:
   - **Payroll Management**:
     - Payroll Runs: **Write**
     - Payroll Approvals: **Read** (can view, not approve)
     - Payroll Data: **Write**
     - Payroll Components: **Write**
   - **Employees** (HR Core):
     - Employees: **Read** (view salary info)
   - Leave everything else as **None**
3. Click "Save Permissions"

**Result**: Payroll Officer can process payroll but cannot approve or release payments. Cannot modify employee records.

### Example 3: Department Manager

**Scenario**: Manager needs to manage their department only

**Steps**:
1. Navigate to Positions → Department Manager → Permissions
2. Set:
   - **HR Core Functions**:
     - Employees: **Write** (can edit their department's employees)
     - Departments: **Read**
   - **Leave Management**:
     - Leave Approvals: **Write** ✓ Override
     - Leave Requests: **Read**
   - **Attendance Tracking**:
     - Attendance Records: **Write**
   - **Performance Reviews**:
     - Performance Reviews: **Write** ✓ Override
3. Click "Save Permissions"

**Result**: Manager can manage their team, approve leave, and conduct performance reviews. Has override capability for critical actions.

---

## Permission Level Guide

### None (Gray)
- **Effect**: Completely blocked from accessing this feature
- **UI Behavior**: Links/buttons hidden, direct URL access shows 403
- **Use When**: Feature not relevant to this position
- **Example**: Payroll Officer doesn't need Performance Review access

### Read (Blue)
- **Effect**: View-only access, no modifications allowed
- **UI Behavior**: See data, export allowed, no create/edit/delete buttons
- **Use When**: Position needs awareness but not control
- **Example**: Employee viewing payroll history, viewing department list

### Write (Green)
- **Effect**: Create, edit, update capabilities
- **UI Behavior**: Full forms, save buttons, but no delete or admin settings
- **Use When**: Position handles day-to-day operations
- **Example**: HR Assistant creating employee records, Leave Officer processing requests
- **Override Option**: Available - can authorize others

### Manage (Red)
- **Effect**: Full control including delete, admin settings, approvals
- **UI Behavior**: All buttons including dangerous actions (delete, override prompts)
- **Use When**: Position is responsible for this entire domain
- **Example**: HR Manager deleting employees, Payroll Admin releasing payments
- **Override Option**: Available - can authorize others

---

## Override Permission Explained

**What It Means**:
- User can provide credentials to authorize **others** to perform this action
- Creates two-factor approval workflow
- Authorizer's user ID logged in audit trail

**When Enabled**:
- Only for **Write** and **Manage** access levels
- Checkbox appears next to access level dropdown

**Use Cases**:
1. **Employee Deletion**: HR Assistant requests delete, HR Manager authorizes
2. **Payroll Release**: Payroll Officer prepares, Accountant authorizes release
3. **Sensitive Edits**: User makes change, their manager authorizes with password

**How It Works** (System Flow):
```
1. User attempts restricted action (e.g., delete employee)
2. System shows override prompt modal
3. User enters authorizer's email and password
4. System calls validate_override_credentials()
5. Checks: Active user + correct password + has 'manage' level + override flag set
6. If valid: Action proceeds, audit log shows both users
7. If invalid: Action blocked, failed attempt logged
```

---

## Self-Service Resources

**What Are They?**:
Resources that all authenticated users should access regardless of position.

**Current Self-Service Resources**:
1. **Leave Requests** (`leave.leave_requests`)
   - All employees can file their own leave
   - Access level: 'write' (automatic)
   - Reason: Core employee right

2. **Self Profile** (`user_management.self_profile`)
   - All users can view/edit their own profile
   - Access level: 'write' (automatic)
   - Reason: Personal data management

3. **View Notifications** (`notifications.view_notifications`)
   - All users can see their notifications
   - Access level: 'read' (automatic)
   - Reason: System communication

**UI Behavior**:
- Blue "Self-Service (All Users)" badge shown
- Access level dropdown **disabled**
- Override checkbox **hidden**
- Cannot be changed in UI
- Always granted by `get_user_effective_access()` function

---

## Technical Details

### Database Operations

**Save Action**:
```sql
-- 1. Delete all existing permissions for position
DELETE FROM position_access_permissions WHERE position_id = :pid;

-- 2. Insert new permissions (skip 'none' levels)
INSERT INTO position_access_permissions 
(position_id, domain, resource_key, access_level, allow_override, notes)
VALUES (:pid, :domain, :resource, :level, :override, :notes);
```

**Copy Template Action**:
```sql
-- Copy all permissions from another position
INSERT INTO position_access_permissions 
(position_id, domain, resource_key, access_level, allow_override, notes)
SELECT :new_pid, domain, resource_key, access_level, allow_override, 
       CONCAT('Copied from ', (SELECT name FROM positions WHERE id = :template_pid))
FROM position_access_permissions
WHERE position_id = :template_pid;
```

### Performance Optimizations
- Static caching in `permissions.php` (per-request)
- Database indexes on `(position_id, domain, resource_key)`
- Lazy loading of template list (only if other positions have permissions)
- Frontend JavaScript tracks changes client-side (no server calls)

### Cache Invalidation
After saving permissions:
```php
clear_permission_cache(); // Clears static cache
```
**Impact**: Next permission check will re-query database  
**Scope**: Current request only (static caching doesn't persist across requests)

### Immediate Effect
⚠️ **Warning**: Changes affect users **immediately**!
- No grace period
- Active sessions use new permissions on next page load
- Cached permission checks expire at request end
- Employees see new access instantly

---

## Error Handling

### Database Errors
**Error Codes**: `DB-PERMS-001` through `DB-PERMS-004`

**Behaviors**:
- `DB-PERMS-001`: Failed to fetch position → Redirect to position list
- `DB-PERMS-002`: Failed to update permissions → Flash error, stay on page
- `DB-PERMS-003`: Failed to copy template → Flash error, stay on page
- `DB-PERMS-004`: Failed to fetch current permissions → Continue with empty state

**User Feedback**:
- All errors show flash messages
- Errors logged to `system_logs` table
- Context preserved for debugging

### Validation Errors
- Missing position ID → Redirect with error
- Position not found → Redirect with error
- Invalid CSRF token → Form rejected silently (no save)
- Template copy on same position → Ignored

---

## Keyboard Shortcuts & UX

### Change Detection
- Real-time counter updates on every dropdown/checkbox change
- Save bar text bolds when changes detected
- Browser warns before navigating away with unsaved changes

### Confirmation Prompts
**Copy Template**:
- "This will replace all current permissions. Continue?"
- Prevents accidental overwrite

**Form Submit**:
- No confirmation (already tracking changes explicitly)
- Success message shows permission count: "Permissions updated successfully (23 active permissions)"

### Responsive Design
- Desktop: Two-column layout (label left, controls right)
- Tablet/Mobile: Stacked layout
- Sticky save bar works on all screen sizes
- Touch-friendly controls (larger tap targets)

---

## Integration Points

### Called Functions (from permissions.php)
- `get_permissions_catalog()` - Loads all 39 resources
- `get_access_levels()` - Loads 4 access level definitions
- `clear_permission_cache()` - Invalidates cache after save

### Required Auth Functions
- `require_access('system', 'system_settings', 'manage')` - Page guard
- `csrf_verify()` - Form protection
- `csrf_token()` - Token generation
- `audit()` - Audit logging
- `action_log()` - Structured action logging

### Navigation Links
- Position list: `modules/positions/index`
- Position edit: `modules/positions/edit?id=X`
- Permissions: `modules/positions/permissions?id=X` ← **This page**

---

## Common Issues & Solutions

### Issue: "Invalid position ID" Error
**Cause**: Missing or zero `?id=` parameter  
**Solution**: Always access via position list or edit page links

### Issue: Permissions Not Saving
**Cause**: CSRF token expired (session timeout)  
**Solution**: Refresh page before saving, check for inactive session

### Issue: Override Checkbox Grayed Out
**Cause**: Access level set to 'none' or 'read'  
**Solution**: Set to 'write' or 'manage' first, then enable override

### Issue: Template List Empty
**Cause**: No other positions have permissions configured yet  
**Solution**: Manually configure first position, then use it as template

### Issue: Changes Not Affecting Users
**Cause**: Cache not cleared (should auto-clear)  
**Solution**: Verify `clear_permission_cache()` called after save

### Issue: Self-Service Resources Disabled
**Behavior**: This is **correct** - cannot be changed  
**Reason**: System-enforced permissions for all users

---

## Testing Checklist

### Before Deployment
- [ ] System admin can access UI
- [ ] Non-admin access blocked (403)
- [ ] Position list shows "Permissions" link
- [ ] Tab navigation works between Details and Permissions
- [ ] All 9 domains render
- [ ] All 39 resources display
- [ ] Access level dropdowns functional
- [ ] Override checkboxes enable/disable correctly
- [ ] Self-service resources marked and disabled
- [ ] Copy template loads other positions
- [ ] Copy template confirmation works
- [ ] Save button commits changes
- [ ] Cancel button returns without saving
- [ ] Change counter updates in real-time
- [ ] Browser warns on unsaved changes
- [ ] Flash messages appear after actions
- [ ] Audit logs capture changes

### After Deployment
- [ ] Test with 2-3 different positions
- [ ] Verify employee count accurate
- [ ] Confirm immediate permission effect
- [ ] Check template copy between positions
- [ ] Validate override authorization flow
- [ ] Review audit trail entries
- [ ] Test responsive layout on mobile
- [ ] Verify CSRF protection active

---

## Future Enhancements

### Planned Features
- **Permission Templates Library**: Pre-built templates for common roles
- **Bulk Edit**: Apply same permission to multiple positions
- **Permission Comparison**: Side-by-side view of two positions
- **History View**: See permission changes over time
- **Permission Groups**: Tag and filter resources by category
- **Export/Import**: JSON-based permission backup/restore
- **Inheritance**: Child positions inherit from parent department position

### Performance Ideas
- **Lazy Load Domains**: Expand/collapse accordion for large permission sets
- **Search/Filter**: Quick find specific resources
- **Favorites**: Pin frequently-used resources to top

---

## Security Considerations

### Access Control
- Only system admins can access this UI
- Uses new position-based permission system
- Falls back to role='admin' during migration
- CSRF protection on all forms

### Audit Trail
Every action logged:
- Timestamp
- User ID (who made change)
- Position ID (what was changed)
- Permission count (how many rules)
- Full permission array (what changed)

### Override Safety
- Override flag requires write/manage level minimum
- Authorizer must have higher/equal access
- Both requester and authorizer logged
- Failed attempts logged for security monitoring

### Database Integrity
- Transactions used for multi-row operations
- Rollback on errors
- Foreign key constraints enforce position existence
- Indexes prevent duplicate permissions

---

## Summary

**Purpose**: Fine-grained position-based access control management  
**Access**: System Administrators only  
**Scope**: 9 domains, 39 resources, 4 access levels  
**Impact**: Immediate (affects all employees in position)  
**Safety**: Full audit trail, override controls, CSRF protection  

**Key Benefits**:
- ✅ Visual permission matrix (easy to understand)
- ✅ Copy from template (fast setup)
- ✅ Real-time change tracking (no surprises)
- ✅ Override authorization (two-factor approvals)
- ✅ Self-service handling (automatic core permissions)
- ✅ Full audit trail (security compliance)
- ✅ Immediate effect (no deploy needed)

**Next Steps After Configuration**:
1. Configure System Administrator position (should be all 'manage')
2. Set up HR Manager template (most HR + leave functions)
3. Create Payroll Officer template (payroll write, employee read)
4. Define Department Manager template (team management focus)
5. Set Basic Employee template (self-service only)
6. Use templates to quickly set up new positions
