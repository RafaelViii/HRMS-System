# Position-Based Access Control Implementation Plan
**Date**: November 12, 2025  
**Status**: 🟡 In Progress - Database Layer Complete, Application Layer Pending

## Overview
Complete overhaul of the access control system, replacing role-based permissions with position-based fine-grained permissions. Each position gets granular control over system resources organized by domain.

---

## 📊 Current System Analysis

### Existing Roles (to be deprecated)
- `admin` - Full system access
- `hr` - HR functions
- `employee` - Basic employee self-service
- `accountant` - Financial/payroll
- `manager` - Department management
- `hr_supervisor`, `hr_recruit`, `hr_payroll`, `admin_assistant` - Specialized roles

### Current Tables (to be deprecated)
- `user_access_permissions` - Direct user permission overrides
- `roles_meta` - Role metadata
- `roles_meta_permissions` - Role-based permissions

### Issues with Current System
1. **Inflexible**: Can't easily customize permissions per position
2. **Coarse-grained**: Role-level only, no resource-level control
3. **Maintenance burden**: Adding new roles requires code changes
4. **No audit trail**: Hard to track who changed permissions
5. **Position disconnect**: Users have roles, but employees have positions (two separate concepts)

---

## 🎯 New System Design

### Access Levels (Hierarchical)
```
manage (rank 3)
  └─ Full control: read + write + delete + sensitive operations
     
write (rank 2)
  └─ Create + update + read (no delete)
     
read (rank 1)
  └─ View + list + download only
     
none (rank 0)
  └─ No access
```

### Domains & Resources

| Domain | Resources | Example Pages |
|--------|-----------|---------------|
| **system** | dashboard, system_settings, audit_logs, system_logs, backup_restore, tools_workbench | index.php, modules/admin/config/*, modules/audit/* |
| **hr_core** | employees, departments, positions, branches, recruitment | modules/employees/*, modules/departments/* |
| **payroll** | payroll_runs, payroll_batches, payslips, payroll_config, payroll_complaints, overtime, dtr_uploads | modules/payroll/*, modules/admin/compensation/* |
| **leave** | leave_requests, leave_approval, leave_balances, leave_config | modules/leave/* |
| **attendance** | attendance_records, work_schedules | modules/attendance/* |
| **documents** | memos, documents | modules/memos/*, modules/documents/* |
| **performance** | performance_reviews | modules/performance/* |
| **notifications** | view_notifications, create_notifications | modules/notifications/* |
| **user_management** | user_accounts, self_profile | modules/account/* |
| **reports** | export_data, analytics | **/csv.php, **/pdf.php |

**Total**: 9 domains, 39 resources

---

## 🗄️ Database Schema Changes

### New Tables

#### 1. `position_access_permissions`
```sql
CREATE TABLE position_access_permissions (
    id BIGSERIAL PRIMARY KEY,
    position_id INTEGER NOT NULL,
    domain VARCHAR(100) NOT NULL,
    resource_key VARCHAR(100) NOT NULL,
    access_level VARCHAR(20) NOT NULL CHECK (access_level IN ('none', 'read', 'write', 'manage')),
    allow_override BOOLEAN DEFAULT FALSE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    UNIQUE (position_id, domain, resource_key)
);
```

### Modified Tables

#### 1. `users` - Add system admin flag
```sql
ALTER TABLE users ADD COLUMN is_system_admin BOOLEAN DEFAULT FALSE NOT NULL;
```

### New Database Functions

#### 1. `check_user_access(user_id, domain, resource_key, required_level)`
```sql
-- Fast lookup: checks if user has required access level
-- Returns BOOLEAN
-- System admins automatically return TRUE
```

### New Views

#### 1. `v_user_position_permissions`
```sql
-- Joins users → employees → positions → permissions
-- Shows complete permission matrix per user
```

---

## 📝 Files Created

### ✅ Completed

1. **`includes/permissions_catalog.php`** (NEW)
   - Central registry of all domains and resources
   - Metadata for UI display
   - Helper functions for permission lookups
   - Access level definitions

2. **`database/migrations/2025-11-12_position_based_permissions.sql`** (NEW)
   - Creates new tables
   - Adds system admin flag
   - Creates helper functions and views
   - Marks old tables as deprecated
   - Creates System Administrator position

