-- Payroll foundation schema additions
-- Date: 2025-10-18

BEGIN;

-- Enumerations for payroll workflow
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_run_status') THEN
        CREATE TYPE payroll_run_status AS ENUM ('draft','for_review','approved','released','rejected','reverting');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_approval_status') THEN
        CREATE TYPE payroll_approval_status AS ENUM ('pending','approved','rejected','skipped');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payslip_status') THEN
        CREATE TYPE payslip_status AS ENUM ('draft','locked','released','void');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payslip_item_type') THEN
        CREATE TYPE payslip_item_type AS ENUM ('earning','deduction','info');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'rate_config_category') THEN
        CREATE TYPE rate_config_category AS ENUM ('statutory','allowance','custom_rate');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'branch_submission_status') THEN
        CREATE TYPE branch_submission_status AS ENUM ('pending','submitted','accepted','rejected','missing');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_complaint_status') THEN
        CREATE TYPE payroll_complaint_status AS ENUM ('pending','in_review','resolved','rejected');
    END IF;
END $$;

-- Reference table for company branches
CREATE TABLE IF NOT EXISTS branches (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    address TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Payroll run header
CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status payroll_run_status NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    generated_by INT NULL,
    released_at TIMESTAMP WITHOUT TIME ZONE NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_runs_generated_by FOREIGN KEY (generated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payroll_runs_period ON payroll_runs (period_start, period_end);

-- Payslip record per employee and run
CREATE TABLE IF NOT EXISTS payslips (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payroll_run_id INT NULL,
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    basic_pay NUMERIC(10,2) NOT NULL DEFAULT 0,
    total_earnings NUMERIC(10,2) NOT NULL DEFAULT 0,
    total_deductions NUMERIC(10,2) NOT NULL DEFAULT 0,
    net_pay NUMERIC(10,2) NOT NULL DEFAULT 0,
    breakdown JSONB NOT NULL DEFAULT '{}'::jsonb,
    status payslip_status NOT NULL DEFAULT 'draft',
    generated_by INT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payslips_run FOREIGN KEY (payroll_run_id)
        REFERENCES payroll_runs (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payslips_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payslips_generated_by FOREIGN KEY (generated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payslips_employee_period ON payslips (employee_id, period_start, period_end);

-- Optional granular line items per payslip
CREATE TABLE IF NOT EXISTS payslip_items (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payslip_id INT NOT NULL,
    type payslip_item_type NOT NULL,
    code VARCHAR(50) NULL,
    label VARCHAR(191) NOT NULL,
    amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    meta JSONB NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payslip_items_payslip FOREIGN KEY (payslip_id)
        REFERENCES payslips (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payslip_items_payslip ON payslip_items (payslip_id);

-- Approval routing tables
CREATE TABLE IF NOT EXISTS payroll_approvers (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INT NOT NULL,
    step_order SMALLINT NOT NULL DEFAULT 1,
    applies_to VARCHAR(50) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_approvers_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_payroll_approvers_user_scope ON payroll_approvers (user_id, COALESCE(applies_to, 'global'));

CREATE TABLE IF NOT EXISTS payroll_run_approvals (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    approver_id INT NOT NULL,
    step_order SMALLINT NOT NULL,
    status payroll_approval_status NOT NULL DEFAULT 'pending',
    remarks TEXT NULL,
    acted_at TIMESTAMP WITHOUT TIME ZONE NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_run_approvals_run FOREIGN KEY (payroll_run_id)
        REFERENCES payroll_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_run_approvals_approver FOREIGN KEY (approver_id)
        REFERENCES payroll_approvers (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_run_approvals_step ON payroll_run_approvals (payroll_run_id, step_order);

-- Rate configuration overrides
CREATE TABLE IF NOT EXISTS payroll_rate_configs (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category rate_config_category NOT NULL,
    code VARCHAR(100) NOT NULL,
    label VARCHAR(191) NOT NULL,
    default_value NUMERIC(12,4) NOT NULL,
    override_value NUMERIC(12,4) NULL,
    effective_start DATE NOT NULL,
    effective_end DATE NULL,
    meta JSONB NULL,
    updated_by INT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rate_configs_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_rate_configs_code_dates ON payroll_rate_configs (code, effective_start);

-- Branch submission tracking per payroll run
CREATE TABLE IF NOT EXISTS payroll_branch_submissions (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    branch_id INT NOT NULL,
    status branch_submission_status NOT NULL DEFAULT 'pending',
    submitted_at TIMESTAMP WITHOUT TIME ZONE NULL,
    biometric_path VARCHAR(255) NULL,
    logbook_path VARCHAR(255) NULL,
    supporting_docs_path VARCHAR(255) NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_branch_submissions_run FOREIGN KEY (payroll_run_id)
        REFERENCES payroll_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_branch_submissions_branch FOREIGN KEY (branch_id)
        REFERENCES branches (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_branch_submission_run_branch ON payroll_branch_submissions (payroll_run_id, branch_id);

-- Payroll complaint log
CREATE TABLE IF NOT EXISTS payroll_complaints (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    payslip_id INT NULL,
    employee_id INT NOT NULL,
    issue_type VARCHAR(100) NULL,
    description TEXT NOT NULL,
    status payroll_complaint_status NOT NULL DEFAULT 'pending',
    submitted_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP WITHOUT TIME ZONE NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payroll_complaints_run FOREIGN KEY (payroll_run_id)
        REFERENCES payroll_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_complaints_payslip FOREIGN KEY (payslip_id)
        REFERENCES payslips (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payroll_complaints_employee FOREIGN KEY (employee_id)
        REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_payroll_complaints_employee ON payroll_complaints (employee_id, status);

COMMIT;
