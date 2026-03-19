-- Custom Payroll Settings Module
-- Individual-level configurations for salaries, benefits, leaves, and deductions
-- Supports system-wide, branch, department, and employee-level overrides

-- Employee Custom Compensation (Salaries and Rates)
CREATE TABLE IF NOT EXISTS employee_custom_compensation (
    id SERIAL PRIMARY KEY,
    -- Target level
    config_level VARCHAR(20) NOT NULL CHECK (config_level IN ('system', 'branch', 'department', 'employee')),
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    employee_id INT REFERENCES employees(id) ON DELETE CASCADE,
    -- Compensation fields
    base_salary DECIMAL(12,2),
    hourly_rate DECIMAL(10,2),
    daily_rate DECIMAL(10,2),
    overtime_rate_multiplier DECIMAL(5,2) DEFAULT 1.5,
    holiday_rate_multiplier DECIMAL(5,2) DEFAULT 2.0,
    night_differential_rate DECIMAL(10,2),
    -- Currency
    currency VARCHAR(3) DEFAULT 'PHP',
    -- Payment frequency
    payment_frequency VARCHAR(20) DEFAULT 'monthly' CHECK (payment_frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'annually')),
    -- Effective period
    effective_from DATE NOT NULL,
    effective_to DATE,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    notes TEXT,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employee Custom Benefits and Allowances