3. **`database/migrations/2025-11-12_seed_sysadmin_permissions.sql`** (NEW)
   - Seeds full 'manage' access for System Administrator position
   - All 39 resources across 9 domains

---

## 🔄 Migration Strategy

### Phase 1: Database (✅ COMPLETE)
- [x] Create new tables
- [x] Add system admin flag
- [x] Create helper functions
- [x] Create System Administrator position
- [x] Seed System Administrator permissions

### Phase 2: Application Layer (🟡 NEXT)
1. **Update `includes/auth.php`**
   - Rewrite `user_access_level()` to use positions
   - Update `has_module_access()` to check new system
   - Modify `require_module_access()` to use domain.resource pattern
   - Add `get_user_position()` helper
   - Update override system to work with new permissions

2. **Create new helper: `includes/permissions.php`**
   - `get_user_effective_access($userId, $domain, $resourceKey): string`
   - `user_can($domain, $resourceKey, $level = 'read'): bool`
   - `require_access($domain, $resourceKey, $level = 'read'): void`
   - `get_user_all_permissions($userId): array`

### Phase 3: Module Guards (🔴 PENDING)
Replace all `require_role([...])` calls with `require_access()`:

**Example transformations:**
```php
// OLD
require_role(['admin', 'hr']);

// NEW
require_access('hr_core', 'employees', 'read');
```

