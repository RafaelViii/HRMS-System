-- Migration: Database backup history tracking
-- Created: 2025-11-13
-- Description: Adds table to track database backup history

CREATE TABLE IF NOT EXISTS database_backup_history (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    backup_type VARCHAR(50) DEFAULT 'manual' CHECK (backup_type IN ('manual', 'scheduled', 'automated')),
    initiated_by INTEGER REFERENCES employees(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'completed' CHECK (status IN ('pending', 'completed', 'failed')),
    error_message TEXT NULL,
    backup_duration INTEGER NULL, -- seconds
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL
);

-- Add index for faster lookups
CREATE INDEX IF NOT EXISTS idx_backup_history_created_at ON database_backup_history(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_backup_history_initiated_by ON database_backup_history(initiated_by);
CREATE INDEX IF NOT EXISTS idx_backup_history_status ON database_backup_history(status);

-- Add comment
COMMENT ON TABLE database_backup_history IS 'Tracks all database backup operations with metadata and status';
