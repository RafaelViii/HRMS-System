# Payroll Module Implementation Plan (Draft)

_Date: 2025-10-18_

## 1. Business & Regulatory Requirements

- **Payroll Frequency**: Bi-monthly (15th / end-of-month) with occasional special runs. Confirm holiday adjustments and cutoff policies.
- **Client Cutoff Windows**: 8th–20th (payout on 30th) and 21st–5th (payout on 15th) with HR-run reminders to branches for submissions.
- **Scope of Earnings**:
  - Basic pay (pro-rated for period)
  - Overtime: regular OT, rest day OT, holiday OT (use attendance/overtime source)
  - Holiday pay: regular, special non-working, double
  - Allowances: fixed (monthly) and ad hoc (per period); confirm taxable vs non-taxable handling
  - Other earnings: bonuses/adjustments; clarify approvals and taxable treatment
- **Deductions**:
  - Statutory: SSS, PhilHealth, Pag-IBIG (monthly ceilings, employer vs employee shares); confirm schedule long-term (January vs July adjustments)
  - Withholding tax: BIR TRAIN tables; derive from taxable income after statutory contributions
  - Attendance-based: absences, undertime, tardiness (per minute rate, rest day logic)
  - Optional deductions: loans, advances, uniform, co-op contributions (need data source?)
  - Miscellaneous: late filings, penalties
- **Compliance & Reporting**:
  - Payslip detail accessible to employee portal (PDF/HTML)
  - Summary exports: SSS R3, PhilHealth RF, Pag-IBIG MCRF, BIR Alphalist (future)
  - Audit trail: who generated, overrides, adjustments
- **Workflow & Approvals**:
  - Payroll runs must pass through an HR payroll manager (or delegates) before release
  - Support multi-step approvals (e.g., preparer → reviewer → final approver) with timestamps and remarks
  - Admin-manageable list of approvers (add/remove users, define sequence/threshold)
  - Approval actions require credential re-entry (password confirmation or override token) to guard against hijacked sessions
- **User Experience**:
  - Interfaces (payroll dashboard, generation wizard, approval queue) must remain intuitive and mobile-resilient
  - Provide contextual tooltips, inline validation, and clear status indicators for payroll lifecycle stages
  - Ensure preparers/approvers can quickly drill into payslip breakdowns without leaving primary screens
- **Rate Configuration**:
  - System ships with default statutory and company rate tables; admins may override values per effective date via configuration screens
  - Display clear differentiation between default rates and manually overridden entries, with audit trail
- **Branch Submission Workflow**:
  - Six branches provide biometric exports, manual logbooks (for emergencies/offline capture), and supporting documents (OT forms, leave forms, medical certificates) ahead of each cutoff.
  - HR consolidates submissions, cross-checks biometrics vs logbooks, and stores tagged digital copies (physical archiving handled externally).
- **Allowance Rules**:
  - Hazard pay (PHP 3,000/month for clinical staff, prorated by actual days worked)
  - Socio-economic allowance (PHP 1,500/month, prorated if staff joins mid-period)
  - Future-proof configuration for additional company allowances.
- **Complaint Handling**:
  - Employees file payroll complaint form via HR within 48 hours of payout; HR validates records and posts adjustments on next cycle.
- **Other Pay Types**:
  - 13th-month pay processed in December based on total basic pay ÷ 12.
  - Final pay released only after clearance completion; may require off-cycle processing.

_Questions to resolve_
1. Exact statutory formula versions (2024/2025 tables) and rounding (nearest peso/centavo?).
2. Attendance classification source: `attendance` table only or additional triggers (leave approvals, holidays table)?
3. Approval workflow details: number of approval tiers, fallback approvers, SLA for reviews?
4. Storage for manual adjustments and notes.
5. Should approver list vary by payroll period, business unit, or be global?
6. Confirm complaint submission SLA (e.g., 48 hours) and whether adjustments can be mid-cycle.

## 2. Current Data & Gaps

| Domain | Current Table(s) | Observations | Gaps |
| --- | --- | --- | --- |
| Employees | `employees`, `users` | salary stored in `employees.salary` (numeric) | need allowance definitions, pay type (monthly/hourly) |
| Attendance | `attendance`, `leave_requests` | has status (present/absent/late/on-leave) and overtime minutes | need per-day overtime rate info (regular/rest day/holiday) |
| Payroll | `payroll`, `payroll_periods` | high-level totals, no per-employee breakdown | new `payslips` entity + breakdown lines |
| Holidays | none |  | create `holidays` table or config file |
| Loans/Other | none |  | add optional table or rely on manual adjustments |
| Rate Config | none | defaults likely hard-coded | add admin-manageable rate configuration tables |
| Branch Submissions | none | files handled manually | add upload + tracking module per branch/cutoff |
| Complaints | none | ad hoc tracking | add complaints/resolution log tied to payroll runs |

