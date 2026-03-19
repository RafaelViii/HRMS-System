-- Create cutoff_periods table for payroll cycle management
-- Migration: 2025-11-13_create_cutoff_periods.sql

DO $$
BEGIN
    -- Create cutoff_periods table if not exists
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'cutoff_periods') THEN
        CREATE TABLE cutoff_periods (
            id SERIAL PRIMARY KEY,
            period_name VARCHAR(100) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            cutoff_date DATE NOT NULL,
            pay_date DATE,
            status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'closed', 'cancelled')),
            is_locked BOOLEAN DEFAULT FALSE,
            notes TEXT,
            created_by INTEGER REFERENCES users(id),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT cutoff_date_range_check CHECK (start_date <= end_date),
            CONSTRAINT cutoff_date_within_period CHECK (cutoff_date >= end_date),
            UNIQUE (start_date, end_date)
        );

        -- Create index on dates for faster lookups
        CREATE INDEX idx_cutoff_periods_dates ON cutoff_periods(start_date, end_date);
        CREATE INDEX idx_cutoff_periods_status ON cutoff_periods(status);

        -- Add comment
        COMMENT ON TABLE cutoff_periods IS 'Defines payroll cutoff periods for attendance and payroll calculation';
        COMMENT ON COLUMN cutoff_periods.period_name IS 'Human-readable name for the period (e.g., "October 16-31, 2025")';
        COMMENT ON COLUMN cutoff_periods.start_date IS 'First day of the work period';
        COMMENT ON COLUMN cutoff_periods.end_date IS 'Last day of the work period';
        COMMENT ON COLUMN cutoff_periods.cutoff_date IS 'Deadline for attendance corrections and submissions';
        COMMENT ON COLUMN cutoff_periods.pay_date IS 'Expected or actual payroll release date';
        COMMENT ON COLUMN cutoff_periods.is_locked IS 'When true, prevents modifications to attendance within this period';

        RAISE NOTICE 'Created cutoff_periods table successfully';
    ELSE
        RAISE NOTICE 'cutoff_periods table already exists, skipping';
    END IF;

    -- Add audit log entry
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'audit_trail') THEN
        INSERT INTO audit_trail (action, details, created_at)
        VALUES (
            'create_cutoff_periods_table',
            '{"migration": "2025-11-13_create_cutoff_periods.sql", "description": "Created payroll cutoff periods management table"}',
            CURRENT_TIMESTAMP
        );
    END IF;

END $$;
