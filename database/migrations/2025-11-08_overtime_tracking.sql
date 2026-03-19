-- ================================================================
-- Overtime Tracking for Employee Time Management
-- Created: 2025-11-08
-- Purpose: Track overtime hours worked by employees with approval workflow
-- ================================================================

CREATE TABLE IF NOT EXISTS overtime_requests (
    id BIGSERIAL PRIMARY KEY,
    employee_id INT NOT NULL,
    
    -- Overtime details
    overtime_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    hours_worked NUMERIC(5,2) NOT NULL, -- Calculated hours (can be fractional, e.g., 2.5)
    overtime_type VARCHAR(50) DEFAULT 'regular', -- regular, holiday, restday
    
    -- Approval workflow
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, rejected
    approved_by INT NULL,
    approved_at TIMESTAMP WITHOUT TIME ZONE NULL,
    rejection_reason TEXT NULL,
    
    -- Context
    reason TEXT NULL, -- Why overtime was needed
    work_description TEXT NULL, -- What work was done
    notes TEXT NULL, -- Additional notes from employee
    
    -- Payroll integration
    included_in_payroll_run_id INT NULL, -- Links to payroll_runs when computed
    is_paid BOOLEAN NOT NULL DEFAULT FALSE,
    
    -- Audit
    created_by INT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_overtime_employee FOREIGN KEY (employee_id)
        REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_approver FOREIGN KEY (approved_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_payroll_run FOREIGN KEY (included_in_payroll_run_id)
        REFERENCES payroll_runs(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_overtime_status CHECK (status IN ('pending', 'approved', 'rejected')),
    CONSTRAINT chk_overtime_type CHECK (overtime_type IN ('regular', 'holiday', 'restday')),
    CONSTRAINT chk_overtime_hours CHECK (hours_worked > 0 AND hours_worked <= 24),
    CONSTRAINT chk_overtime_time_order CHECK (end_time > start_time OR (end_time < start_time AND hours_worked > 0))
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_overtime_employee ON overtime_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_overtime_status ON overtime_requests(status);
CREATE INDEX IF NOT EXISTS idx_overtime_date ON overtime_requests(overtime_date DESC);
CREATE INDEX IF NOT EXISTS idx_overtime_approver ON overtime_requests(approved_by);
CREATE INDEX IF NOT EXISTS idx_overtime_payroll_run ON overtime_requests(included_in_payroll_run_id);
CREATE INDEX IF NOT EXISTS idx_overtime_employee_status ON overtime_requests(employee_id, status);
CREATE INDEX IF NOT EXISTS idx_overtime_employee_date ON overtime_requests(employee_id, overtime_date DESC);

-- Trigger to update updated_at timestamp
CREATE OR REPLACE FUNCTION fn_overtime_set_updated()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_overtime_set_updated ON overtime_requests;
CREATE TRIGGER trg_overtime_set_updated
BEFORE UPDATE ON overtime_requests
FOR EACH ROW
EXECUTE FUNCTION fn_overtime_set_updated();

-- Comments for documentation
COMMENT ON TABLE overtime_requests IS 'Tracks employee overtime hours with approval workflow and payroll integration';
COMMENT ON COLUMN overtime_requests.hours_worked IS 'Decimal hours worked (e.g., 2.5 for 2 hours 30 minutes)';
COMMENT ON COLUMN overtime_requests.overtime_type IS 'Type of overtime: regular (weekday), holiday, or restday (weekend)';
COMMENT ON COLUMN overtime_requests.status IS 'Approval status: pending (awaiting approval), approved (authorized for payroll), rejected (not authorized)';
COMMENT ON COLUMN overtime_requests.included_in_payroll_run_id IS 'Links to payroll run when overtime is computed into pay';
COMMENT ON COLUMN overtime_requests.is_paid IS 'Marks overtime as paid to prevent double-payment';