## 3. Proposed Database Changes

1. **New Tables**
   - `payslips`
     - `id`, `employee_id`, `period_start`, `period_end`, `basic_pay`, `total_earnings`, `total_deductions`, `net_pay`, `breakdown` (JSON), `status`, `generated_by`, timestamps
   - `payslip_items` (optional but recommended for reporting)
     - `id`, `payslip_id`, `type` (`earning`/`deduction`), `code`, `label`, `amount`, `meta` (JSON)
   - `payroll_runs`
     - `id`, `period_start`, `period_end`, `status` (`draft`, `for_review`, `approved`, `released`, `rejected`), `notes`, `generated_by`, `released_at`, timestamps
   - `payroll_run_approvals`
     - `id`, `payroll_run_id`, `approver_id`, `step_order`, `status` (`pending`, `approved`, `rejected`), `remarks`, `acted_at`
   - `payroll_approvers`
     - `id`, `user_id`, `step_order`, `active`, `applies_to` (e.g., global/company/department), timestamps
   - `payroll_rate_configs`
     - `id`, `category` (`statutory`, `allowance`, `custom_rate`), `code`, `label`, `default_value`, `override_value`, `effective_start`, `effective_end`, `updated_by`, timestamps
  - `payroll_branch_submissions`
    - `id`, `payroll_run_id`, `branch_id`, `submitted_at`, `biometric_path`, `logbook_path`, `supporting_docs_path`, `status`, `remarks`
  - `payroll_complaints`
    - `id`, `payroll_run_id`, `employee_id`, `submitted_at`, `issue_type`, `description`, `status`, `resolution_notes`, `resolved_at`

2. **Supporting Tables**
   - `holidays` (date, type, multiplier)
   - `allowances` / `employee_allowances` (if allowances vary)
   - `statutory_tables` (store SSS, PhilHealth, Pag-IBIG brackets for versioning)
  - `branches` (if not already modeled) for submission tracking

3. **Migrations**
   - Use `tools/migrate.php` workflow; supply new SQL scripts under `database/migrations/`.

## 4. Module Architecture (Plain PHP)

- **Location**: `modules/payroll/`
- **Components**:
  - Controller scripts: `index.php` (list payroll runs/payslips), `generate.php` (form + POST), `view.php` (payslip detail), `export_pdf.php`, `export_csv.php`
  - Helper functions (add to `includes/payroll.php` or extend `utils.php`): rate calculations, statutory tables loader, JSON breakdown builder, approval routing utilities
  - Access control: require `payroll` module access; use existing override system for adjustments/deletions; enforce reviewer-only actions on approval screens
  - Approval UI: preparer dashboard to submit runs for review, reviewer workspace to approve/reject with notes, audit history per run; enforce credential re-entry modal before finalizing actions
  - Configuration UI (likely under `modules/admin/`): manage approver list and ordering, toggle active approvers, assign backups
  - Rate management UI: list default statutory/company rates, allow authorized admins to set overrides with effective dates and rollback history
  - Branch submissions UI: allow each branch or HR to upload biometric files, logbooks, and supporting documents; show status dashboard per cutoff.
  - Complaint management UI: capture, track, and resolve payroll complaints post-release; ensure adjustments flagged for next cycle.
  - UI: reuse Tailwind/Turbo SPA approach; integrate with navigation, notifications, audit logging; craft user-friendly layouts with step indicators, summary cards, and responsive tables

## 5. Calculation Engine Design

- **Inputs**: Employee (salary, allowances), attendance logs within period, leave data, configured rules
- **Steps**:
  1. Derive rates (daily/hourly/minute) from monthly salary (consistent with policy; standard assumes 261 workdays/year)
  2. Compute base pay for days worked (bi-monthly base minus absences)
  3. Calculate overtime by category (OT type multipliers; map from attendance) 
  4. Apply holiday pay multipliers using `holidays` table and attendance
  5. Aggregate allowances (prorated if needed)
  6. Compute statutory deductions using latest tables (stub functions now, upgrade later)
  7. Calculate attendance penalties (absences, tardiness, undertime) using minute rates
  8. Determine taxable income, withholding tax from BIR table
   9. Save `payslip` record with `breakdown` JSON summarizing each line
   10. Append `payslip_items` lines for granular reporting (if table created)
   11. Attach payslip set to payroll run; update run status progression (`draft` → `for_review` → `approved` → `released`)
    12. Log supporting evidence (branch submissions references, leave approvals) for audit trail.

