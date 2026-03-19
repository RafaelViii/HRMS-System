-- Enhance audit_logs table for comprehensive tracking
-- Migration: 2025-11-13_enhance_audit_trail.sql

DO $$
BEGIN
    -- Add new columns for detailed tracking
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'ip_address') THEN
        ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45);
        COMMENT ON COLUMN audit_logs.ip_address IS 'IP address of the user performing the action';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'user_agent') THEN
        ALTER TABLE audit_logs ADD COLUMN user_agent TEXT;
        COMMENT ON COLUMN audit_logs.user_agent IS 'Browser/client user agent string';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'module') THEN
        ALTER TABLE audit_logs ADD COLUMN module VARCHAR(100);
        COMMENT ON COLUMN audit_logs.module IS 'Module/section where action occurred (e.g., payroll, employees, leave)';
        CREATE INDEX idx_audit_logs_module ON audit_logs(module);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'action_type') THEN
        ALTER TABLE audit_logs ADD COLUMN action_type VARCHAR(50);
        COMMENT ON COLUMN audit_logs.action_type IS 'Type of action (create, update, delete, view, approve, etc.)';
        CREATE INDEX idx_audit_logs_action_type ON audit_logs(action_type);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'target_type') THEN
        ALTER TABLE audit_logs ADD COLUMN target_type VARCHAR(100);
        COMMENT ON COLUMN audit_logs.target_type IS 'Type of entity affected (employee, position, department, payroll_run, etc.)';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'target_id') THEN
        ALTER TABLE audit_logs ADD COLUMN target_id INTEGER;
        COMMENT ON COLUMN audit_logs.target_id IS 'ID of the affected entity';
        CREATE INDEX idx_audit_logs_target ON audit_logs(target_type, target_id);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'old_values') THEN
        ALTER TABLE audit_logs ADD COLUMN old_values JSONB;
        COMMENT ON COLUMN audit_logs.old_values IS 'Previous values before the change (for update operations)';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'new_values') THEN
        ALTER TABLE audit_logs ADD COLUMN new_values JSONB;
        COMMENT ON COLUMN audit_logs.new_values IS 'New values after the change';
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'status') THEN
        ALTER TABLE audit_logs ADD COLUMN status VARCHAR(20) DEFAULT 'success';
        COMMENT ON COLUMN audit_logs.status IS 'Action status: success, failed, partial';
        CREATE INDEX idx_audit_logs_status ON audit_logs(status);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'employee_id') THEN
        ALTER TABLE audit_logs ADD COLUMN employee_id INTEGER REFERENCES employees(id);
        COMMENT ON COLUMN audit_logs.employee_id IS 'Employee ID if user is linked to an employee';
        CREATE INDEX idx_audit_logs_employee ON audit_logs(employee_id);
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'audit_logs' AND column_name = 'severity') THEN
        ALTER TABLE audit_logs ADD COLUMN severity VARCHAR(20) DEFAULT 'normal';
        COMMENT ON COLUMN audit_logs.severity IS 'Action severity: low, normal, high, critical';
        CREATE INDEX idx_audit_logs_severity ON audit_logs(severity);
    END IF;

    -- Add index on created_at for faster date queries
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'audit_logs' AND indexname = 'idx_audit_logs_created_at') THEN
        CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);
    END IF;

    -- Add index on user_id for faster user queries
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'audit_logs' AND indexname = 'idx_audit_logs_user_id') THEN
        CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
    END IF;

    -- Add composite index for common queries
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'audit_logs' AND indexname = 'idx_audit_logs_user_date') THEN
        CREATE INDEX idx_audit_logs_user_date ON audit_logs(user_id, created_at DESC);
    END IF;

    RAISE NOTICE 'Enhanced audit_logs table successfully';

    -- Create audit_trail_view for easier querying
    CREATE OR REPLACE VIEW audit_trail_view AS
    SELECT 
        al.id,
        al.created_at,
        al.action,
        al.action_type,
        al.module,
        al.details,
        al.status,
        al.severity,
        al.target_type,
        al.target_id,
        al.old_values,
        al.new_values,
        al.ip_address,
        al.user_agent,
        u.id AS user_id,
        u.full_name AS user_full_name,
        u.email AS user_email,
        u.role AS user_role,
        e.id AS employee_id,
        e.employee_code,
        e.first_name,
        e.last_name,
        e.department_id,
        d.name AS department_name,
        p.id AS position_id,
        p.name AS position_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN employees e ON e.id = al.employee_id OR e.user_id = al.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN positions p ON p.id = e.position_id
    ORDER BY al.created_at DESC;

    RAISE NOTICE 'Created audit_trail_view successfully';

    -- Add audit log entry
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'audit_trail') THEN
        INSERT INTO audit_trail (action, details, created_at)
        VALUES (
            'enhance_audit_logs_table',
            '{"migration": "2025-11-13_enhance_audit_trail.sql", "description": "Enhanced audit_logs table with comprehensive tracking fields"}',
            CURRENT_TIMESTAMP
        );
    END IF;

END $$;
