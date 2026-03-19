-- Print Server: Office/Branch-based categorization & smart allocation
-- Adds branch_id to printers and a settings table for the toggle

-- 1. Add branch_id to printers table
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'printers' AND column_name = 'branch_id'
    ) THEN
        ALTER TABLE printers ADD COLUMN branch_id INTEGER REFERENCES branches(id) ON DELETE SET NULL;
        COMMENT ON COLUMN printers.branch_id IS 'Office/branch this printer is assigned to (NULL = shared/unassigned)';
    END IF;
END $$;

-- 2. Create print_server_settings table for feature toggles
CREATE TABLE IF NOT EXISTS print_server_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL DEFAULT '',
    description TEXT,
    updated_by INTEGER REFERENCES users(id),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 3. Insert default office allocation toggle (off by default)
INSERT INTO print_server_settings (setting_key, setting_value, description)
VALUES ('office_allocation_enabled', 'false', 'When enabled, print jobs are automatically routed to printers assigned to the user''s office/branch')
ON CONFLICT (setting_key) DO NOTHING;

-- 4. Index for branch_id lookups
CREATE INDEX IF NOT EXISTS idx_printers_branch_id ON printers(branch_id);