- **Outputs**: `payslips` row, optional `payslip_items`, audit log entry, notifications to employee (optional email/portal message)

## 6. Implementation Phases

1. **Foundation (Week 1)**
   - Finalize statutory formulas & assumptions document
  - Create database migration drafts, review with team (including approval-related tables and rate configs)
  - Draft helper scaffolding (`includes/payroll.php` with stubbed calculation and approval functions)
  - Prototype admin UI wireframes (payroll dashboards, approval queue, branch submissions intake, complaint tracking, rate management) for stakeholder sign-off before development

2. **Core Generation Flow (Week 2)**
  - Build payroll UI (list/generate/view) with stub calculations
  - Implement calculation logic for base pay and attendance adjustments
  - Store `payslips` and `breakdown`
  - Implement payroll run lifecycle (draft submission, move to `for_review`)
  - Build approval queue UI and persistence (`payroll_run_approvals`)
  - Implement rate configuration backend (load default, apply overrides) and admin interface skeleton
  - Integrate credential re-auth modal (reuse override infrastructure or dedicated password prompt) for approval actions
  - Implement branch submission intake module with upload validation, status dashboard, and reminder tracking

3. **Statutory Deductions & Adjustments (Week 3)**
  - Replace stubbed SSS/PhilHealth/Pag-IBIG/tax with accurate algorithms
  - Add UI for manual adjustments and notes
  - Ensure audit logs and override tokens around sensitive actions
  - Finalize approval actions (approve/reject, escalations) and release workflow (trigger `released_at`, employee notifications)
  - Enable rate override application (effective dating, conflict validation) with change history view
  - Implement complaint management flow (intake, resolution, auto-adjust flagging)

4. **Exports, Notifications, Polish (Week 4)**
   - Generate PDF/CSV payslips (reuse FPDF)
   - Integrate notifications, add module-specific reports
   - Complete documentation, update `README.md`, produce training materials
  - Wire reminders for branch submissions, approval SLAs, and complaint updates (email + in-app)

## 7. Testing & QA

- **Unit Tests**: (if feasible) standalone PHP scripts verifying calculation functions with reference cases
- **Integration Tests**: manual scenarios for new hires, salary changes, leaves, OTs, special holidays
- **Approval Tests**: scenarios covering multi-step approvals, rejection loops, approver reassignment, and admin configuration changes
- **Security Tests**: verify credential re-entry requirement, failed attempt logging, and session hijack mitigation for approval/release actions
- **UX Tests**: validate usability with HR preparers/approvers, ensuring forms, wizards, and tables are responsive and clear
- **Workflow Tests**: simulate branch submission timeline, missing documentation, hazard pay proration, 13th month processing, and complaint resolution.
- **Regression**: verify existing modules unaffected (attendance, leave, audit, notifications)
- **UAT Checklist**: confirm HR/finance sign-off on computed values, rounding, printed payslip format

## 8. Deployment Considerations

- Run new migrations via `tools/migrate.php`
- Seed statutory tables and sample data (PowerShell helper or SQL script)
- Schedule deployment outside payroll cutoffs; provide rollback plan (database backups)
- Train HR on new module before enabling for employees; include approval process walkthrough for preparers/reviewers
- Populate initial approver configuration (seed admin tool with HR payroll manager)
- Load default rate configurations and document override SOPs for admins
- Prepare documented rollback playbook: steps to revert a released payroll (invalidate approvals, reverse payslips, send corrected notifications, restore backups)

## 9. Rollback Procedures

1. **Preconditions**: capture database snapshot before each payroll release; archive exported reports sent to stakeholders.
2. **Triggering Reversal**:
  - Admin initiates "Rollback Payroll" action from payroll run view (credential re-entry required).
  - System flags run as `reverting`, notifies approvers and affected employees (holding communication).
3. **Automated Steps**:
  - Set payslips to `void` status; write reversal entries to `payslip_items` and audit logs.
  - Reverse any posted notifications (mark as retracted) and issue updated message.
  - If exports were generated, regenerate corrected files tagged as revision.
4. **Post-Rollback**:
  - Return payroll run to `draft` for correction.
  - Require comment on reason for rollback; expose in audit trail.
  - Notify finance/HR distribution list of completion.

