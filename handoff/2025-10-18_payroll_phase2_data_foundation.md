# Payroll Revamp – Phase 2 Data Foundation

_Date: 2025-10-18_

## Scope
Implement database structures and seed scaffolding required by the new payroll workflow, including runs, payslips, approvals, rate configurations, branch submissions, and complaints.

## Completed Tasks
- Authored migration `database/migrations/2025-10-18_payroll_foundation.sql` introducing:
  - Enumerations for payroll run, approval, payslip, branch submission, complaint statuses
  - Reference `branches` table for six-branch workflow support
  - `payroll_runs`, `payslips`, `payslip_items` with associations to employees and runs
  - Approval tables `payroll_approvers`, `payroll_run_approvals`
  - Rate configuration table `payroll_rate_configs`
  - Branch submission tracker `payroll_branch_submissions`
  - Complaint log `payroll_complaints`
- Added helper scaffolding `includes/payroll.php` (rates loader + stub generator) to ensure future code has entry point (created during roadmap setup)
- Created seed script `database/Dummydata/payroll_seed.sql` with sample branches, default approvers (placeholder IDs), and baseline rate configs
- Updated phase tracker to mark Phase 2 in progress

## Outstanding Actions
- Map real user IDs for payroll approvers after migration (update seed script or run manual SQL)
- Capture actual branch address details from client for production seeding
- Provide DBA with migration + seed execution instructions (`tools/migrate.php` then `psql -f database/Dummydata/payroll_seed.sql`)
- Record migration dry-run results once executed in dev environment

## Risks / Notes
- Seed script uses placeholder user IDs (1,2); ensure these map to actual admin accounts to avoid FK issues
- Large migrations should be tested against sanitized production snapshot to verify performance

## Exit Criteria
- Migration file reviewed and approved
- Seed script validated with correct IDs
- DBA acknowledges migration execution plan
- Approval to proceed to Phase 3 (Core Engine & Intake)

---
_Pending stakeholder sign-off to move to Phase 3._