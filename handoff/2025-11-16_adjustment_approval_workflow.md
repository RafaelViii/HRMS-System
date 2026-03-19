# Payroll Adjustment Approval Workflow Implementation

**Date:** November 16, 2025  
**Status:** ✅ Complete - Ready for Migration

## Overview

Implemented a comprehensive approval workflow system for payroll adjustments created from complaint resolutions. This ensures that adjustments must be reviewed and approved before they can be applied to payroll runs.

## Key Features

### 1. Two Separate Approval Workflows

The system now has **two distinct approval workflows**:

- **Payroll Run Approvers** - Approve entire payroll runs before release
- **Payroll Adjustment Approvers** - Approve individual adjustments before application to payroll

Each workflow operates independently with its own:
- Approver list
- Configuration page
- Approval logic
- Status tracking

### 2. Database Changes

**Migration File:** `database/migrations/2025-11-16_payroll_adjustment_approvals.sql`

#### New Columns in `payroll_adjustment_queue`:
- `approval_status` VARCHAR(20) DEFAULT 'pending_approval' - Tracks approval state
- `approved_by` INT - User who approved/rejected
- `approved_at` TIMESTAMP - When decision was made
- `rejection_reason` TEXT - Reason for rejection if applicable

#### New Table: `payroll_adjustment_approvers`
- `id` - Primary key
- `user_id` - User authorized to approve (unique)
- `approval_order` - Sequential order for display
- `active` - Whether approver is currently active
- `notes` - Optional notes about approver role
- Timestamps and foreign keys

#### Indexes Added:
- `idx_payadj_approval_status` - Fast approval status lookups
- `idx_payadj_approved_by` - Approver history queries
- `idx_adjustment_approvers_active` - Active approver filtering

### 3. Backend Functions

**New Functions in `includes/payroll.php`:**

1. **`payroll_get_adjustment_approvers($pdo, $activeOnly = true)`**
   - Fetches list of adjustment approvers
   - Can filter by active status
   - Returns user details with names and emails

2. **`payroll_is_adjustment_approver($pdo, $userId)`**
   - Checks if a user is authorized to approve adjustments
   - Returns boolean

3. **`payroll_get_pending_adjustments($pdo, $runId = null)`**
   - Fetches adjustments awaiting approval
   - Can filter by specific payroll run
   - Returns full details including employee, department, complaint info

4. **`payroll_approve_adjustment($pdo, $adjustmentId, $approverUserId)`**
   - Approves an adjustment
   - Validates approver authorization
   - Updates status to 'approved' and queue status to 'pending' for application
   - Logs action in audit trail

5. **`payroll_reject_adjustment($pdo, $adjustmentId, $approverUserId, $reason)`**
   - Rejects an adjustment with reason
   - Validates approver authorization
   - Updates status to 'rejected' and queue status to 'cancelled'
   - Logs action with rejection reason

**Modified Functions:**

6. **`payroll_get_queued_adjustments()`**
   - Now filters by `approval_status = 'approved'`
   - Only approved adjustments are applied to payroll
   - Backward compatible (checks if column exists)

### 4. Admin Interface

**New Page:** `modules/admin/approval-workflow.php`

Features:
- **Dual Management Interface**
  - Payroll Run Approvers section (blue theme)
  - Payroll Adjustment Approvers section (green theme)
  - Clear visual separation

- **Approver Management**
  - Add/remove approvers dynamically
  - Set approval order
  - Toggle active/inactive status
  - Add optional notes for each approver
  
- **User Selection**
  - Dropdown of eligible users (admin, hr, hr_manager, accountant, hr_payroll)
  - Shows username and full name
  - Prevents duplicate assignments

- **Form Handling**
  - Separate save actions for each workflow
  - JSON serialization for batch updates
  - Client-side validation before submission
  - Success/error flash messages

### 5. Run View Enhancements

**Updated:** `modules/payroll/run_view.php`

#### New Section: "Pending Payroll Adjustments"
Located between approvals and complaints sections, displays:

**For Each Pending Adjustment:**
- Visual card with color-coded type (emerald for earning, rose for deduction)
- Amount in large, prominent display
- Employee details (name, code, department)
- Originating complaint reference
- Adjustment notes if provided
- Creation timestamp and creator
- Approve/Reject action buttons (if authorized)

**Approver Badge:**
- Shows "You are an approver" badge for authorized users
- Empty state message when no pending adjustments

**Action Buttons:**
- **Approve** - Green button, immediately approves
- **Reject** - Red button, opens modal for rejection reason

#### New POST Handlers:

1. **`approve_adjustment`**
   - Validates adjustment ID
   - Checks approver authorization (or requires override)
   - Calls `payroll_approve_adjustment()`
   - Shows success/error message
   - Redirects back to run view

2. **`reject_adjustment`**
   - Validates adjustment ID and rejection reason
   - Checks approver authorization (or requires override)
   - Calls `payroll_reject_adjustment()`
   - Shows success/error message
   - Redirects back to run view

#### New Modal: "Reject Adjustment"
- Displays adjustment details
- Requires rejection reason (textarea)
- Validates before submission
- Records reason in database

### 6. Workflow Integration

#### Adjustment Creation (Complaint Resolution):
1. Admin resolves complaint in `run_view.php`
2. Checks "Queue payroll adjustment" checkbox
3. Fills adjustment details (type, amount, label, code)
4. Submits complaint update
5. `payroll_schedule_adjustment()` creates queue entry
6. **New:** `approval_status` defaults to 'pending_approval'
7. **New:** Adjustment appears in "Pending Adjustments" section

