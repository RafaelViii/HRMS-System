-- Migration: Leave Filing Policies
-- Date: 2025-11-19
-- Description: Add leave filing policy settings table with advance notice requirements per leave type
-- This allows HR admins to configure minimum advance notice days before leave can be filed

-- Create table for leave filing policy settings
CREATE TABLE IF NOT EXISTS leave_filing_policies (
    id SERIAL PRIMARY KEY,
    leave_type VARCHAR(50) NOT NULL,
    require_advance_notice BOOLEAN DEFAULT FALSE NOT NULL,
    advance_notice_days INTEGER DEFAULT 5 NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    notes TEXT,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    
    CONSTRAINT leave_filing_policies_leave_type_key UNIQUE (leave_type),
    CONSTRAINT chk_advance_notice_days CHECK (advance_notice_days >= 0 AND advance_notice_days <= 365)
);

-- Add trigger for updated_at
CREATE OR REPLACE FUNCTION trg_leave_filing_policies_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_leave_filing_policies_set_updated
BEFORE UPDATE ON leave_filing_policies
FOR EACH ROW
EXECUTE FUNCTION trg_leave_filing_policies_updated_at();

-- Add comments
COMMENT ON TABLE leave_filing_policies IS 'Leave filing policy configurations including advance notice requirements per leave type';
COMMENT ON COLUMN leave_filing_policies.leave_type IS 'Leave type code matching leave_type enum values';
COMMENT ON COLUMN leave_filing_policies.require_advance_notice IS 'When TRUE, enforce minimum advance notice days before leave start date';
COMMENT ON COLUMN leave_filing_policies.advance_notice_days IS 'Number of days in advance employee must file leave (default 5 days)';
COMMENT ON COLUMN leave_filing_policies.is_active IS 'Whether this policy is currently active';
COMMENT ON COLUMN leave_filing_policies.notes IS 'Optional notes or guidelines for this policy';

-- Insert default policies for common leave types (with notice requirement OFF by default)
INSERT INTO leave_filing_policies (leave_type, require_advance_notice, advance_notice_days, notes)
VALUES 
    ('vacation', FALSE, 5, 'Vacation leave - advance notice optional by default'),
    ('sick', FALSE, 0, 'Sick leave - no advance notice required (emergency)'),
    ('emergency', FALSE, 0, 'Emergency leave - no advance notice required'),
    ('maternity', FALSE, 14, 'Maternity leave - 14 days advance notice recommended'),
    ('paternity', FALSE, 7, 'Paternity leave - 7 days advance notice recommended'),
    ('unpaid', FALSE, 7, 'Unpaid leave - advance notice optional'),
    ('other', FALSE, 3, 'Other leave types - advance notice optional')
ON CONFLICT (leave_type) DO NOTHING;

-- Create index for faster lookups during leave request validation
CREATE INDEX IF NOT EXISTS idx_leave_filing_policies_active_type 
ON leave_filing_policies(leave_type, is_active) 
WHERE is_active = TRUE;
