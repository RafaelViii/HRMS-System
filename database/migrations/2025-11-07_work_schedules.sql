-- Custom Work Schedule Module
-- Supports system-wide, branch, department, and employee-level custom work schedules

-- Work Schedule Templates (Morning Shift, Night Shift, etc.)
CREATE TABLE IF NOT EXISTS work_schedule_templates (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    -- Shift times
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    -- Break configuration
    break_duration_minutes INT DEFAULT 0,
    break_start_time TIME,
    -- Days of week (JSON array: [1,2,3,4,5] for Mon-Fri)
    work_days JSONB DEFAULT '[]'::jsonb,
    -- Flexible hours per week
    hours_per_week DECIMAL(5,2),
    -- Template type: 'system' (global default), 'branch', 'department', 'custom'
    template_type VARCHAR(20) DEFAULT 'custom',
    -- Configuration level (for hierarchical override)
    config_level VARCHAR(20) DEFAULT 'system' CHECK (config_level IN ('system', 'branch', 'department', 'employee')),
    -- Reference IDs for scoped templates
    branch_id INT REFERENCES branches(id) ON DELETE CASCADE,
    department_id INT REFERENCES departments(id) ON DELETE CASCADE,
    -- Status
    is_active BOOLEAN DEFAULT true,
    -- Metadata
    created_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Employee Work Schedule Assignments
CREATE TABLE IF NOT EXISTS employee_work_schedules (
    id SERIAL PRIMARY KEY,
    employee_id INT NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
    schedule_template_id INT REFERENCES work_schedule_templates(id) ON DELETE SET NULL,
    -- Custom override fields (if not using template)
    custom_start_time TIME,
    custom_end_time TIME,
    custom_break_minutes INT,
    custom_work_days JSONB,
    custom_hours_per_week DECIMAL(5,2),
    -- Effective date range
    effective_from DATE NOT NULL,
    effective_to DATE,
    -- Priority for conflict resolution (higher = more specific)
    priority INT DEFAULT 1,
    -- Notes
    notes TEXT,
    -- Metadata
    assigned_by INT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(employee_id, effective_from, effective_to)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_work_schedule_templates_type ON work_schedule_templates(template_type);
CREATE INDEX IF NOT EXISTS idx_work_schedule_templates_level ON work_schedule_templates(config_level);
CREATE INDEX IF NOT EXISTS idx_work_schedule_templates_branch ON work_schedule_templates(branch_id);
CREATE INDEX IF NOT EXISTS idx_work_schedule_templates_dept ON work_schedule_templates(department_id);
CREATE INDEX IF NOT EXISTS idx_work_schedule_templates_active ON work_schedule_templates(is_active);

CREATE INDEX IF NOT EXISTS idx_employee_work_schedules_employee ON employee_work_schedules(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_work_schedules_template ON employee_work_schedules(schedule_template_id);
CREATE INDEX IF NOT EXISTS idx_employee_work_schedules_dates ON employee_work_schedules(effective_from, effective_to);

-- Comments
COMMENT ON TABLE work_schedule_templates IS 'Predefined work schedule templates (shifts) that can be assigned at system, branch, department, or employee level';
COMMENT ON TABLE employee_work_schedules IS 'Work schedule assignments for employees with support for custom overrides and date-based effectiveness';
COMMENT ON COLUMN work_schedule_templates.config_level IS 'Hierarchy level: system (global default), branch, department, or employee-specific';
COMMENT ON COLUMN work_schedule_templates.work_days IS 'JSON array of weekday numbers (1=Monday, 7=Sunday)';
COMMENT ON COLUMN employee_work_schedules.priority IS 'Higher priority overrides lower when multiple schedules overlap';
