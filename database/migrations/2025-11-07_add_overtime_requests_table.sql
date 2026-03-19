-- Migration: Add overtime_requests table
-- Created: 2025-11-07
-- Purpose: Employee overtime tracking with HR approval interface

-- Idempotency check
DO $$ BEGIN

-- Create overtime_requests table if it doesn't exist
IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'overtime_requests') THEN
  CREATE TABLE overtime_requests (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id INT NOT NULL,
    overtime_date DATE NOT NULL,
    hours NUMERIC(5,2) NOT NULL CHECK (hours > 0 AND hours <= 24),
    reason TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'paid')),
    approved_by INT NULL,
    approved_at TIMESTAMP WITHOUT TIME ZONE NULL,
    rejection_reason TEXT NULL,
    included_in_payroll_run_id INT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_overtime_employee FOREIGN KEY (employee_id)
      REFERENCES employees (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_approver FOREIGN KEY (approved_by)
      REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_payroll_run FOREIGN KEY (included_in_payroll_run_id)
      REFERENCES payroll_runs (id) ON DELETE SET NULL ON UPDATE CASCADE
  );

  -- Create indexes
  CREATE INDEX IF NOT EXISTS idx_overtime_employee ON overtime_requests(employee_id);
  CREATE INDEX IF NOT EXISTS idx_overtime_status ON overtime_requests(status);
  CREATE INDEX IF NOT EXISTS idx_overtime_date ON overtime_requests(overtime_date);
  CREATE INDEX IF NOT EXISTS idx_overtime_approver ON overtime_requests(approved_by);

  -- Create trigger for updated_at
  CREATE TRIGGER trg_overtime_requests_updated_at
    BEFORE UPDATE ON overtime_requests
    FOR EACH ROW
    EXECUTE FUNCTION set_updated_at();

  RAISE NOTICE 'Created overtime_requests table with indexes and triggers';
END IF;

END $$;
