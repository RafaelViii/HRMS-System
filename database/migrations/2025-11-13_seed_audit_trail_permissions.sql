-- Migration: Seed Audit Trail Permissions
-- Date: 2025-11-13
-- Description: Ensures audit trail permissions are properly configured for key positions
-- Idempotent: Yes

DO $$
DECLARE
    pos_id INTEGER;
    perm_exists BOOLEAN;
BEGIN
    RAISE NOTICE 'Starting audit trail permissions seeding...';

    -- ============================================================================
    -- System Administrator Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%system admin%' 
       OR LOWER(title) LIKE '%sysadmin%'
       OR title = 'System Administrator'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        -- Check if permission already exists
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'manage', TRUE, 'System Administrator - Full audit trail access'),
                (pos_id, 'user_management', 'audit_reports', 'manage', TRUE, 'System Administrator - Full audit reports access')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for System Administrator (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'System Administrator already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'System Administrator position not found - skipping';
    END IF;

    -- ============================================================================
    -- HR Manager Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%hr manager%'
       OR LOWER(title) LIKE '%human resource%manager%'
       OR title = 'HR Manager'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'manage', FALSE, 'HR Manager - Full audit trail access for HR oversight'),
                (pos_id, 'user_management', 'audit_reports', 'manage', FALSE, 'HR Manager - Full audit reports access')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for HR Manager (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'HR Manager already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'HR Manager position not found - skipping';
    END IF;

    -- ============================================================================
    -- Internal Auditor Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%auditor%'
       OR LOWER(title) LIKE '%compliance%'
       OR title = 'Internal Auditor'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'manage', FALSE, 'Internal Auditor - Full audit trail access for compliance'),
                (pos_id, 'user_management', 'audit_reports', 'manage', FALSE, 'Internal Auditor - Full audit reports access')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Internal Auditor (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Internal Auditor already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Internal Auditor position not found - skipping';
    END IF;

    -- ============================================================================
    -- IT Administrator Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%it admin%'
       OR LOWER(title) LIKE '%information tech%admin%'
       OR title = 'IT Administrator'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'read', FALSE, 'IT Administrator - Read audit trail for system monitoring'),
                (pos_id, 'user_management', 'audit_reports', 'read', FALSE, 'IT Administrator - Read audit reports for analysis')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for IT Administrator (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'IT Administrator already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'IT Administrator position not found - skipping';
    END IF;

    -- ============================================================================
    -- Compliance Officer Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%compliance officer%'
       OR LOWER(title) LIKE '%compliance manager%'
       OR title = 'Compliance Officer'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'manage', FALSE, 'Compliance Officer - Full audit trail access for regulatory compliance'),
                (pos_id, 'user_management', 'audit_reports', 'manage', FALSE, 'Compliance Officer - Full audit reports access')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Compliance Officer (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Compliance Officer already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Compliance Officer position not found - skipping';
    END IF;

    -- ============================================================================
    -- Security Officer Position
    -- ============================================================================
    SELECT id INTO pos_id FROM positions 
    WHERE LOWER(title) LIKE '%security officer%'
       OR LOWER(title) LIKE '%security manager%'
       OR LOWER(title) LIKE '%infosec%'
       OR title = 'Security Officer'
    LIMIT 1;

    IF pos_id IS NOT NULL THEN
        SELECT EXISTS (
            SELECT 1 FROM position_permissions 
            WHERE position_id = pos_id 
            AND domain = 'user_management' 
            AND resource = 'audit_logs'
        ) INTO perm_exists;

        IF NOT perm_exists THEN
            INSERT INTO position_permissions (position_id, domain, resource, access_level, is_system_permission, notes)
            VALUES 
                (pos_id, 'user_management', 'audit_logs', 'manage', FALSE, 'Security Officer - Full audit trail access for security monitoring'),
                (pos_id, 'user_management', 'audit_reports', 'manage', FALSE, 'Security Officer - Full audit reports access')
            ON CONFLICT (position_id, domain, resource) DO NOTHING;
            RAISE NOTICE 'Seeded audit trail permissions for Security Officer (ID: %)', pos_id;
        ELSE
            RAISE NOTICE 'Security Officer already has audit trail permissions';
        END IF;
    ELSE
        RAISE NOTICE 'Security Officer position not found - skipping';
    END IF;

    RAISE NOTICE 'Audit trail permissions seeding completed successfully!';
    RAISE NOTICE '========================================';
    RAISE NOTICE 'Summary:';
    RAISE NOTICE '- Positions with MANAGE access: System Admin, HR Manager, Internal Auditor, Compliance Officer, Security Officer';
    RAISE NOTICE '- Positions with READ access: IT Administrator';
    RAISE NOTICE '- Only existing positions were updated';
    RAISE NOTICE '========================================';

EXCEPTION
    WHEN OTHERS THEN
        RAISE EXCEPTION 'Error seeding audit trail permissions: %', SQLERRM;
END;
$$;
