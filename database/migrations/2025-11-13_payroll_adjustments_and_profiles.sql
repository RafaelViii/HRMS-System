-- Migration: Payroll adjustments queue and enhanced employee payroll profiles
-- Date: 2025-11-13
-- Purpose:
--   * Introduce a queue for deferred payroll adjustments (e.g., complaint resolutions)
--   * Extend employee payroll profiles with rate customization fields
--   * Enrich payroll complaints workflow metadata (review, resolution, confirmation)

BEGIN;

-- Ensure new complaint status is available
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_type t
        JOIN pg_enum e ON t.oid = e.enumtypid
        WHERE t.typname = 'payroll_complaint_status'
          AND e.enumlabel = 'confirmed'
    ) THEN
        -- already present
        NULL;
    ELSE
        ALTER TYPE payroll_complaint_status ADD VALUE 'confirmed';
    END IF;
END$$;

-- Extend employee payroll profiles with customization fields
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'employee_payroll_profiles'
          AND column_name = 'overtime_multiplier'
    ) THEN
        ALTER TABLE employee_payroll_profiles
            ADD COLUMN overtime_multiplier NUMERIC(6,3) DEFAULT 1.250;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'employee_payroll_profiles'
          AND column_name = 'custom_hourly_rate'
    ) THEN
        ALTER TABLE employee_payroll_profiles
            ADD COLUMN custom_hourly_rate NUMERIC(12,2) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'employee_payroll_profiles'
          AND column_name = 'custom_daily_rate'
    ) THEN
        ALTER TABLE employee_payroll_profiles
            ADD COLUMN custom_daily_rate NUMERIC(12,2) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'employee_payroll_profiles'
          AND column_name = 'profile_notes'
    ) THEN
        ALTER TABLE employee_payroll_profiles
            ADD COLUMN profile_notes TEXT NULL;
    END IF;

    -- Backfill default overtime multiplier for existing rows
    UPDATE employee_payroll_profiles
       SET overtime_multiplier = COALESCE(overtime_multiplier, 1.250)
     WHERE overtime_multiplier IS NULL;
END$$;

-- Create payroll adjustment queue table for deferred earnings/deductions
CREATE TABLE IF NOT EXISTS payroll_adjustment_queue (
    id                         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id                INT NOT NULL,
    complaint_id               INT NULL,
    cutoff_period_id           INT NULL,
    payroll_run_id             INT NULL,
    payslip_id                 INT NULL,
    effective_period_start     DATE NOT NULL,
    effective_period_end       DATE NOT NULL,
    adjustment_type            payslip_item_type NOT NULL DEFAULT 'earning',
    code                       VARCHAR(32) NULL,
    label                      VARCHAR(191) NOT NULL,
    amount                     NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    notes                      TEXT NULL,
    status                     VARCHAR(20) NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','applied','cancelled','skipped')),
    created_by                 INT NULL,
    created_at                 TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at                 TIMESTAMP WITHOUT TIME ZONE NULL,
    updated_at                 TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add foreign keys (idempotent guards rely on constraint names)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_employee'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_employee
                FOREIGN KEY (employee_id)
                REFERENCES employees (id)
                ON DELETE CASCADE ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_complaint'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_complaint
                FOREIGN KEY (complaint_id)
                REFERENCES payroll_complaints (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_cutoff'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_cutoff
                FOREIGN KEY (cutoff_period_id)
                REFERENCES cutoff_periods (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_run'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_run
                FOREIGN KEY (payroll_run_id)
                REFERENCES payroll_runs (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_payslip'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_payslip
                FOREIGN KEY (payslip_id)
                REFERENCES payslips (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_created_by'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_created_by
                FOREIGN KEY (created_by)
                REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$;

-- Trigger for updated_at maintenance
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.triggers
        WHERE event_object_schema = current_schema()
          AND event_object_table = 'payroll_adjustment_queue'
          AND trigger_name = 'trg_payadj_updated_at'
    ) THEN
        CREATE TRIGGER trg_payadj_updated_at
            BEFORE UPDATE ON payroll_adjustment_queue
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
    END IF;
END$$;

-- Helpful indexes for adjustment queue lookups
CREATE INDEX IF NOT EXISTS idx_payadj_employee_status
    ON payroll_adjustment_queue (employee_id, status);

CREATE INDEX IF NOT EXISTS idx_payadj_effective_period
    ON payroll_adjustment_queue (effective_period_start, effective_period_end, status);

-- Enrich payroll complaint workflow metadata
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'review_notes'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN review_notes TEXT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'reviewed_by'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN reviewed_by INT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'reviewed_at'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN reviewed_at TIMESTAMP WITHOUT TIME ZONE NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'resolution_by'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN resolution_by INT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'resolution_at'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN resolution_at TIMESTAMP WITHOUT TIME ZONE NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_amount'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_amount NUMERIC(12,2) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_type'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_type payslip_item_type NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_label'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_label VARCHAR(191) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_code'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_code VARCHAR(32) NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_notes'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_notes TEXT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_effective_start'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_effective_start DATE NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_effective_end'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_effective_end DATE NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'adjustment_queue_id'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN adjustment_queue_id INT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'confirmation_by'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN confirmation_by INT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'confirmation_at'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN confirmation_at TIMESTAMP WITHOUT TIME ZONE NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND column_name = 'confirmation_notes'
    ) THEN
        ALTER TABLE payroll_complaints ADD COLUMN confirmation_notes TEXT NULL;
    END IF;
END$$;

-- Backfill adjustment type for existing complaints
UPDATE payroll_complaints
   SET adjustment_type = COALESCE(adjustment_type, 'earning');

-- Add foreign keys linking complaints to users and adjustment queue
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND constraint_name = 'fk_paycomplaint_reviewed_by'
    ) THEN
        ALTER TABLE payroll_complaints
            ADD CONSTRAINT fk_paycomplaint_reviewed_by
                FOREIGN KEY (reviewed_by)
                REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND constraint_name = 'fk_paycomplaint_resolution_by'
    ) THEN
        ALTER TABLE payroll_complaints
            ADD CONSTRAINT fk_paycomplaint_resolution_by
                FOREIGN KEY (resolution_by)
                REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND constraint_name = 'fk_paycomplaint_confirmation_by'
    ) THEN
        ALTER TABLE payroll_complaints
            ADD CONSTRAINT fk_paycomplaint_confirmation_by
                FOREIGN KEY (confirmation_by)
                REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_complaints'
          AND constraint_name = 'fk_paycomplaint_adjust_queue'
    ) THEN
        ALTER TABLE payroll_complaints
            ADD CONSTRAINT fk_paycomplaint_adjust_queue
                FOREIGN KEY (adjustment_queue_id)
                REFERENCES payroll_adjustment_queue (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$;

-- Improve lookup performance on complaints by status and resolution stage
CREATE INDEX IF NOT EXISTS idx_paycomplaints_status ON payroll_complaints (status);
CREATE INDEX IF NOT EXISTS idx_paycomplaints_employee_status ON payroll_complaints (employee_id, status);

COMMIT;