**Files to update** (estimate: ~100+ occurrences):
- modules/employees/*.php
- modules/departments/*.php
- modules/positions/*.php
- modules/payroll/*.php
- modules/leave/*.php
- modules/attendance/*.php
- modules/memos/*.php
- modules/documents/*.php
- modules/performance/*.php
- modules/admin/**/*.php
- modules/account/*.php
- modules/audit/*.php
- modules/notifications/*.php
- modules/recruitment/*.php

### Phase 4: Management UI (🔴 PENDING)
1. **`modules/positions/permissions.php`** (NEW)
   - Matrix view of domains × resources
   - Set access level per resource
   - Bulk copy from templates
   - Audit log of changes
   
2. **`modules/positions/index.php`** (UPDATE)
   - Add "Permissions" link/tab

3. **`modules/positions/edit.php`** (UPDATE)
   - Add permissions tab alongside department details

### Phase 5: Data Migration (🔴 PENDING)
1. **Identify current admins** → set `is_system_admin` flag
2. **Create common position templates**:
   - HR Manager (full hr_core + leave + attendance)
   - Payroll Officer (payroll domain + read employees)
   - Department Manager (read most, write leave_approval)
   - Employee (self_service only)
3. **Backfill existing employees** → assign positions
4. **Verify no users locked out**

### Phase 6: Testing & Validation (🔴 PENDING)
- [ ] System admin can access everything
- [ ] Position-based users see correct menus
- [ ] Access denied redirects work
- [ ] Override prompts function
- [ ] Self-service resources (leave_requests, self_profile) accessible to all
- [ ] CLI tools respect new permissions
- [ ] Audit logging captures permission changes

### Phase 7: Cleanup (🔴 PENDING)
- [ ] Drop deprecated tables (after backup):
  - `user_access_permissions`
  - `roles_meta`
  - `roles_meta_permissions`
- [ ] Remove role checks from code
- [ ] Update documentation
- [ ] Remove `users.role` column (keep temporarily for reference)

---

## 🚨 Critical Considerations

### 1. Self-Service Resources
These must remain accessible to ALL users:
- `leave.leave_requests` (employees file their own leave)
- `user_management.self_profile` (view/edit own profile)
- `notifications.view_notifications` (see own notifications)

**Implementation**: Check if resource has `self_service: true` flag, bypass position check.

### 2. System Administrator Bypass
```php
// Quick check at top of permission resolution
if ($user['is_system_admin']) {
    return 'manage'; // Full access to everything
}
```

### 3. Backward Compatibility
During migration period:
- Keep old `require_role()` function but add deprecation warning
- Log when old system is used
- Provide migration helper script to find all occurrences

### 4. CLI/Tools Access
Scripts in `tools/*.php` need special handling:
- Check for CLI context
- May need service account with system admin flag
- Or bypass permission check in CLI mode (with audit logging)

### 5. Branch-Level Overrides
Future enhancement: Allow branch-specific permission overrides
- Add `branch_id` column to `position_access_permissions`
- NULL = applies to all branches

---

## 📋 Testing Checklist

### Access Control
- [ ] System admin accesses all pages without errors
- [ ] Non-admin with position sees only permitted resources
- [ ] User without position or employee record is blocked (except self-service)
- [ ] Hierarchical levels work (manage includes write, write includes read)
- [ ] Override prompts appear for insufficient permissions
- [ ] Override with valid admin credentials succeeds

### Data Integrity
- [ ] Cascade deletes work (delete position → remove permissions)
- [ ] Unique constraints prevent duplicate permissions
- [ ] Invalid access levels rejected by CHECK constraint

### Performance
- [ ] Permission lookups are fast (<10ms)
- [ ] Indexes are used (check EXPLAIN output)
- [ ] View `v_user_position_permissions` performs well

### UI/UX
- [ ] Permission matrix loads quickly
- [ ] Changes save with confirmation
- [ ] Audit log shows who changed what
- [ ] Filtered views work (by domain)

---

## 🎓 Training & Documentation

### For Administrators
1. **Position Permission Management Guide**
   - How to assign permissions to positions
   - Understanding access levels
   - Using permission templates
   - Auditing permission changes

2. **Migration from Roles**
   - Mapping old roles to new positions
   - Common position templates
   - Troubleshooting access issues

### For Developers
1. **Permission Check Patterns**
   ```php
   // Page-level guard
   require_access('payroll', 'payroll_runs', 'write');
   
   // Conditional logic
   if (user_can('payroll', 'payroll_runs', 'manage')) {
       // Show release button
   }
   
   // Get all user permissions
   $perms = get_user_all_permissions($userId);
   ```

2. **Adding New Resources**
   - Update `includes/permissions_catalog.php`
   - Add to appropriate domain
   - Document pages affected
   - Run migration to update existing positions

---

## 🔗 Dependencies

### Required Files (Already Exist)
- `includes/db.php` - Database connection
- `includes/session.php` - Session management
- `includes/utils.php` - Utility functions
- `includes/auth.php` - Current auth system (will be refactored)

### New Files to Create
- `includes/permissions_catalog.php` ✅
- `includes/permissions.php` (helper functions)
- `modules/positions/permissions.php` (management UI)
- `tools/verify_permissions.php` (validation script)
- `tools/migrate_roles_to_positions.php` (one-time migration)

---

## 📊 Rollout Plan

### Pre-Deployment
1. Backup database
2. Run migration in staging environment
3. Test all critical paths
4. Document any issues

### Deployment Day
1. Announce maintenance window
2. Run migrations:
   ```bash
   psql -f database/migrations/2025-11-12_position_based_permissions.sql
   psql -f database/migrations/2025-11-12_seed_sysadmin_permissions.sql
   ```
3. Deploy updated code
4. Verify system admin can log in
5. Monitor error logs

### Post-Deployment
1. Create position templates for common roles
2. Assign positions to all employees
3. Train HR staff on permission management
4. Monitor access denied logs
5. Adjust permissions based on feedback

### Week 1-2
- Address any access issues
- Fine-tune permissions
- Gather user feedback

### Month 1
- Remove deprecated tables (after final backup)
- Update all documentation
- Conduct code review to ensure all role checks removed

---

## 🏁 Success Criteria

- [ ] All users can access resources they need
- [ ] No unauthorized access possible
- [ ] Permission changes are auditable
- [ ] System performance is not degraded
- [ ] HR staff can manage permissions without developer help
- [ ] Zero security incidents related to permission bypass

---

## 📞 Support & Contacts

**Technical Lead**: System Architect  
**Database Admin**: (coordinate migration timing)  
**HR Manager**: (validate permission requirements)

---

## Next Immediate Steps

1. ✅ Review this plan with stakeholders
2. 🟡 **CURRENT**: Update `includes/auth.php` with new permission helpers
3. Create `includes/permissions.php`
4. Update one module as proof-of-concept (e.g., `modules/employees`)
5. Build management UI (`modules/positions/permissions.php`)
6. Systematic replacement of all `require_role()` calls
7. Testing phase
8. Production deployment

