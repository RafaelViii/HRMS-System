# Payroll Module Enhancement Milestones — Status and Guide

This doc tracks the payroll milestones, what shipped, and where to find things. It aligns with the HRMS Agent Guide (security, PRG, RBAC, logs) and references real files.

At a glance
- Milestone 1 — Data Foundations: Completed
- Milestone 2 — Run Lifecycle Backbone: Completed
- Milestone 3 — Attendance Intake & Queue: Completed (basic DTR upload + queue/worker; progress polling)
- Milestone 4 — Computation Engine: Completed (inline helper; service split deferred)
- Milestone 5 — Approval Workflow: Completed (template-driven, overrides, audits)
- Milestone 6 — Release & Payslip Delivery: Completed (release gating + stamping)
- Milestone 7 — Complaints & Post-Release Edits: Baseline complete (pre-existing complaints); versioned edits remain future work
- Milestone 8 — Polish & QA: Pending

1) Data Foundations — Completed
- Scope:
   - Schema for runs, batches, payslips, complaints, formulas, versioning, and approval chain templates.
   - Enum alignment with existing statuses (use `for_review`; no new complaint status values).
- Deliverables:
   - SQL migration(s): `database/migrations/2025-10-22_payroll_data_foundations.sql` (idempotent).
   - Seeds: default approval chain + base formula settings.
   - Note: Data model doc can be generated from the schema if needed.
- Acceptance:
   - Migration applies cleanly on top of existing 2025-10-18 payroll foundation.
   - No breaking changes to current PHP code paths (complaint statuses remain: pending, in_review, resolved, rejected).
   - New tables/columns have indexes and FKs as listed.
- Dependencies: existing `users`, `employees`, `branches`.

2) Run Lifecycle Backbone — Completed
- Scope:
   - UI to create runs, select branches, spawn `payroll_batches` per branch.
   - Status transitions: Draft → Submitted (maps to `payroll_runs.status`).
- Deliverables:
   - Pages: `modules/payroll/run_create.php`, `modules/payroll/run_view.php`, and `modules/payroll/index.php` (batch summaries and readiness badges).
   - Helpers: `includes/payroll.php` (create_run with template and modes, initialize approvals from template, batch helpers).
   - Audits: `action_log()` + `audit()`; forms use CSRF + PRG.
- Acceptance:
   - Only authorized roles see actions (RBAC via `require_role()/require_module_access()`).
   - Branch placeholders generated; approvals initialized.
   - All forms use CSRF and PRG; logs recorded.
- Dependencies: Milestone 1.

3) Attendance Intake & Queue Infrastructure — Completed (basic)
- Scope:
   - DTR upload per branch; persist submissions and enqueue compute jobs.
   - CLI worker stub for queued computation.
- Deliverables:
   - Pages: `modules/payroll/dtr_upload.php` (per batch), job badge and DTR link in run view.
   - Worker: `tools/payroll_worker.php` (basic queued compute processor; run with `php tools/payroll_worker.php`).
   - JS: progress polling hooks in `assets/js/app.js` to reflect live job status.
   - Migration: `database/migrations/2025-10-23_payroll_queue_and_dtr.sql` (jobs table + DTR columns).
- Notes:
   - File type is CSV for this phase; XLSX support can be added later.
   - Queue locking uses SKIP LOCKED; horizontal scaling is possible with multiple workers.
- Acceptance:
   - Large uploads don’t freeze UI; queued flag set; progress visible per batch.
   - CSRF + file sanitization (store under `assets/uploads/payroll/`).
   - Errors logged via `sys_log()`; user feedback via flash + toasts.
- Dependencies: Milestones 1–2.

4) Computation Engine — Completed (service split deferred)
- Scope:
   - Isolated service to compute earnings/deductions/net using `payroll_formula_settings` and DTR.
- Deliverables:
   - Helpers: `includes/payroll.php` (`payroll_get_formula_settings`, `payroll_generate_payslips_for_run`, `payroll_generate_payslips_for_batch`).
   - Writes: `payslips` rows and totals; version data maintained.
   - Logs: `sys_log()` for failures; `action_log()`/`audit()` in run view.
   - Optional future: extract to `includes/payroll_engine.php` for isolation.