CREATE TABLE IF NOT EXISTS employee_custom_benefits (
    id SERIAL PRIMARY KEY,
    -- Target level
    config_level VARCHAR(20) NOT NULL CHECK (config_level IN ('system', 'branch', 'department', 'employee')),
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    employee_id INT REFERENCES employees(id) ON DELETE CASCADE,
    -- Benefit details
    benefit_type VARCHAR(50) NOT NULL, -- 'allowance', 'bonus', 'incentive', 'cola', 'travel', 'meal', 'phone', 'housing', etc.
    benefit_name VARCHAR(100) NOT NULL,
    benefit_amount DECIMAL(12,2) NOT NULL,
    -- Calculation method
    calculation_method VARCHAR(20) DEFAULT 'fixed' CHECK (calculation_method IN ('fixed', 'percentage', 'formula')),
    percentage_base VARCHAR(30), -- 'base_salary', 'gross_pay', etc.
    percentage_value DECIMAL(5,2),
    -- Frequency
    frequency VARCHAR(20) DEFAULT 'monthly' CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually', 'onetime')),
    -- Taxability
    is_taxable BOOLEAN DEFAULT true,
    is_pensionable BOOLEAN DEFAULT false,
    -- Effective period
    effective_from DATE NOT NULL,
    effective_to DATE,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    notes TEXT,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employee Custom Leave Entitlements
CREATE TABLE IF NOT EXISTS employee_custom_leave_entitlements (
    id SERIAL PRIMARY KEY,
    -- Target level
    config_level VARCHAR(20) NOT NULL CHECK (config_level IN ('system', 'branch', 'department', 'employee')),
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    employee_id INT REFERENCES employees(id) ON DELETE CASCADE,
    -- Leave details
    leave_type VARCHAR(50) NOT NULL, -- 'vacation', 'sick', 'emergency', 'maternity', 'paternity', 'bereavement', etc.
    leave_name VARCHAR(100) NOT NULL,
    -- Entitlement
    days_per_year DECIMAL(5,2) NOT NULL,
    hours_per_year DECIMAL(7,2),
    -- Accrual settings
    accrual_method VARCHAR(20) DEFAULT 'annual' CHECK (accrual_method IN ('annual', 'monthly', 'biweekly', 'per_pay_period')),
    accrual_rate DECIMAL(5,4), -- Days/hours accrued per period
    -- Carry over rules
    allow_carryover BOOLEAN DEFAULT false,
    max_carryover_days DECIMAL(5,2),
    carryover_expires_months INT,
    -- Encashment
    allow_encashment BOOLEAN DEFAULT false,
    encashment_rate DECIMAL(5,2) DEFAULT 100.00, -- Percentage of salary
    -- Effective period
    effective_from DATE NOT NULL,
    effective_to DATE,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    notes TEXT,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employee Custom Deductions
CREATE TABLE IF NOT EXISTS employee_custom_deductions (
    id SERIAL PRIMARY KEY,
    -- Target level
    config_level VARCHAR(20) NOT NULL CHECK (config_level IN ('system', 'branch', 'department', 'employee')),
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    employee_id INT REFERENCES employees(id) ON DELETE CASCADE,
    -- Deduction details
    deduction_type VARCHAR(50) NOT NULL, -- 'tax', 'sss', 'philhealth', 'pagibig', 'loan', 'advance', 'insurance', 'union_dues', etc.
    deduction_name VARCHAR(100) NOT NULL,
    deduction_amount DECIMAL(12,2) NOT NULL,
    -- Calculation method
    calculation_method VARCHAR(20) DEFAULT 'fixed' CHECK (calculation_method IN ('fixed', 'percentage', 'formula', 'table')),
    percentage_base VARCHAR(30), -- 'basic_pay', 'gross_pay', 'taxable_income', etc.
    percentage_value DECIMAL(5,2),
    -- Frequency
    frequency VARCHAR(20) DEFAULT 'monthly' CHECK (frequency IN ('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually', 'onetime')),
    -- Priority (order of deduction)
    priority INT DEFAULT 1,
    -- Loan-specific fields
    loan_principal DECIMAL(12,2),
    loan_balance DECIMAL(12,2),
    installment_count INT,
    installments_remaining INT,
    -- Effective period
    effective_from DATE NOT NULL,
    effective_to DATE,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    notes TEXT,
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_employee_custom_compensation_level ON employee_custom_compensation(config_level);
CREATE INDEX IF NOT EXISTS idx_employee_custom_compensation_branch ON employee_custom_compensation(branch_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_compensation_dept ON employee_custom_compensation(department_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_compensation_employee ON employee_custom_compensation(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_compensation_dates ON employee_custom_compensation(effective_from, effective_to);

CREATE INDEX IF NOT EXISTS idx_employee_custom_benefits_level ON employee_custom_benefits(config_level);
CREATE INDEX IF NOT EXISTS idx_employee_custom_benefits_employee ON employee_custom_benefits(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_benefits_type ON employee_custom_benefits(benefit_type);
CREATE INDEX IF NOT EXISTS idx_employee_custom_benefits_dates ON employee_custom_benefits(effective_from, effective_to);

CREATE INDEX IF NOT EXISTS idx_employee_custom_leave_level ON employee_custom_leave_entitlements(config_level);
CREATE INDEX IF NOT EXISTS idx_employee_custom_leave_employee ON employee_custom_leave_entitlements(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_leave_type ON employee_custom_leave_entitlements(leave_type);
CREATE INDEX IF NOT EXISTS idx_employee_custom_leave_dates ON employee_custom_leave_entitlements(effective_from, effective_to);

CREATE INDEX IF NOT EXISTS idx_employee_custom_deductions_level ON employee_custom_deductions(config_level);
CREATE INDEX IF NOT EXISTS idx_employee_custom_deductions_employee ON employee_custom_deductions(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_custom_deductions_type ON employee_custom_deductions(deduction_type);
CREATE INDEX IF NOT EXISTS idx_employee_custom_deductions_dates ON employee_custom_deductions(effective_from, effective_to);

-- Comments
COMMENT ON TABLE employee_custom_compensation IS 'Custom salary and compensation rates at system, branch, department, or employee level';
COMMENT ON TABLE employee_custom_benefits IS 'Custom benefits and allowances with flexible calculation methods and frequencies';
COMMENT ON TABLE employee_custom_leave_entitlements IS 'Custom leave entitlements with accrual, carryover, and encashment rules';
COMMENT ON TABLE employee_custom_deductions IS 'Custom deductions including taxes, government contributions, loans, and other withholdings';
