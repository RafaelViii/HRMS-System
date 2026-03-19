# Payroll Revamp – Phase 1 Discovery & Validation

_Date: 2025-10-18_

## Objectives
- Reconfirm business rules, cutoff timelines, and approval expectations with client artifacts
- Inventory open questions and dependencies before schema/build work
- Define sample data/assets required for validation and test cycles
- Outline environment preparation tasks for development and QA

## Inputs Reviewed
- Client workflow diagram (HR payroll and leave management) – 2025-10-18 attachment
- Updated implementation plan (`2025-10-18_payroll_plan.md`)
- Existing database schema (`database/schema_postgre.sql`) and migrations
- Current payroll module files (`modules/payroll/*`)

## Key Assumptions (Pending Confirmation)
1. **Cutoff & Payout Schedule**: Branch submissions due on or before cutoffs (8–20, 21–5) with payouts on 30/15; no ad hoc overtime after cutoff unless flagged as emergency.
2. **Complaint SLA**: Employees must submit complaints within 48 hours of payout; HR resolves within 3 business days and applies adjustments in next cycle.
3. **Approver Tiers**: Two-tier workflow (HR payroll manager, administrator) is default; additional tiers handled via admin-configured sequence.
4. **Allowance Eligibility**: Hazard allowance applies only to clinical staff flagged in `employees` table (needs attribute); socio-economic allowance applies to all unless opted out.
5. **Leave Source of Truth**: `leave_requests` table contains approved leaves; statuses `approved` and `on-leave` in attendance align with deductions logic.
6. **File Storage**: Branch submission uploads stored under `assets/uploads/payroll_submissions/{run_id}/{branch}`; assumption that storage path is acceptable.

## Outstanding Questions
| # | Topic | Current Understanding | Needs | Owner |
|---|---|---|---|---|
| Q1 | Allowance Flags | Employees table has `salary` only | Need fields for `is_clinical`, `allowance_opt_out` | Client HR |
| Q2 | Approver Scope | Global list covers all branches | Clarify if branch-specific approvers required | Client HR/Admin |
| Q3 | Rate Overrides | Admin can set overrides with effective dates | Confirm approval/audit requirement for rate changes | Finance Controller |
| Q4 | Notification Channels | System currently supports in-app notifications | Decide if email notifications must be sent for approvals/complaints | IT/HR |
| Q5 | Historical Data | Need to backfill 12 months of payroll for analytics | Confirm availability/format (Excel, CSV) | Accounting |
| Q6 | Complaint Categories | Generic free text | Provide standard list (salary discrepancy, allowance, overtime, etc.) | HR Payroll |

## Required Sample Data / Assets
- Last 2 cutoff biometric exports (raw files) from each branch
- Representative manual logbook sample (PDF/Excel)
- Overtime request form (scanned) for linking metadata
- Approved leave CSV extract for a full month
- Example payroll computation spreadsheet with hazard & socio-economic allowances applied
- Existing admin list of approvers with roles

## Environment Preparation Checklist
- [ ] Provision dedicated development database (PostgreSQL 14+) & update `.env` with credentials
- [ ] Snapshot production schema for reference (no PII data)
- [ ] Schedule migration dry run window with DBA
- [ ] Enable filesystem write permissions for `assets/uploads/payroll_submissions`
- [ ] Identify SMTP or notification service for approval alerts (if email required)

## Blockers / Risks Identified
- Missing employee attributes for allowance eligibility will impact calculation accuracy
- Lack of standardized complaint categories may complicate analytics/reporting
- No current process defined for replacing approvers on leave; needs SOP

## Recommendations
- Arrange stakeholder workshop to resolve Q1–Q6 before Phase 2
- Obtain sample data and store in `handoff/derived/` (sanitized) for testing
- Draft SOP documents for rate override approval and complaint resolution to align with audit requirements

## Exit Criteria for Phase 1
- Stakeholder sign-off on assumptions and outstanding question list
- Confirmation that sample data and environment preparations are underway
- Approval to proceed to Phase 2 (Data Foundation implementation)

---
_Once sign-off is received, we will proceed with Phase 2: Data Foundation (schema migrations & seed scaffolding)._