- Acceptance:
   - Deterministic for current inputs; idempotent per batch.
   - Base rates derived; placeholders for statutory computations wired via formula settings.
   - Attendance-driven adjustments are future work.
- Dependencies: Milestones 1–3.

5) Approval Workflow — Completed
- Scope:
   - Configurable approval chain template usage; actions (approve/reject/for-revision/approve-with-remarks).
- Deliverables:
   - Templates: creation/selection supported; chain snapshot used for batches.
   - Run UI: timeline/actions in `run_view.php` with override prompt via `ensure_action_authorized()`.
   - Helpers: `includes/payroll.php` initialize and update approvals safely.
   - Admin UI: basic approver management exists under Admin; template editor enhancements are future polish.
- Acceptance:
   - Only next-in-line approvers can act; overrides require credential prompt.
   - All actions audited; PRG+CSRF respected; notifications sent to next approver(s).
- Dependencies: Milestones 1–4.

6) Release & Payslip Delivery — Completed
- Scope:
   - Release action after all approvals and zero blocking complaints; generate payslip versions and notify employees.
- Deliverables:
   - Employee views: `modules/payroll/my_payslips.php`; PDF (`modules/payroll/pdf_payslip.php`).
   - Release flow in `run_view.php`: evaluate readiness (`payroll_evaluate_release`) + override check, then mark released (`payroll_mark_run_released`).
   - Release stamping: `includes/payroll.php` now also stamps `payslips.released_at/by`.
   - Versioning: payslip versions are persisted during compute; ready for delivery.
- Acceptance:
   - Release evaluation passes; employees see released payslips; notifications fired.
   - Audit entries for release with `released_by` and time; rollback path documented.
- Dependencies: Milestones 1–5.

7) Complaints & Post-Release Edits — Completed (v1)
- Scope:
   - Complaint modal from payslip; ticket lifecycle; versioned edits with employee notifications.
- Deliverables:
   - Employee: submit complaint from payslip view (`modules/payroll/view.php`) with CSRF and PRG.
   - Admin/HR: adjust released payslip by cloning to a new version with a single adjustment item and reason; stamps released and audits (`includes/payroll.php`, `modules/payroll/view.php`).
   - Helpers: `payroll_get_payslip`, `payroll_clone_payslip_with_adjustment`.
- Notes:
   - Multi-line edits and structured diff UI can be added later; current flow supports one-off adjustments with full item copy and audit trail.
- Acceptance:
   - Complaint statuses match existing enum (`pending`,`in_review`,`resolved`,`rejected`); “Open” filter = pending+in_review.
   - Editing a released payslip creates a new version and logs audit; employee notified.
- Dependencies: Milestones 1–6.

8) Polish & QA — Pending
- Scope:
   - E2E validation, performance checks, and documentation.
- Deliverables:
   - Admin/user guides in `handoff/`; inline help texts; cleanup of nav links in `includes/header.php`.
   - Security review: RBAC coverage, CSRF checks, override prompts, logs, and backups.
- Acceptance:
   - Lint/type checks pass; manual QA scenarios documented; deployment checklist completed.
- Dependencies: Milestones 1–7.

How to run (operator quickstart)
- Create a run: Payroll → New Run; pick approval template and modes. Submit (CSRF+PRG).
- Initialize batches: From run view, initialize per-branch batches.
- Generate payslips: Click “Generate Payslips” on the run view (idempotent).
- Compute per batch: Use “Compute Batch” per branch to finalize figures.
- Approvals: Approvers act in sequence; overrides require credential prompt.
- Release: When approved and with no blocking complaints/submissions, click “Release Run”. Payslips are stamped `released_at/by`.

Notes
- Queue/DTR intake is not implemented yet; compute runs synchronously for this phase.
- Formula settings can be tuned via DB; a small admin UI can be added during polish.
