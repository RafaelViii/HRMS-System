-- Payroll module seed data
-- Safe to run after 2025-10-18_payroll_foundation migration

BEGIN;

-- Branches
INSERT INTO branches (code, name, address) VALUES
    ('BRN-01', 'Main Branch', '123 Central Ave, City'),
    ('BRN-02', 'North Branch', '45 North Rd, City'),
    ('BRN-03', 'South Branch', '89 South Blvd, City'),
    ('BRN-04', 'East Branch', '12 East St, City'),
    ('BRN-05', 'West Branch', '77 West Ave, City'),
    ('BRN-06', 'Clinic Branch', '5 Health Way, City')
ON CONFLICT (code) DO NOTHING;

-- Default approvers (replace with real user IDs post-migration)
-- NOTE: Update user IDs based on production data; using 1 and 2 as placeholders.
INSERT INTO payroll_approvers (user_id, step_order, applies_to, active)
VALUES
    (1, 1, 'global', TRUE),
    (2, 2, 'global', TRUE)
ON CONFLICT (user_id, COALESCE(applies_to, 'global')) DO UPDATE SET step_order = EXCLUDED.step_order, active = EXCLUDED.active;

-- Rate configurations
INSERT INTO payroll_rate_configs (category, code, label, default_value, override_value, effective_start, meta)
VALUES
    ('statutory', 'sss_employee_pct', 'SSS Employee Share %', 4.5, NULL, CURRENT_DATE, '{"notes":"Use latest SSS table for reference"}'::jsonb),
    ('statutory', 'philhealth_employee_pct', 'PhilHealth Employee Share %', 2.75, NULL, CURRENT_DATE, '{"deduction":"employee"}'::jsonb),
    ('statutory', 'pagibig_employee_fixed', 'Pag-IBIG Employee Fixed', 100, NULL, CURRENT_DATE, '{"currency":"PHP"}'::jsonb),
    ('custom_rate', 'working_days_per_year', 'Working Days Per Year', 261, NULL, CURRENT_DATE, 'null'),
    ('allowance', 'hazard_allowance_amount', 'Hazard Allowance (Monthly)', 3000, NULL, CURRENT_DATE, '{"prorate":"days_present"}'::jsonb),
    ('allowance', 'socio_economic_allowance_amount', 'Socio-Economic Allowance (Monthly)', 1500, NULL, CURRENT_DATE, '{"prorate":"days_present"}'::jsonb)
ON CONFLICT (code, effective_start) DO NOTHING;

COMMIT;
