---
mode: agent
---
# HRMS PAYROLL MODULE ENHANCEMENT SPECIFICATION
# -------------------------------------------------
# PURPOSE:
# Extend the existing HRMS system with a complete Payroll Management Flow
# that integrates attendance, approval workflow, payslip generation, and complaint handling.
# This should NOT replace existing modules—only extend or integrate them.

# -------------------------------------------------
# 1. PAYROLL RUN CONFIGURATION
# -------------------------------------------------
# Create a new “Payroll Run” object to encapsulate one full payroll cycle.
# Each payroll run follows this lifecycle:
#   Draft → Submitted → Under Review → For Revision → Approved → Released → Closed
#
# Payroll runs can be triggered in two ways:
# - AUTOMATIC (default): auto-generated every 15th and 30th of the month.
# - MANUAL: can be manually initiated by HR Supervisor if enabled in System Settings.
#
# System setting:
#   Payroll Run Mode: [Automatic | Manual]
#   Default Periods: [15th, 30th]
#   Computation Mode: [Queued (recommended) | Synchronous]
#
# When initiated, the system should:
# - Pull all active employees from selected branches.
# - Allow HR Supervisor to select which branches are included (default: all).
# - Create PayrollBatch objects per branch inside the PayrollRun.

# -------------------------------------------------
# 2. ATTENDANCE SUBMISSION (DTR)
# -------------------------------------------------
# DTR (attendance data) is manually uploaded by HR Supervisor per branch.
# Once uploaded and submitted:
# - The system automatically computes:
#   - Absences (Absent Days × Daily Rate)
#   - Tardiness/Undertime (Minutes × Per-Minute Rate)
# - These are stored and used by the Payroll Computation Engine.
# - Computation should be done asynchronously via background queue to prevent freezing.

# -------------------------------------------------
# 3. PAYROLL COMPUTATION ENGINE
# -------------------------------------------------
# Auto-computation module must be isolated and handle all core calculations in one step.
# This improves auditability and avoids fragmentation of logic.
#
# Computation Steps:
#   Step 1: Determine base rates:
#     Monthly Rate, Bi-Monthly Rate, Daily Rate (Monthly / 22), Hourly Rate (Daily / 8), Per-Minute Rate (Hourly / 60)
#   Step 2: Compute EARNINGS:
#     - Basic Pay
#     - Overtime (1.25x)
#     - Rest Day OT (1.30x)
#     - Regular Holiday (2.00x)
#     - Regular Holiday OT (2.60x)
#     - Special Non-Working Day (1.30x)
#     - SNW OT (1.69x)
#     - Allowances (TA, MA, LA) — default per employee, but editable per run
#     - Custom earnings (bonuses, incentives, adjustments)
#   Step 3: Compute DEDUCTIONS:
#     - Attendance deductions (absences, tardiness, undertime)
#     - SSS (based on 2025 table, bi-monthly split)
#     - PhilHealth (5% rate, bi-monthly split)
#     - Pag-IBIG (2%, capped at ₱100)
#     - Withholding Tax (BIR progressive rates)
#     - Other loans or custom deductions
#   Step 4: Compute NET PAY:
#     Net Pay = Total Earnings - Total Deductions
#
# All formulas must be configurable via a Payroll Formula Configuration Table.
# This table lets HR adjust rates and multipliers without changing backend code.

# -------------------------------------------------
# 4. APPROVAL WORKFLOW
# -------------------------------------------------
# Each payroll run must go through an approval chain.
# Default Chain: Admin → HR Supervisor → HR Payroll
# However, approval chain must be modular and configurable per branch or company.
#
# Each stage can perform these actions:
#   - Approve (with optional remarks)
#   - Reject
#   - For Revision (must include reason)
#   - Approve with Reason (adds to audit log)
#
# Sensitive actions (approve, release, modify post-release) require authorization confirmation
# using the existing credential re-check system.
#
# Workflow Status transitions:
#   Draft → Submitted → Under Review → For Revision → Approved → Released → Closed

