-- Payroll data foundations for enhanced module (Milestone 1)
-- Date: 2025-10-22

BEGIN;

-- Ensure user_role enum exists (fallback for environments missing base schema load)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
        CREATE TYPE user_role AS ENUM (
            'admin','hr','employee','accountant','manager',
            'hr_supervisor','hr_recruit','hr_payroll','admin_assistant'
        );
    END IF;
END $$;

-- Enumerations for run/batch modes and statuses
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_run_mode') THEN
        CREATE TYPE payroll_run_mode AS ENUM ('automatic','manual');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_computation_mode') THEN
        CREATE TYPE payroll_computation_mode AS ENUM ('queued','synchronous');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_batch_status') THEN
        CREATE TYPE payroll_batch_status AS ENUM (
            'pending','awaiting_dtr','submitted','computing','for_review','for_revision','approved','released','closed','error'
        );
    END IF;
END $$;

-- Extend existing lifecycle enumerations
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_run_status') THEN
        BEGIN
            ALTER TYPE payroll_run_status ADD VALUE IF NOT EXISTS 'submitted';
            -- Use existing 'for_review' for "Under Review" to preserve compatibility
            ALTER TYPE payroll_run_status ADD VALUE IF NOT EXISTS 'for_revision';
            ALTER TYPE payroll_run_status ADD VALUE IF NOT EXISTS 'closed';
        EXCEPTION
            WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;

