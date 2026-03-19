# 🏗️ Employee Edit Module - Architecture Diagram

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Browser (User Interface)                      │
│  ┌──────────┬──────────────┬──────────────┬──────────────┐         │
│  │ Personal │ Compensation │    Leave     │   Overtime   │         │
│  │   Tab    │     Tab      │     Tab      │     Tab      │         │
│  └─────┬────┴──────┬───────┴──────┬───────┴──────┬───────┘         │
│        │           │              │              │                  │
│        └───────────┴──────────────┴──────────────┘                  │
│                          │                                           │
│                    [Tab Switching JS]                                │
│                          │                                           │
└──────────────────────────┼───────────────────────────────────────────┘
                           │ HTTP POST/GET
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   modules/employees/edit.php                         │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Authentication & Authorization Layer                        │   │
│  │  • require_login()                                           │   │
│  │  • require_module_access('employees', 'write')               │   │
│  │  • csrf_verify()                                             │   │
│  └────────────────────┬─────────────────────────────────────────┘   │
│                       │                                              │
│  ┌────────────────────▼─────────────────────────────────────────┐   │
│  │  POST Request Router                                          │   │
│  │  • form=employee_details → Update personal info              │   │
│  │  • form=employee_compensation → Save/clear compensation      │   │
│  │  • form=employee_leave → Save leave overrides                │   │
│  │  • overtime_action → Approve/reject overtime                 │   │
│  │  • delete_employee → Delete with authorization               │   │
│  │  • unbind_user → Remove account binding                      │   │
│  └────────────────────┬─────────────────────────────────────────┘   │
│                       │                                              │
│  ┌────────────────────▼─────────────────────────────────────────┐   │
│  │  Tab Includes                                                 │   │
│  │  <?php include 'edit_tabs/personal.php'; ?>                  │   │
│  │  <?php include 'edit_tabs/compensation.php'; ?>              │   │
│  │  <?php include 'edit_tabs/leave.php'; ?>                     │   │
│  │  <?php include 'edit_tabs/overtime.php'; ?>                  │   │
│  └───────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                        Tab Components                                │
│                                                                      │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐    │
│  │  personal.php   │  │ compensation.php│  │    leave.php    │    │
│  │                 │  │                 │  │                 │    │
│  │ • Employee code │  │ • Default view  │  │ • Balance cards │    │
│  │ • Name, email   │  │ • Override form │  │ • Override form │    │
│  │ • Department    │  │ • Allowances    │  │ • Save/clear    │    │
│  │ • Position      │  │ • Deductions    │  │                 │    │
│  │ • Salary        │  │ • Tax override  │  │                 │    │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘    │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  overtime.php                                                 │  │
│  │  • Request table with filtering                               │  │
│  │  • Approve/Reject buttons                                     │  │
│  │  • Rejection reason modal                                     │  │
│  │  • Summary statistics                                         │  │
│  └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     Business Logic Layer                             │
│                      (includes/payroll.php)                          │
│                                                                      │
│  Compensation Functions:                                             │
│  • payroll_get_compensation_defaults()                               │
│  • payroll_get_employee_compensation()                               │
│  • payroll_save_employee_compensation()                              │
│  • payroll_delete_employee_compensation_override()                   │
│                                                                      │
│  Leave Functions:                                                    │
│  • leave_collect_entitlement_layers()                                │
│  • leave_get_known_types()                                           │
│  • leave_label_for_type()                                            │
│                                                                      │
│  Branch/Dept Functions:                                              │
│  • branches_fetch_all()                                              │
└─────────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      Database Layer (PostgreSQL)                     │
│                                                                      │
│  ┌──────────────┐  ┌──────────────────────────┐  ┌──────────────┐ │
│  │  employees   │  │ employee_compensation_   │  │    leave_    │ │
│  │              │  │      overrides           │  │ entitlements │ │
│  │ • id (PK)    │  │ • employee_id (PK, FK)   │  │ • scope_type │ │
│  │ • emp_code   │  │ • allowances (JSONB)     │  │ • scope_id   │ │
│  │ • first_name │  │ • deductions (JSONB)     │  │ • leave_type │ │
│  │ • last_name  │  │ • tax_percentage         │  │ • days       │ │
│  │ • email      │  │ • notes                  │  └──────────────┘ │
│  │ • salary     │  │ • updated_by (FK)        │                   │
│  │ • dept (FK)  │  │ • updated_at             │                   │
│  │ • branch(FK) │  └──────────────────────────┘                   │
│  └──────────────┘                                                   │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────┐    │
│  │  overtime_requests (NEW)                                    │    │
│  │  • id (PK)                                                  │    │
│  │  • employee_id (FK → employees)                             │    │
│  │  • overtime_date                                            │    │
│  │  • hours                                                    │    │
│  │  • reason                                                   │    │
│  │  • status (pending/approved/rejected/paid)                  │    │
│  │  • approved_by (FK → users)                                 │    │
│  │  • approved_at                                              │    │
│  │  • rejection_reason                                         │    │
│  │  • included_in_payroll_run_id (FK)                          │    │
│  └────────────────────────────────────────────────────────────┘    │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │ departments  │  │  positions   │  │   branches   │             │
│  │ • id (PK)    │  │ • id (PK)    │  │ • id (PK)    │             │
│  │ • name       │  │ • name       │  │ • code       │             │
│  └──────────────┘  └──────────────┘  │ • name       │             │
│                                       └──────────────┘             │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  payroll_compensation_defaults                                │  │
│  │  • id (PK, always 1)                                          │  │
│  │  • allowances (JSONB)                                         │  │
│  │  • deductions (JSONB)                                         │  │
│  │  • tax_percentage                                             │  │
│  │  • notes                                                      │  │
│  └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Data Flow Diagrams

