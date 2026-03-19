# Payroll Revamp – Phase 3 Core Engine & Intake

_Date: 2025-10-18_

## Scope
Deliver foundational code for the payroll calculation engine scaffolding and the branch submission intake workflow. The full computation logic will be completed in later phases, but preparers can now create payroll runs and track branch document submissions in-system.

## Completed Tasks
- Enhanced `includes/payroll.php` with helper utilities:
  - `payroll_get_branches`, `payroll_create_run`, `payroll_list_runs`, `payroll_get_run`, `payroll_get_run_submissions`
  - Maintained stub `payroll_generate_payslip` for future calculation logic
- Rebuilt `modules/payroll/index.php` into a payroll run dashboard showing period, status, branch submission progress, and quick links
- Added `modules/payroll/run_create.php` for creating payroll runs (period dates + notes) with CSRF protection and auto-init of branch submissions
- Added `modules/payroll/run_view.php` to manage branch submissions per run, including:
  - Status updates (pending/submitted/accepted/etc.)
  - File uploads for biometric exports, logbooks, and supporting documents (stored under `assets/uploads/payroll_submissions/{run}/{submission}`)
  - Remarks capture and contextual guidance for next steps
- Created upload directory structure automatically when updating submissions

## Outstanding Actions
- Finalize file retention policy (size limits, allowed types) and integrate validation in upload handling
- Determine branch-level access rights (whether branches themselves upload or HR-only) before enabling in production
- Implement detailed calculation engine and payslip generation in Phase 4/5
- Build progress indicators/notifications for overdue branch submissions (planned for later phase)

## Risks / Notes
- Upload handling currently accepts any file extension; need to enforce whitelist once policy confirmed
- Branch list must be populated via seed/migration; if empty, run creation will still work but no submissions will appear
- Large file uploads may require PHP.ini adjustments (upload_max_filesize/post_max_size)

## Exit Criteria
- Payroll admins can create runs and record branch submission status in the system
- Code scaffolding ready for upcoming calculation/approval work
- Stakeholder review of new UI completed with feedback captured for future phases

---
_Ready to proceed to Phase 4 once sign-off received._