## 10. Risk & Mitigation

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Statutory table updates mid-project | Incorrect deductions | Store tables in `payroll_rate_configs`; allow rapid overrides; monitor DOLE/BIR advisories |
| Attendance data gaps or inaccuracies | Wrong payslip calculations | Build validation report highlighting missing logs; require HR sign-off prior to generation |
| Approval bottlenecks | Delayed payroll release | Implement reminders/notifications, allow admin reassignments, track SLA metrics |
| Performance on large employee counts | Slow payroll run generation | Batch processing, optimize queries, cache rate tables |
| UX rejection by stakeholders | Rework late in cycle | Present wireframes/prototypes and conduct usability reviews early |

## 11. Success Metrics

- Payroll run generation time within agreed threshold (e.g., <5 minutes for 500 employees)
- 0 calculation discrepancies in UAT sign-off checklist
- Approval cycle completion within defined SLA (e.g., <24 hours)
- Positive usability feedback (e.g., ≥4/5 rating) from HR preparers and approvers during pilot
- Reduction in manual spreadsheet adjustments post-launch
- Timely branch submissions (100% before cutoff) with automated tracking metrics
- Complaint resolution within SLA (e.g., ≤3 business days) and logged adjustments applied next cycle

## 12. Data Migration & Backfill

- Inventory existing payroll spreadsheets or legacy totals to determine need for historical import
- Backfill allowances, loans, and other recurring deductions into new tables prior to first run
- Migrate existing approver assignments (if any) or capture from HR org chart
- Create scripts to preload statutory defaults and latest rates; document process for future updates
- Plan cutover timeline ensuring no overlap with active payroll cycle; schedule validation after migration

## 13. Analytics & Reporting Backlog

- Trend dashboards (e.g., total net pay per period, overtime costs, absence penalties) using existing chart components
- Statutory summaries (SSS, PhilHealth, Pag-IBIG, tax withholding) for monthly/quarterly filings
- Approval efficiency report showing cycle times, bottlenecks, and rejections
- Employee self-service analytics: historical payslip comparisons, year-to-date earnings/deductions
- Export templates aligned with government submission formats (future automation)
- Branch submission compliance dashboard (on-time submissions, missing documents)
- Complaint trends and resolution metrics for HR leadership

---
_Next actionable items_
1. Answer open questions with HR/finance stakeholders (especially approval tiers, approver scope, rate override governance)
2. Draft SQL migration scripts for `payslips`, approval tables, rate configuration, supporting tables
3. Outline `includes/payroll.php` helper functions, approval utilities, rate override loaders, and skeleton module pages
4. Produce low-fidelity mockups for payroll dashboards, approval queue, branch submission intake, complaint tracking, and admin configuration screens for stakeholder feedback
5. Draft risk register and success-metric tracking spreadsheet prior to development kickoff
6. Clarify leave policy integration (per attached workflow) and update deduction logic/spec accordingly

## 14. Execution Roadmap

To keep delivery manageable and align with corporate standards, execute in the following waves—each finishing with a review demo and checklist sign-off:

1. **Discovery & Validation (Week 0)**
  - Confirm open questions, finalize mockups, approve risk register
  - Prepare migration/test environments and collect sample data sets

2. **Data Foundation (Week 1)**
  - Implement schema migrations (`payslips`, approvals, rate configs, branch submissions, complaints)
  - Build seed scripts for statutory defaults, branches, and approver list
  - Deliver migration dry-run report for stakeholder approval

3. **Core Engine & Intake (Week 2)**
  - Develop calculation helpers, rate loaders, branch submission intake module
  - Scaffold payroll run lifecycle (draft → for_review) and base UI layouts
  - Unit-test earnings/deductions scenarios with provided datasets

4. **Approvals, Complaints & Security (Week 3)**
  - Complete multi-step approval workflow with credential re-entry
  - Implement complaint logging/resolution and post-release rollback flow
  - Harden audit logging, notifications, and override handling

5. **UX Polish & Reporting (Week 4)**
  - Finalize responsive UI/UX, confirmations, tooltips, dashboards
  - Add exports, analytics backlog scaffolding hooks, and documentation updates
  - Conduct end-to-end mock payroll run (dress rehearsal) and capture feedback

6. **Testing, UAT & Cutover (Week 5)**
  - Execute full QA matrix (unit, integration, approval, security, UX)
  - Run UAT with HR/finance, resolve findings, obtain sign-off
  - Plan production deployment window, rollback rehearsal, and hypercare schedule

7. **Go-Live & Hypercare (Week 6)**
  - Deploy to production per checklist; monitor success metrics and SLA dashboards
  - Provide rapid-response support for two payroll cycles; transition to steady-state maintenance

Deliverables and review gates at the end of each wave ensure the implementation stays aligned with corporate procedures and user-friendly expectations.