#### Approval Process:
1. Authorized approver views pending adjustments
2. Reviews details (amount, employee, complaint link, notes)
3. **Option A - Approve:**
   - Clicks "Approve" button
   - Status changes to 'approved'
   - Queue status changes to 'pending' (ready for payroll)
   - Action logged in audit trail
4. **Option B - Reject:**
   - Clicks "Reject" button
   - Modal opens requesting reason
   - Enters rejection reason (required)
   - Status changes to 'rejected'
   - Queue status changes to 'cancelled' (won't apply)
   - Reason and action logged in audit trail

#### Payroll Generation:
1. Payroll run is generated
2. `payroll_get_queued_adjustments()` called for each employee
3. **New:** Function filters by `approval_status = 'approved'`
4. Only approved adjustments are included
5. Adjustments applied to earnings/deductions
6. Marked as 'applied' after successful payslip creation

## Navigation & Access

### Admin Access Points:
1. **HR Admin Dashboard** (`modules/admin/index.php`)
   - "Approval Workflow" card links to management page
   - Listed in quick action buttons

2. **Payroll Run View** (`modules/payroll/run_view?id=X`)
   - "Pending Payroll Adjustments" section
   - Approve/Reject buttons for authorized users

### Permission Requirements:
- **View adjustments:** Anyone with payroll run access
- **Approve/Reject:** Must be in `payroll_adjustment_approvers` table
- **Override:** System admins can approve via authorization dialog

## Security & Audit

### Authorization Checks:
- `payroll_is_adjustment_approver()` validates approver status
- Non-approvers must use authorization override dialog
- All actions logged via `action_log()`

### Audit Trail:
- Adjustment creation logged with creator
- Approval logged with approver user ID and timestamp
- Rejection logged with approver, timestamp, and reason
- All changes tracked in `updated_at` column

## Migration Instructions

### 1. Run Database Migration
```bash
# Via web interface
Visit: https://your-domain/tools/migrate.php

# Or via psql CLI
psql -h cd7f19r8oktbkp.cluster-czrs8kj4isg7.us-east-1.rds.amazonaws.com \
     -U uen9p9diua190r \
     -d ddh3o0bnf6d62e \
     -f database/migrations/2025-11-16_payroll_adjustment_approvals.sql
```

### 2. Configure Approvers
1. Log in as admin
2. Navigate to **HR Admin > Approval Workflow**
3. Scroll to "Payroll Adjustment Approvers" section
4. Click "Add Approver"
5. Select user from dropdown
6. Set active status
7. Add optional notes
8. Click "Save Adjustment Approvers"

### 3. Test Workflow
1. Create a test complaint on a payroll run
2. Resolve complaint with adjustment
3. Verify adjustment appears in "Pending Adjustments"
4. Log in as approver
5. Test approve action
6. Verify adjustment status changes to 'approved'
7. Generate payroll and verify adjustment is applied

## Backward Compatibility

### Existing Adjustments:
- Migration updates existing adjustments to `approval_status = 'pending_approval'`
- Applies to adjustments with `status IN ('queued', 'pending')`

### Column Existence Check:
- `payroll_get_queued_adjustments()` checks if `approval_status` column exists
- Falls back to old behavior if column not present
- Allows gradual rollout without breaking changes

## Files Modified

### Database:
- ✅ `database/migrations/2025-11-16_payroll_adjustment_approvals.sql` (NEW)

### Backend:
- ✅ `includes/payroll.php` (MODIFIED)
  - Added 5 new functions
  - Modified `payroll_get_queued_adjustments()` to check approvals

### Frontend:
- ✅ `modules/admin/approval-workflow.php` (NEW)
- ✅ `modules/payroll/run_view.php` (MODIFIED)
  - Added pending adjustments section
  - Added approve/reject POST handlers
  - Added rejection modal
  - Added JavaScript helper functions

## Testing Checklist

- [ ] Migration runs without errors
- [ ] `payroll_adjustment_queue` table has new columns
- [ ] `payroll_adjustment_approvers` table created successfully
- [ ] Indexes created on approval columns
- [ ] Admin can access approval workflow page
- [ ] Can add/edit/remove adjustment approvers
- [ ] Pending adjustments display in run view
- [ ] Approve button works for authorized users
- [ ] Reject modal opens and submits correctly
- [ ] Rejection reason is required
- [ ] Approved adjustments show in next payroll generation
- [ ] Rejected adjustments do not apply to payroll
- [ ] Audit logs record approval/rejection actions
- [ ] Non-approvers see authorization dialog

## Future Enhancements

### Possible Additions:
1. **Sequential Approval** - Multiple approval levels with routing
2. **Approval Notifications** - Email/in-app alerts to approvers
3. **Amount Thresholds** - Auto-approve below certain amount
4. **Department Routing** - Route to specific approvers by department
5. **Approval Dashboard** - Dedicated page showing all pending approvals
6. **Bulk Actions** - Approve/reject multiple adjustments at once
7. **History View** - See all past approvals/rejections with reasons

## Notes

- **Two Workflows:** Payroll Run vs Adjustment approvals are completely separate
- **Default Status:** All new adjustments require approval by default
- **Backward Compatible:** Checks for column existence before filtering
- **Authorization Override:** Admins can always approve via authorization dialog
- **Rejection Reason:** Always required to maintain audit trail clarity
- **Status Flow:** pending_approval → approved/rejected → pending/cancelled → applied/skipped

## Support

For questions or issues, contact the development team or refer to:
- `handoff/HANDOFF.md` - General system documentation
- `database/schema_postgre.sql` - Full database schema
- `.github/instructions/Copilot-Instructions.instructions.md` - Development guidelines