-- Approval chain templates
CREATE TABLE IF NOT EXISTS payroll_approval_chain_templates (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chain_key VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(191) NOT NULL,
    description TEXT NULL,
    scope_type VARCHAR(50) NULL,
    scope_identifier VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payroll_approval_chain_steps (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    template_id INT NOT NULL,
    step_order SMALLINT NOT NULL,
    role user_role NOT NULL,
    requires_override BOOLEAN NOT NULL DEFAULT TRUE,
    notify BOOLEAN NOT NULL DEFAULT TRUE,
    instructions TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_approval_chain_step_template FOREIGN KEY (template_id)
        REFERENCES payroll_approval_chain_templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT uq_payroll_approval_chain_step UNIQUE (template_id, step_order)
);

-- payroll_runs enhancements
ALTER TABLE payroll_runs
    ADD COLUMN IF NOT EXISTS company_id INT NULL,
    ADD COLUMN IF NOT EXISTS run_mode payroll_run_mode NOT NULL DEFAULT 'automatic',
    ADD COLUMN IF NOT EXISTS computation_mode payroll_computation_mode NOT NULL DEFAULT 'queued',
    ADD COLUMN IF NOT EXISTS settings_snapshot JSONB NULL,
    ADD COLUMN IF NOT EXISTS initiated_by INT NULL,
    ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP WITHOUT TIME ZONE NULL,
    ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP WITHOUT TIME ZONE NULL,
    ADD COLUMN IF NOT EXISTS approval_template_id INT NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payroll_runs' AND constraint_name = 'fk_payroll_runs_initiated_by'
    ) THEN
        ALTER TABLE payroll_runs
            ADD CONSTRAINT fk_payroll_runs_initiated_by
            FOREIGN KEY (initiated_by) REFERENCES users (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payroll_runs' AND constraint_name = 'fk_payroll_runs_approval_template'
    ) THEN
        ALTER TABLE payroll_runs
            ADD CONSTRAINT fk_payroll_runs_approval_template
            FOREIGN KEY (approval_template_id) REFERENCES payroll_approval_chain_templates (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_payroll_runs_run_mode ON payroll_runs (run_mode);
CREATE INDEX IF NOT EXISTS idx_payroll_runs_comp_mode ON payroll_runs (computation_mode);
CREATE INDEX IF NOT EXISTS idx_payroll_runs_approval_template ON payroll_runs (approval_template_id);

-- payroll_batches table
CREATE TABLE IF NOT EXISTS payroll_batches (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    branch_id INT NOT NULL,
    status payroll_batch_status NOT NULL DEFAULT 'pending',
    computation_mode payroll_computation_mode NOT NULL DEFAULT 'queued',
    approvers_chain JSONB NULL,
    approvals_log JSONB NULL,
    submission_meta JSONB NULL,
    submitted_by INT NULL,
    computation_job_id VARCHAR(100) NULL,
    last_computed_at TIMESTAMP WITHOUT TIME ZONE NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    approval_template_id INT NULL,
    CONSTRAINT fk_payroll_batches_run FOREIGN KEY (payroll_run_id)
        REFERENCES payroll_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_batches_branch FOREIGN KEY (branch_id)
        REFERENCES branches (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_batches_submitted_by FOREIGN KEY (submitted_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_batches_template FOREIGN KEY (approval_template_id)
        REFERENCES payroll_approval_chain_templates (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT uq_payroll_batches_run_branch UNIQUE (payroll_run_id, branch_id)
);

CREATE INDEX IF NOT EXISTS idx_payroll_batches_status ON payroll_batches (status);
CREATE INDEX IF NOT EXISTS idx_payroll_batches_job_id ON payroll_batches (computation_job_id);

-- payslip extensions
ALTER TABLE payslips
    ADD COLUMN IF NOT EXISTS gross_pay NUMERIC(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS earnings_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS deductions_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS remarks TEXT NULL,
    ADD COLUMN IF NOT EXISTS version INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS prev_version_id INT NULL,
    ADD COLUMN IF NOT EXISTS change_reason TEXT NULL,
    ADD COLUMN IF NOT EXISTS released_at TIMESTAMP WITHOUT TIME ZONE NULL,
    ADD COLUMN IF NOT EXISTS released_by INT NULL,
    ADD COLUMN IF NOT EXISTS rollup_meta JSONB NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payslips' AND constraint_name = 'fk_payslips_prev_version'
    ) THEN
        ALTER TABLE payslips
            ADD CONSTRAINT fk_payslips_prev_version
            FOREIGN KEY (prev_version_id) REFERENCES payslips (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payslips' AND constraint_name = 'fk_payslips_released_by'
    ) THEN
        ALTER TABLE payslips
            ADD CONSTRAINT fk_payslips_released_by
            FOREIGN KEY (released_by) REFERENCES users (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_payslips_version ON payslips (payroll_run_id, employee_id, version);
CREATE INDEX IF NOT EXISTS idx_payslips_released_at ON payslips (released_at);

-- payslip version history
CREATE TABLE IF NOT EXISTS payslip_versions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payslip_id INT NOT NULL,
    version INT NOT NULL,
    snapshot JSONB NOT NULL,
    change_reason TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payslip_versions_payslip FOREIGN KEY (payslip_id)
        REFERENCES payslips (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payslip_versions_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT uq_payslip_versions UNIQUE (payslip_id, version)
);

-- payroll complaints enrichment
ALTER TABLE payroll_complaints
    ADD COLUMN IF NOT EXISTS subject VARCHAR(191) NULL,
    ADD COLUMN IF NOT EXISTS attachments JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS assigned_to INT NULL,
    ADD COLUMN IF NOT EXISTS ticket_code VARCHAR(30) NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payroll_complaints' AND constraint_name = 'fk_payroll_complaints_assigned_to'
    ) THEN
        ALTER TABLE payroll_complaints
            ADD CONSTRAINT fk_payroll_complaints_assigned_to
            FOREIGN KEY (assigned_to) REFERENCES users (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_payroll_complaints_ticket ON payroll_complaints (ticket_code);
CREATE INDEX IF NOT EXISTS idx_payroll_complaints_assigned ON payroll_complaints (assigned_to);

-- formula settings configuration
CREATE TABLE IF NOT EXISTS payroll_formula_settings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code VARCHAR(100) NOT NULL,
    label VARCHAR(191) NOT NULL,
    category VARCHAR(50) NOT NULL,
    description TEXT NULL,
    default_value NUMERIC(18,6) NULL,
    is_percentage BOOLEAN NOT NULL DEFAULT FALSE,
    config JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    effective_start DATE NOT NULL DEFAULT CURRENT_DATE,
    effective_end DATE NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_payroll_formula_settings UNIQUE (code, effective_start)
);

CREATE INDEX IF NOT EXISTS idx_payroll_formula_category ON payroll_formula_settings (category);

INSERT INTO payroll_approval_chain_templates (chain_key, label, description, scope_type, scope_identifier, is_active)
VALUES
    ('default_payroll_chain', 'Default Payroll Chain', 'Admin -> HR Supervisor -> HR Payroll', 'global', NULL, TRUE)
ON CONFLICT (chain_key) DO UPDATE
SET label = EXCLUDED.label,
    description = EXCLUDED.description,
    scope_type = EXCLUDED.scope_type,
    scope_identifier = EXCLUDED.scope_identifier,
    is_active = EXCLUDED.is_active;

WITH template AS (
    SELECT id FROM payroll_approval_chain_templates WHERE chain_key = 'default_payroll_chain' LIMIT 1
)
INSERT INTO payroll_approval_chain_steps (template_id, step_order, role, requires_override, notify, instructions)
SELECT template.id, v.step_order, v.role, v.requires_override, v.notify, v.instructions
FROM template
CROSS JOIN (VALUES
    (1::smallint, 'admin'::user_role, TRUE, TRUE, 'Initial certification'),
    (2::smallint, 'hr_supervisor'::user_role, TRUE, TRUE, 'Review timekeeping and attendance variances'),
    (3::smallint, 'hr_payroll'::user_role, TRUE, TRUE, 'Finalize payroll computation and release readiness')
) AS v(step_order, role, requires_override, notify, instructions)
ON CONFLICT (template_id, step_order) DO UPDATE
SET role = EXCLUDED.role,
    requires_override = EXCLUDED.requires_override,
    notify = EXCLUDED.notify,
    instructions = EXCLUDED.instructions;

-- Seed formula settings (effective 2025-10-22)
WITH base_date AS (
    SELECT DATE '2025-10-22' AS effective_date
)
INSERT INTO payroll_formula_settings (code, label, category, description, default_value, is_percentage, config, effective_start)
SELECT v.code, v.label, v.category, v.description, v.default_value, v.is_percentage, v.config, base_date.effective_date
FROM base_date
CROSS JOIN (
    VALUES
        ('basic_pay_base', 'Basic Pay Base', 'earnings', 'Base pay computed from employment monthly rate.', 1.000000, FALSE, '{"basis":"monthly_rate"}'::jsonb),
        ('overtime_multiplier', 'Overtime Multiplier', 'earnings', 'Standard overtime multiplier (1.25x).', 1.250000, FALSE, '{"component":"overtime","type":"multiplier"}'::jsonb),
        ('rest_day_ot_multiplier', 'Rest Day OT Multiplier', 'earnings', 'Overtime worked on rest days (1.30x).', 1.300000, FALSE, '{"component":"rest_day_overtime","type":"multiplier"}'::jsonb),
        ('regular_holiday_multiplier', 'Regular Holiday Multiplier', 'earnings', 'Regular holiday rate (2.00x).', 2.000000, FALSE, '{"component":"regular_holiday","type":"multiplier"}'::jsonb),
        ('regular_holiday_ot_multiplier', 'Regular Holiday OT Multiplier', 'earnings', 'Regular holiday overtime rate (2.60x).', 2.600000, FALSE, '{"component":"regular_holiday_ot","type":"multiplier"}'::jsonb),
        ('special_holiday_multiplier', 'Special Non-Working Day Multiplier', 'earnings', 'Special non-working day rate (1.30x).', 1.300000, FALSE, '{"component":"special_non_working","type":"multiplier"}'::jsonb),
        ('special_holiday_ot_multiplier', 'Special Non-Working OT Multiplier', 'earnings', 'Special non-working day overtime rate (1.69x).', 1.690000, FALSE, '{"component":"special_non_working_ot","type":"multiplier"}'::jsonb),
        ('allowance_transport', 'Transport Allowance Default', 'earnings', 'Monthly transport allowance default value.', 0.000000, FALSE, '{"component":"allowance","code":"TA"}'::jsonb),
        ('allowance_meal', 'Meal Allowance Default', 'earnings', 'Monthly meal allowance default value.', 0.000000, FALSE, '{"component":"allowance","code":"MA"}'::jsonb),
        ('allowance_living', 'Living Allowance Default', 'earnings', 'Monthly living allowance default value.', 0.000000, FALSE, '{"component":"allowance","code":"LA"}'::jsonb),
        ('attendance_absence_formula', 'Absence Deduction Formula', 'deductions', 'Deduct based on absent days multiplied by daily rate.', NULL, FALSE, '{"basis":"daily_rate","input":"absent_days"}'::jsonb),
        ('attendance_tardiness_formula', 'Tardiness Deduction Formula', 'deductions', 'Deduct based on minutes late multiplied by per-minute rate.', NULL, FALSE, '{"basis":"minute_rate","input":"tardy_minutes"}'::jsonb),
        ('sss_employee_share', 'SSS Employee Share', 'deductions', 'SSS contribution percentage (employee share).', 0.045000, TRUE, '{"table_year":2025,"split":"bi-monthly"}'::jsonb),
        ('philhealth_employee_share', 'PhilHealth Employee Share', 'deductions', 'PhilHealth contribution rate.', 0.050000, TRUE, '{"split":"bi-monthly"}'::jsonb),
        ('pagibig_employee_share', 'Pag-IBIG Employee Share', 'deductions', 'Pag-IBIG contribution rate.', 0.020000, TRUE, '{"cap":100,"split":"bi-monthly"}'::jsonb),
        ('withholding_tax_table', 'Withholding Tax Table', 'deductions', 'BIR progressive tax tables reference.', NULL, FALSE, '{"table":"BIR-2025","split":"bi-monthly"}'::jsonb),
        ('loan_deduction_rule', 'Loan Deduction Rule', 'deductions', 'Loan repayment deduction configuration.', NULL, FALSE, '{"component":"loan","type":"amortization"}'::jsonb),
        ('net_pay_formula', 'Net Pay Formula', 'meta', 'Total earnings minus total deductions.', NULL, FALSE, '{"formula":"total_earnings - total_deductions"}'::jsonb),
        ('rate_computation_defaults', 'Rate Computation Defaults', 'meta', 'Working days and hours for rate computation.', NULL, FALSE, '{"working_days_per_month":22,"hours_per_day":8}'::jsonb)
) AS v(code, label, category, description, default_value, is_percentage, config)
ON CONFLICT (code, effective_start) DO UPDATE
SET label = EXCLUDED.label,
    category = EXCLUDED.category,
    description = EXCLUDED.description,
    default_value = EXCLUDED.default_value,
    is_percentage = EXCLUDED.is_percentage,
    config = EXCLUDED.config,
    is_active = TRUE;

COMMIT;
