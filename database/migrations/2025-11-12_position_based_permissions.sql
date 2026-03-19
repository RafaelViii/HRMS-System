-- Migration: Position-Based Access Control System
-- Date: 2025-11-12
-- Description: Replace role-based access with position-based permissions
--              This allows fine-grained control over what each position can access

-- ============================================================================
-- STEP 1: Add is_system_admin flag to users table
-- ============================================================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'users' 
        AND column_name = 'is_system_admin'
    ) THEN
        ALTER TABLE users ADD COLUMN is_system_admin BOOLEAN DEFAULT FALSE NOT NULL;
        COMMENT ON COLUMN users.is_system_admin IS 'System administrators bypass all permission checks';
    END IF;
END $$;

-- ============================================================================
-- STEP 2: Create position_access_permissions table
-- ============================================================================
CREATE TABLE IF NOT EXISTS position_access_permissions (
    id BIGSERIAL PRIMARY KEY,
    position_id INTEGER NOT NULL,
    domain VARCHAR(100) NOT NULL,
    resource_key VARCHAR(100) NOT NULL,
    access_level VARCHAR(20) NOT NULL,
    allow_override BOOLEAN DEFAULT FALSE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    
    -- Foreign key to positions
    CONSTRAINT fk_position_perms_position 
        FOREIGN KEY (position_id) 
        REFERENCES positions(id) 
        ON UPDATE CASCADE 
        ON DELETE CASCADE,
    
    -- Ensure valid access levels
    CONSTRAINT chk_position_perms_access_level 
        CHECK (access_level IN ('none', 'read', 'write', 'manage')),
    
    -- Prevent duplicate permissions for same position + domain + resource
    CONSTRAINT uq_position_perms_unique 
        UNIQUE (position_id, domain, resource_key)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_position_perms_position_id 
    ON position_access_permissions(position_id);
    
CREATE INDEX IF NOT EXISTS idx_position_perms_domain 
    ON position_access_permissions(domain);
    
CREATE INDEX IF NOT EXISTS idx_position_perms_lookup 
    ON position_access_permissions(position_id, domain, resource_key);

-- Comments
COMMENT ON TABLE position_access_permissions IS 
    'Defines access permissions for each position across system domains and resources';
COMMENT ON COLUMN position_access_permissions.domain IS 
    'Permission domain (e.g., system, hr_core, payroll, leave)';
COMMENT ON COLUMN position_access_permissions.resource_key IS 
    'Specific resource within domain (e.g., employees, payroll_runs)';
COMMENT ON COLUMN position_access_permissions.access_level IS 
    'Access level: none, read, write, manage (hierarchical)';
COMMENT ON COLUMN position_access_permissions.allow_override IS 
    'Whether this position can use admin override for this resource';

-- ============================================================================
-- STEP 3: Create audit trigger for permission changes
-- ============================================================================
CREATE OR REPLACE FUNCTION trg_position_perms_updated()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_position_perms_set_updated ON position_access_permissions;
CREATE TRIGGER trg_position_perms_set_updated
    BEFORE UPDATE ON position_access_permissions
    FOR EACH ROW
    EXECUTE FUNCTION trg_position_perms_updated();

-- ============================================================================
-- STEP 4: Create System Administrator position (if not exists)
-- ============================================================================
DO $$
DECLARE
    v_sysadmin_position_id INTEGER;
    v_default_dept_id INTEGER;
BEGIN
    -- Get or create a default department for system positions
    SELECT id INTO v_default_dept_id 
    FROM departments 
    WHERE LOWER(name) = 'administration' 
    LIMIT 1;
    
    IF v_default_dept_id IS NULL THEN
        INSERT INTO departments (name, description)
        VALUES ('Administration', 'System administration and management')
        RETURNING id INTO v_default_dept_id;
    END IF;
    
    -- Create System Administrator position if it doesn't exist
    SELECT id INTO v_sysadmin_position_id
    FROM positions
    WHERE LOWER(name) = 'system administrator'
    LIMIT 1;
    
    IF v_sysadmin_position_id IS NULL THEN
        INSERT INTO positions (name, description, department_id, base_salary)
        VALUES (
            'System Administrator',
            'Full system access - manages all aspects of the HRMS',
            v_default_dept_id,
            0
        )
        RETURNING id INTO v_sysadmin_position_id;
        
        -- Grant full manage access to all domains
        -- We'll populate this with a seed script, but create the position now
        RAISE NOTICE 'Created System Administrator position with ID: %', v_sysadmin_position_id;
    END IF;
END $$;

-- ============================================================================
-- STEP 5: Set is_system_admin flag for existing admin users
-- ============================================================================
-- Mark users with 'admin' role as system administrators
UPDATE users 
SET is_system_admin = TRUE 
WHERE role = 'admin' 
AND is_system_admin = FALSE;

-- ============================================================================
-- STEP 6: Create helper view for user permissions lookup
-- ============================================================================
CREATE OR REPLACE VIEW v_user_position_permissions AS
SELECT 
    u.id as user_id,
    u.email,
    u.full_name,
    u.is_system_admin,
    e.id as employee_id,
    e.position_id,
    p.name as position_name,
    pap.domain,
    pap.resource_key,
    pap.access_level,
    pap.allow_override
FROM users u
LEFT JOIN employees e ON e.user_id = u.id AND e.status = 'active'
LEFT JOIN positions p ON p.id = e.position_id
LEFT JOIN position_access_permissions pap ON pap.position_id = p.id
WHERE u.status = 'active';

COMMENT ON VIEW v_user_position_permissions IS 
    'Convenient view joining users with their position-based permissions';

-- ============================================================================
-- STEP 7: Create function to quickly check user access
-- ============================================================================
CREATE OR REPLACE FUNCTION check_user_access(
    p_user_id INTEGER,
    p_domain VARCHAR(100),
    p_resource_key VARCHAR(100),
    p_required_level VARCHAR(20) DEFAULT 'read'
)
RETURNS BOOLEAN AS $$
DECLARE
    v_is_sysadmin BOOLEAN;
    v_user_level VARCHAR(20);
    v_level_ranks JSONB;
BEGIN
    -- Define level hierarchy
    v_level_ranks := '{"none": 0, "read": 1, "write": 2, "manage": 3}'::jsonb;
    
    -- Check if user is system admin
    SELECT is_system_admin INTO v_is_sysadmin
    FROM users
    WHERE id = p_user_id AND status = 'active';
    
    IF v_is_sysadmin THEN
        RETURN TRUE;
    END IF;
    
    -- Get user's access level for this resource
    SELECT pap.access_level INTO v_user_level
    FROM employees e
    JOIN position_access_permissions pap ON pap.position_id = e.position_id
    WHERE e.user_id = p_user_id 
    AND e.status = 'active'
    AND pap.domain = p_domain
    AND pap.resource_key = p_resource_key
    LIMIT 1;
    
    -- If no permission record found, default to none
    IF v_user_level IS NULL THEN
        v_user_level := 'none';
    END IF;
    
    -- Compare levels (hierarchical: manage > write > read > none)
    RETURN (v_level_ranks->>v_user_level)::int >= (v_level_ranks->>p_required_level)::int;
END;
$$ LANGUAGE plpgsql STABLE;

COMMENT ON FUNCTION check_user_access IS 
    'Check if user has required access level for a specific resource';

-- ============================================================================
-- STEP 8: Mark old tables as deprecated (don't drop yet for safety)
-- ============================================================================
-- Add comments to old tables indicating they're deprecated
COMMENT ON TABLE user_access_permissions IS 
    'DEPRECATED: Replaced by position_access_permissions. Will be removed in future version.';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'roles_meta') THEN
        COMMENT ON TABLE roles_meta IS 
            'DEPRECATED: Replaced by position-based permissions. Will be removed in future version.';
    END IF;
    
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'roles_meta_permissions') THEN
        COMMENT ON TABLE roles_meta_permissions IS 
            'DEPRECATED: Replaced by position-based permissions. Will be removed in future version.';
    END IF;
END $$;

-- ============================================================================
-- STEP 9: Record migration completion
-- ============================================================================
INSERT INTO schema_migrations (filename, checksum, applied_at)
VALUES (
    '2025-11-12_position_based_permissions.sql',
    'position_based_permissions_v1',
    CURRENT_TIMESTAMP
)
ON CONFLICT (filename) DO NOTHING;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- Next steps:
-- 1. Run seed script to populate System Administrator permissions
-- 2. Run backfill script to migrate existing role-based data
-- 3. Update application code to use new permission system
-- 4. Test thoroughly before removing old tables
-- ============================================================================