### 1. Personal Information Update Flow

```
User fills form
     ↓
Clicks "Save Changes"
     ↓
JavaScript validates
     ↓
POST to edit.php
     ↓
CSRF verification
     ↓
Authorization check
     ↓
UPDATE employees SET ... WHERE id = ?
     ↓
audit('update_employee')
     ↓
flash_success()
     ↓
Redirect to view page
```

### 2. Compensation Override Save Flow

```
User adds allowance/deduction rows
     ↓
Fills amounts and labels
     ↓
Sets tax override (optional)
     ↓
Clicks "Save Overrides"
     ↓
POST with form=employee_compensation
     ↓
Parse allowances[] and deductions[]
     ↓
Validate amounts (numeric, > 0)
     ↓
payroll_save_employee_compensation()
     ├─ Normalize items
     ├─ INSERT INTO employee_compensation_overrides
     │  ON CONFLICT DO UPDATE
     ├─ action_log()
     └─ audit()
     ↓
flash_success()
     ↓
Redirect with #compensation hash
```

### 3. Overtime Approval Flow

```
HR views overtime tab
     ↓
Sees pending requests
     ↓
Clicks "Approve" button
     ↓
Confirmation dialog
     ↓
POST with overtime_action=approve
     ↓
Verify request belongs to employee
     ↓
Verify status = 'pending'
     ↓
BEGIN TRANSACTION
     ↓
UPDATE overtime_requests SET
  status = 'approved',
  approved_by = current_user_id,
  approved_at = CURRENT_TIMESTAMP
WHERE id = ?
     ↓
COMMIT
     ↓
action_log('overtime_approved')
     ↓
audit('overtime_approved')
     ↓
flash_success()
     ↓
Redirect with #overtime hash
```

### 4. Leave Override Save Flow

```
User overrides leave days
     ↓
Leaves some fields blank (inherit)
     ↓
Clicks "Save Leave Overrides"
     ↓
POST with form=employee_leave
     ↓
Parse leave_days[] array
     ↓
For each leave type:
  if blank → null (delete override)
  if value → numeric validation
     ↓
BEGIN TRANSACTION
     ↓
DELETE FROM leave_entitlements
WHERE scope_type='employee' AND scope_id=? AND leave_type IN (nulls)
     ↓
INSERT INTO leave_entitlements ON CONFLICT DO UPDATE
(for non-null values)
     ↓
COMMIT
     ↓
action_log()
     ↓
audit()
     ↓
flash_success()
     ↓
Redirect with #leave hash
```

---

## Component Interaction Map

