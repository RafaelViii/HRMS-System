-- Payroll release metadata additions
-- Date: 2025-10-18

BEGIN;

ALTER TABLE payroll_runs
    ADD COLUMN IF NOT EXISTS released_by INT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.table_constraints
         WHERE constraint_name = 'fk_payroll_runs_released_by'
           AND table_schema = current_schema()
           AND table_name = 'payroll_runs'
    ) THEN
        ALTER TABLE payroll_runs
            ADD CONSTRAINT fk_payroll_runs_released_by
                FOREIGN KEY (released_by)
                REFERENCES users (id)
                ON DELETE SET NULL
                ON UPDATE CASCADE;
    END IF;
END $$ LANGUAGE plpgsql;

COMMIT;