# -------------------------------------------------
# 5. PAYROLL RELEASE AND EMPLOYEE VIEW
# -------------------------------------------------
# Once all approvals are given:
# - HR Supervisor can click “Distribute Payroll”.
# - The system generates payslips for each employee and marks them as “Released”.
# - Employees are notified and can view payslips in their HRMS portal.
#
# Payslip should display:
#   - Employee Name, ID, Branch
#   - Pay Period (e.g., Oct 1–15, 2025)
#   - Earnings Breakdown
#   - Deductions Breakdown
#   - Gross Pay, Net Pay
#   - Remarks (e.g., “Approved with reason: OT adjustment”)
#   - Approver Name (no digital signature)

# -------------------------------------------------
# 6. PAYROLL COMPLAINT SYSTEM
# -------------------------------------------------
# Employees can file a payroll complaint directly from their payslip view.
# On clicking “File Complaint”:
#   - Opens a modal to submit: Subject, Description, Attachments (screenshots/comments)
#   - Automatically links the complaint to:
#       - Payroll Run ID
#       - Employee ID
#       - Payslip ID
#
# When submitted:
#   - Creates a Payroll Complaint Ticket.
#   - Notifies HR Supervisor and HR Payroll.
#   - Ticket appears in the Payroll Complaints Window under that payroll run.
#
# Complaint ticket statuses:
#   Open → Under Review → Resolved → Closed

# -------------------------------------------------
# 7. POST-RELEASE MODIFICATIONS AND AUDITING
# -------------------------------------------------
# Even after a run is marked “Finished”, authorized users may modify computations.
# Every change must:
#   - Create a versioned copy of the payslip (store original and modified)
#   - Log audit entry with:
#       who made the change
#       timestamp
#       old value vs new value
#   - Notify employee that their payslip was updated
#
# Keep all versions in the database for compliance and traceability.

# -------------------------------------------------
# 8. SECURITY AND AUTHORIZATION
# -------------------------------------------------
# - Continue using the existing Role-Based Access Control (RBAC) for permissions.
# - Require credential re-check (authorization prompt) only for critical actions:
#   approve, reject, release, or modify post-release.
# - All other actions (view, compute, preview) are governed by RBAC only.
# - Log all authorization attempts in the system security audit log.

# -------------------------------------------------
# 9. DATABASE ENTITIES (SUMMARY)
# -------------------------------------------------
# PayrollRun:
#   id, company_id, period_start, period_end, run_mode, status, settings_snapshot, initiated_by, timestamps
#
# PayrollBatch:
#   id, run_id, branch_id, status, approvers_chain, approvals_log, submitted_by, computation_job_id, remarks
#
# Payslip:
#   id, employee_id, batch_id, period, earnings_json, deductions_json, net_pay, gross_pay, version, prev_version_id, change_reason, timestamps
#
# PayrollComplaint:
#   id, payslip_id, employee_id, subject, description, attachments, status, timestamps, assigned_to

# -------------------------------------------------
# 10. NOTIFICATIONS
# -------------------------------------------------
# - On Payroll Distribution: Notify Employee
# - On Complaint Filed: Notify HR Supervisor + HR Payroll
# - On Complaint Resolved: Notify Employee
# - On Payslip Modification: Notify Employee + Audit Log Entry

# -------------------------------------------------
# 11. PERFORMANCE
# -------------------------------------------------
# - All heavy computations (payroll calculation, attendance parsing) must run in a queued background job.
# - Prevent UI lockups during computation or bulk approval.
# - Show progress indicators or logs per branch batch.

# -------------------------------------------------
# OUTCOME
# -------------------------------------------------
# Once implemented, the enhanced HRMS Payroll Module will:
# - Automatically or manually initiate payroll cycles
# - Compute earnings/deductions accurately and asynchronously
# - Allow structured approval/revision/release workflows
# - Enable employee transparency and complaint handling
# - Maintain full audit and version control for compliance
# - Integrate seamlessly with existing HRMS logic and RBAC structure
