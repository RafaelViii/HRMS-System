# Payroll Module Data Model (Milestone 1)

## Core Entities

### payroll_runs
- Captures each payroll cycle window (period_start, period_end).
- Tracks run mode (`automatic`/`manual`), computation mode (`queued`/`synchronous`), and settings snapshot.
- Links to initiating user (`initiated_by`), generated user (`generated_by`), release metadata, and optional approval template.
- Supports lifecycle timestamps for submission and closure.

### payroll_batches
- One row per branch included in a payroll run.
- Stores batch status, computation mode, branch submission metadata, job tracking id, and remarks.
- Retains serialized approver chains and approval logs per batch.

### payslips
- Employee-level payroll result for a run.
- Contains earnings and deductions JSON arrays, gross/net totals, remarks, and version metadata.
- References prior version (`prev_version_id`) and release auditor (`released_by`).
- Aggregated metadata stored in `rollup_meta` for quick UI rendering.

### payslip_versions
- Immutable history of payslip snapshots per revision.
- Stores serialized snapshot payload, change reason, and user who generated the version.
- Ensures compliance by keeping all historical versions.

### payroll_complaints
- Employee-filed issues tied to a payroll run and optionally a payslip.
- Tracks subject, description, attachments array, assigned resolver, ticket code, and status lifecycle.
- Status values (existing): `pending`, `in_review`, `resolved`, `rejected`.
- UI mapping note: “Open” filter = `pending` + `in_review` (no separate enum value).

### payroll_formula_settings
- Configurable store for calculation multipliers, base rates, and formula expressions.
- Entries grouped by category (earnings, deductions, meta) with JSON configuration blobs.
- Versioned via effective_start / effective_end for future adjustments.

### payroll_approval_chain_templates & steps
- Defines reusable approval workflows per scope (global, branch, company).
- Steps reference HRMS user roles with ordering, override requirement, and notification flags.
- Payload powers payroll run + batch approval routing.
- Status mapping: lifecycle uses existing `for_review` for “Under Review”; add `submitted`, `for_revision`, `closed` to the run enum only if not present.

## Relationships Overview
- `payroll_runs` 1->N `payroll_batches` (per-branch breakout).
- `payroll_runs` 1->N `payslips`; `payslips` 1->N `payslip_versions`.
- `payroll_runs` N<-1 `payroll_approval_chain_templates`; `payroll_batches` optionally override with own template.
- `payroll_complaints` reference both `payroll_runs` and `payslips`, binding employee disputes to source data.
- `payroll_formula_settings` remains standalone; consumed by computation engine per run/batch to derive rates.

## Notes
- All JSON columns default to empty objects/arrays for deterministic reads.
- Foreign keys cascade on delete for dependent artifacts while preserving audit trails via version tables.
- Additional indexes added for lookup-heavy fields (mode, status, assignment, release timestamps).
- Security & audit: All mutation flows must implement CSRF + PRG, `action_log()` on user-facing actions, and `ensure_action_authorized()` for critical operations (approve/reject/release/edits).