```
┌───────────────────────────────────────────────────────────────┐
│                         edit.php                               │
│                      (Main Controller)                         │
│                                                                │
│  Responsibilities:                                             │
│  • Route POST requests                                         │
│  • Fetch employee data                                         │
│  • Fetch related data (depts, positions, branches)             │
│  • Prepare compensation data                                   │
│  • Prepare leave data                                          │
│  • Fetch overtime requests                                     │
│  • Include tab components                                      │
│  • Handle tab state                                            │
└──────────┬───────────────────────────────────────┬─────────────┘
           │                                       │
           │ includes                              │ includes
           ▼                                       ▼
┌─────────────────────┐              ┌─────────────────────────┐
│  edit_tabs/         │              │  includes/              │
│  personal.php       │              │  payroll.php            │
│                     │              │  utils.php              │
│  Renders:           │              │  auth.php               │
│  • Form fields      │◄─────uses────┤  db.php                 │
│  • Input validation │              │                         │
│  • Save button      │              │  Functions:             │
└─────────────────────┘              │  • CSRF                 │
                                     │  • Flash messages       │
┌─────────────────────┐              │  • Audit logging        │
│  edit_tabs/         │              │  • Authorization        │
│  compensation.php   │              │  • DB connection        │
│                     │              │  • Payroll helpers      │
│  Renders:           │              │  • Leave helpers        │
│  • Default view     │              └─────────────────────────┘
│  • Override form    │
│  • Dynamic rows     │
│  • JavaScript       │
└─────────────────────┘

┌─────────────────────┐
│  edit_tabs/         │
│  leave.php          │
│                     │
│  Renders:           │
│  • Balance cards    │
│  • Override form    │
│  • Clear button     │
│  • JavaScript       │
└─────────────────────┘

┌─────────────────────┐
│  edit_tabs/         │
│  overtime.php       │
│                     │
│  Renders:           │
│  • Request table    │
│  • Filter tabs      │
│  • Action buttons   │
│  • Modal dialog     │
│  • Statistics       │
│  • JavaScript       │
└─────────────────────┘
```

---

## State Management

### URL Hash State
```
# Default state (Personal tab)
/modules/employees/edit?id=123

# Compensation tab
/modules/employees/edit?id=123#compensation

# Leave tab
/modules/employees/edit?id=123#leave

# Overtime tab
/modules/employees/edit?id=123#overtime
```

### Tab State Flow
```
Page Load
    ↓
Read window.location.hash
    ↓
If hash exists → Activate corresponding tab
    ↓
If no hash → Activate 'personal' tab
    ↓
User clicks tab button
    ↓
Update active class
    ↓
Show corresponding content
    ↓
Update window.location.hash
    ↓
Browser back/forward
    ↓
hashchange event
    ↓
Re-activate tab based on hash
```

---

## Security Layers

```
┌─────────────────────────────────────────┐
│         Layer 1: Authentication          │
│         require_login()                  │
│         • Session validation             │
│         • User must be logged in         │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 2: Authorization           │
│         require_module_access()          │
│         • Check 'employees' module       │
│         • Require 'write' or 'admin'     │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 3: CSRF Protection         │
│         csrf_verify()                    │
│         • Token in form                  │
│         • Token in session               │
│         • Match required                 │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 4: Input Validation        │
│         • Type checking                  │
│         • Range validation               │
│         • Format validation              │
│         • Sanitization                   │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 5: SQL Injection           │
│         • Prepared statements            │
│         • Parameter binding              │
│         • No raw SQL concat              │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 6: XSS Prevention          │
│         htmlspecialchars()               │
│         • Output escaping                │
│         • Context-aware encoding         │
└────────────────┬────────────────────────┘
                 ↓
┌─────────────────────────────────────────┐
│         Layer 7: Audit Trail             │
│         audit() / action_log()           │
│         • Who did what                   │
│         • When it happened               │
│         • What changed                   │
└─────────────────────────────────────────┘
```

---

## Performance Optimization

### Database Queries
```
# Efficient joins
SELECT e.*, u.status AS account_status
FROM employees e
LEFT JOIN users u ON u.id = e.user_id
WHERE e.id = ?

# Single query for related data
SELECT id, name FROM departments ORDER BY name
SELECT id, name FROM positions ORDER BY name

# Indexed lookups
- employee_id indexed in overtime_requests
- status indexed for filtering
- date indexed for range queries
```

### Client-Side Optimization
```
• Tab content lazy-loads (only active tab visible)
• JavaScript filtering (no page reload)
• CSS animations (GPU-accelerated)
• Minimal JavaScript libraries (vanilla JS)
• CDN for Tailwind CSS
```

---

**Architecture Version**: 1.0  
**Last Updated**: November 7, 2025  
**Status**: Production-Ready
