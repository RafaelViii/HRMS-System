-- Cutoff Period Presets Module
-- Predefined templates for payroll cutoff periods that can be reused

CREATE TABLE IF NOT EXISTS payroll_cutoff_presets (
    id SERIAL PRIMARY KEY,
    -- Preset details
    preset_name VARCHAR(100) NOT NULL,
    description TEXT,
    -- Cutoff configuration
    cutoff_type VARCHAR(20) NOT NULL CHECK (cutoff_type IN ('semi_monthly', 'monthly', 'weekly', 'biweekly', 'custom')),
    -- Semi-monthly cutoffs (e.g., 1-15, 16-end)
    first_cutoff_start_day INT, -- Day of month for first cutoff start (1-31)
    first_cutoff_end_day INT,   -- Day of month for first cutoff end (1-31)
    second_cutoff_start_day INT, -- Day of month for second cutoff start (1-31)
    second_cutoff_end_day INT,   -- Day of month for second cutoff end (1-31 or 0 for month end)
    -- Weekly/Biweekly (day of week: 1=Monday, 7=Sunday)
    week_start_day INT,
    week_end_day INT,
    -- Payment schedule offset (days after cutoff end)
    payment_offset_days INT DEFAULT 0,
    -- Grace period for attendance/corrections (days after cutoff end)
    grace_period_days INT DEFAULT 0,
    -- Branch/Department specific
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    -- Default flag (one per cutoff_type can be default)
    is_default BOOLEAN DEFAULT false,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Ensure unique preset names
    UNIQUE(preset_name)
);

-- Link cutoff presets to payroll periods (for tracking which preset was used)
CREATE TABLE IF NOT EXISTS payroll_period_preset_links (
    id SERIAL PRIMARY KEY,
    payroll_period_id INT NOT NULL REFERENCES payroll_periods(id) ON DELETE CASCADE,
    cutoff_preset_id INT REFERENCES payroll_cutoff_presets(id) ON DELETE SET NULL,
    -- Store snapshot of preset at time of use
    preset_snapshot JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(payroll_period_id)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_payroll_cutoff_presets_type ON payroll_cutoff_presets(cutoff_type);
CREATE INDEX IF NOT EXISTS idx_payroll_cutoff_presets_branch ON payroll_cutoff_presets(branch_id);
CREATE INDEX IF NOT EXISTS idx_payroll_cutoff_presets_dept ON payroll_cutoff_presets(department_id);
CREATE INDEX IF NOT EXISTS idx_payroll_cutoff_presets_active ON payroll_cutoff_presets(is_active);
CREATE INDEX IF NOT EXISTS idx_payroll_cutoff_presets_default ON payroll_cutoff_presets(is_default);

CREATE INDEX IF NOT EXISTS idx_payroll_period_preset_links_period ON payroll_period_preset_links(payroll_period_id);
CREATE INDEX IF NOT EXISTS idx_payroll_period_preset_links_preset ON payroll_period_preset_links(cutoff_preset_id);

-- Comments
COMMENT ON TABLE payroll_cutoff_presets IS 'Reusable cutoff period templates for payroll processing (e.g., 1st-15th, 16th-30th)';
COMMENT ON TABLE payroll_period_preset_links IS 'Links payroll periods to the cutoff presets used, with snapshot for audit trail';
COMMENT ON COLUMN payroll_cutoff_presets.cutoff_type IS 'Type of cutoff: semi_monthly (twice per month), monthly, weekly, biweekly, or custom';
COMMENT ON COLUMN payroll_cutoff_presets.second_cutoff_end_day IS 'Day of month for second cutoff end; use 0 to represent last day of month';
COMMENT ON COLUMN payroll_cutoff_presets.payment_offset_days IS 'Number of days after cutoff end when payment is scheduled';
COMMENT ON COLUMN payroll_cutoff_presets.grace_period_days IS 'Days after cutoff for attendance corrections and adjustments';

-- Insert common presets
INSERT INTO payroll_cutoff_presets (preset_name, description, cutoff_type, first_cutoff_start_day, first_cutoff_end_day, second_cutoff_start_day, second_cutoff_end_day, payment_offset_days, is_default, is_active)
VALUES 
    ('Standard Semi-Monthly (1-15, 16-End)', 'Standard semi-monthly cutoff with two pay periods per month', 'semi_monthly', 1, 15, 16, 0, 5, true, true),
    ('Semi-Monthly (1-10, 11-End)', 'Alternative semi-monthly cutoff ending on 10th and month end', 'semi_monthly', 1, 10, 11, 0, 5, false, true),
    ('Semi-Monthly (1-14, 15-End)', 'Semi-monthly cutoff ending on 14th and month end', 'semi_monthly', 1, 14, 15, 0, 5, false, true),
    ('Monthly (1-End)', 'Single monthly payroll period covering entire month', 'monthly', 1, 0, NULL, NULL, 5, false, true)
ON CONFLICT (preset_name) DO NOTHING;
