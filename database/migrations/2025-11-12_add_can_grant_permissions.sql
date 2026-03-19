-- Migration: Add can_grant_permissions flag to users table
-- Description: Adds a sensitive permission that only admin@hrms.local can set,
--              controlling whether a user can grant permissions above their own level
-- Date: 2025-11-12
-- Idempotent: Yes

-- Add the column if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'users' 
        AND column_name = 'can_grant_permissions'
    ) THEN
        ALTER TABLE users 
        ADD COLUMN can_grant_permissions BOOLEAN NOT NULL DEFAULT FALSE;
        
        RAISE NOTICE 'Added can_grant_permissions column to users table';
    ELSE
        RAISE NOTICE 'Column can_grant_permissions already exists in users table';
    END IF;
END $$;

-- Only the superadmin (user ID 1 - admin@hrms.local) should have this by default
UPDATE users 
SET can_grant_permissions = TRUE 
WHERE id = 1;

-- Add comment explaining the purpose
COMMENT ON COLUMN users.can_grant_permissions IS 
'Sensitive permission: Allows user to grant permissions equal to or above their own level. Only admin@hrms.local can modify this flag.';

-- Create audit trail for this sensitive permission
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM audit_log WHERE action = 'add_can_grant_permissions_column') THEN
        INSERT INTO audit_log (user_id, action, data, ip_address, created_at)
        VALUES (1, 'add_can_grant_permissions_column', 
                '{"migration": "2025-11-12_add_can_grant_permissions", "reason": "Added sensitive permission control"}',
                '127.0.0.1', CURRENT_TIMESTAMP);
    END IF;
END $$;
