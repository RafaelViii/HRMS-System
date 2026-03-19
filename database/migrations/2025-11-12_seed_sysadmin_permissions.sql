-- Seed Script: System Administrator Full Permissions
-- Date: 2025-11-12
-- Description: Grant full 'manage' access to System Administrator position for all resources

DO $$
DECLARE
    v_sysadmin_position_id INTEGER;
BEGIN
    -- Get System Administrator position ID
    SELECT id INTO v_sysadmin_position_id
    FROM positions
    WHERE LOWER(name) = 'system administrator'
    LIMIT 1;
    
    IF v_sysadmin_position_id IS NULL THEN
        RAISE EXCEPTION 'System Administrator position not found. Run main migration first.';
    END IF;
    
    RAISE NOTICE 'Seeding permissions for System Administrator (Position ID: %)', v_sysadmin_position_id;
    
    -- ========================================================================
    -- SYSTEM ADMINISTRATION
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'system', 'dashboard', 'manage', TRUE),
    (v_sysadmin_position_id, 'system', 'system_settings', 'manage', TRUE),
    (v_sysadmin_position_id, 'system', 'audit_logs', 'manage', TRUE),
    (v_sysadmin_position_id, 'system', 'system_logs', 'manage', TRUE),
    (v_sysadmin_position_id, 'system', 'backup_restore', 'manage', TRUE),
    (v_sysadmin_position_id, 'system', 'tools_workbench', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- HR CORE FUNCTIONS
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'hr_core', 'employees', 'manage', TRUE),
    (v_sysadmin_position_id, 'hr_core', 'departments', 'manage', TRUE),
    (v_sysadmin_position_id, 'hr_core', 'positions', 'manage', TRUE),
    (v_sysadmin_position_id, 'hr_core', 'branches', 'manage', TRUE),
    (v_sysadmin_position_id, 'hr_core', 'recruitment', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- PAYROLL MANAGEMENT
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'payroll', 'payroll_runs', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'payroll_batches', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'payslips', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'payroll_config', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'payroll_complaints', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'overtime', 'manage', TRUE),
    (v_sysadmin_position_id, 'payroll', 'dtr_uploads', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- LEAVE MANAGEMENT
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'leave', 'leave_requests', 'manage', TRUE),
    (v_sysadmin_position_id, 'leave', 'leave_approval', 'manage', TRUE),
    (v_sysadmin_position_id, 'leave', 'leave_balances', 'manage', TRUE),
    (v_sysadmin_position_id, 'leave', 'leave_config', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- ATTENDANCE & SCHEDULING
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'attendance', 'attendance_records', 'manage', TRUE),
    (v_sysadmin_position_id, 'attendance', 'work_schedules', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- DOCUMENTS & MEMOS
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'documents', 'memos', 'manage', TRUE),
    (v_sysadmin_position_id, 'documents', 'documents', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- PERFORMANCE MANAGEMENT
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'performance', 'performance_reviews', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- NOTIFICATIONS
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'notifications', 'view_notifications', 'manage', TRUE),
    (v_sysadmin_position_id, 'notifications', 'create_notifications', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- USER MANAGEMENT
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'user_management', 'user_accounts', 'manage', TRUE),
    (v_sysadmin_position_id, 'user_management', 'self_profile', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    -- ========================================================================
    -- REPORTS & ANALYTICS
    -- ========================================================================
    INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override) VALUES
    (v_sysadmin_position_id, 'reports', 'export_data', 'manage', TRUE),
    (v_sysadmin_position_id, 'reports', 'analytics', 'manage', TRUE)
    ON CONFLICT (position_id, domain, resource_key) DO UPDATE 
    SET access_level = 'manage', allow_override = TRUE;
    
    RAISE NOTICE 'Successfully seeded % permissions for System Administrator', 
        (SELECT COUNT(*) FROM position_access_permissions WHERE position_id = v_sysadmin_position_id);
END $$;
