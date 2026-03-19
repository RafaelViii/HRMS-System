-- Migration: Add archive system support
-- Created: 2025-01-09
-- Description: Adds system_settings table for archive configuration and deleted_at columns for soft-delete support

-- Create system_settings table for archive configuration
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER REFERENCES employees(id)
);

-- Insert default archive settings
INSERT INTO system_settings (setting_key, setting_value, updated_at)
VALUES 
    ('archive_enabled', '1', CURRENT_TIMESTAMP),
    ('archive_auto_delete_days', '90', CURRENT_TIMESTAMP)
ON CONFLICT (setting_key) DO NOTHING;

-- Add deleted_at and deleted_by columns to tables that don't have them
-- Employees
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'employees' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE employees 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_employees_deleted_at ON employees(deleted_at);
    END IF;
END $$;

-- Departments
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'departments' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE departments 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_departments_deleted_at ON departments(deleted_at);
    END IF;
END $$;

-- Positions
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'positions' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE positions 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_positions_deleted_at ON positions(deleted_at);
    END IF;
END $$;

-- Leaves
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'leaves' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE leaves 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_leaves_deleted_at ON leaves(deleted_at);
    END IF;
END $$;

-- Memos
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'memos' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE memos 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_memos_deleted_at ON memos(deleted_at);
    END IF;
END $$;

-- Documents
DO $$ 
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'documents' AND column_name = 'deleted_at'
    ) THEN
        ALTER TABLE documents 
        ADD COLUMN deleted_at TIMESTAMP NULL,
        ADD COLUMN deleted_by INTEGER REFERENCES employees(id);
        
        CREATE INDEX IF NOT EXISTS idx_documents_deleted_at ON documents(deleted_at);
    END IF;
END $$;

-- Add comment to document the archive system
COMMENT ON TABLE system_settings IS 'System-wide configuration settings including archive retention policies';
COMMENT ON COLUMN employees.deleted_at IS 'Soft delete timestamp - NULL means active, non-NULL means archived';
COMMENT ON COLUMN employees.deleted_by IS 'Employee ID who archived this record';

-- Create a helper function to archive records (soft delete)
CREATE OR REPLACE FUNCTION archive_record(
    p_table_name TEXT,
    p_record_id INTEGER,
    p_deleted_by INTEGER
) RETURNS BOOLEAN AS $$
DECLARE
    v_sql TEXT;
    v_result BOOLEAN;
BEGIN
    -- Validate table name to prevent SQL injection
    IF p_table_name NOT IN ('employees', 'departments', 'positions', 'leaves', 'memos', 'documents') THEN
        RAISE EXCEPTION 'Invalid table name: %', p_table_name;
    END IF;
    
    -- Build and execute dynamic SQL
    v_sql := format(
        'UPDATE %I SET deleted_at = CURRENT_TIMESTAMP, deleted_by = $1 WHERE id = $2 AND deleted_at IS NULL',
        p_table_name
    );
    
    EXECUTE v_sql USING p_deleted_by, p_record_id;
    
    GET DIAGNOSTICS v_result = ROW_COUNT;
    RETURN v_result > 0;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION archive_record IS 'Safely archive (soft delete) a record from supported tables';

-- Create automated cleanup job function (to be called by cron/scheduler)
CREATE OR REPLACE FUNCTION cleanup_old_archives() RETURNS TABLE(
    table_name TEXT,
    records_deleted INTEGER
) AS $$
DECLARE
    v_auto_delete_days INTEGER;
    v_cutoff_date TIMESTAMP;
    v_deleted_count INTEGER;
    v_table TEXT;
BEGIN
    -- Get auto-delete setting
    SELECT setting_value::INTEGER INTO v_auto_delete_days
    FROM system_settings
    WHERE setting_key = 'archive_auto_delete_days';
    
    -- If 0 or NULL, don't delete anything
    IF v_auto_delete_days IS NULL OR v_auto_delete_days = 0 THEN
        RETURN;
    END IF;
    
    v_cutoff_date := CURRENT_TIMESTAMP - (v_auto_delete_days || ' days')::INTERVAL;
    
    -- Loop through each table and delete old archives
    FOR v_table IN 
        SELECT unnest(ARRAY['employees', 'departments', 'positions', 'leaves', 'memos', 'documents'])
    LOOP
        EXECUTE format(
            'DELETE FROM %I WHERE deleted_at IS NOT NULL AND deleted_at < $1',
            v_table
        ) USING v_cutoff_date;
        
        GET DIAGNOSTICS v_deleted_count = ROW_COUNT;
        
        IF v_deleted_count > 0 THEN
            table_name := v_table;
            records_deleted := v_deleted_count;
            RETURN NEXT;
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

COMMENT ON FUNCTION cleanup_old_archives IS 'Automatically delete archived records older than configured retention period';
