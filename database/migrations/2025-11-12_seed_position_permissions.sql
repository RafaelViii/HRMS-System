-- Permission Seed Data for Common Organizational Positions
-- Description: Applies recommended permission patterns based on organizational roles
-- Date: 2025-11-12
-- Idempotent: Yes (uses INSERT ON CONFLICT)

-- This script seeds permissions for common positions based on best practices:
-- - CEO: Manage+Override on all domains
-- - CHRO: Manage+Override on HR domains, Read on operations
-- - CFO: Manage+Override on payroll/finance, Read on HR
-- - HR Manager: Manage on HR modules, Read on payroll
-- - HR Officer: Write on HR operations
-- - Payroll Manager: Manage+Override on payroll
-- - Payroll Clerk: Write on payroll operations
-- - Recruiter: Write on recruitment
-- - Department Supervisor: Read on team data, Write on approvals
-- - Internal Auditor: Read on all modules, Manage on audit

-- Helper function to check if position exists
DO $$
DECLARE
    pos_id INT;
    domain_name TEXT;
    resource_name TEXT;
BEGIN
    -- CEO Position (Full access with override on everything)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%ceo%' OR LOWER(name) LIKE '%chief executive%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        -- Grant manage+override on all domains
        FOR domain_name IN 
            SELECT DISTINCT domain FROM (
                VALUES 
                ('user_management'), ('employee_management'), ('leave_management'),
                ('attendance_management'), ('payroll'), ('recruitment'),
                ('performance_management'), ('memo_management'), ('documents')
            ) AS domains(domain)
        LOOP
            -- Get all resources for this domain from the catalog (we'll use common ones)
            INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes)
            VALUES 
                (pos_id, domain_name, 'overview', 'manage', TRUE, 'CEO - Full control'),
                (pos_id, domain_name, 'create', 'manage', TRUE, 'CEO - Full control'),
                (pos_id, domain_name, 'edit', 'manage', TRUE, 'CEO - Full control'),
                (pos_id, domain_name, 'delete', 'manage', TRUE, 'CEO - Full control'),
                (pos_id, domain_name, 'approve', 'manage', TRUE, 'CEO - Full control')
            ON CONFLICT (position_id, domain, resource_key) 
            DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        END LOOP;
        
        RAISE NOTICE 'Seeded permissions for CEO position (ID: %)', pos_id;
    END IF;

    -- CHRO Position (HR domains with override, read on operations)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%chro%' OR LOWER(name) LIKE '%chief hr%' OR LOWER(name) LIKE '%hr director%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        -- Manage+Override on HR domains
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Employee Management
        (pos_id, 'employee_management', 'employees', 'manage', TRUE, 'CHRO - Full employee control'),
        (pos_id, 'employee_management', 'employee_view', 'manage', TRUE, 'CHRO'),
        (pos_id, 'employee_management', 'employee_create', 'manage', TRUE, 'CHRO'),
        (pos_id, 'employee_management', 'employee_edit', 'manage', TRUE, 'CHRO'),
        (pos_id, 'employee_management', 'employee_delete', 'manage', TRUE, 'CHRO - Can delete with override'),
        -- Leave Management
        (pos_id, 'leave_management', 'leave_requests', 'manage', TRUE, 'CHRO'),
        (pos_id, 'leave_management', 'leave_view', 'manage', TRUE, 'CHRO'),
        (pos_id, 'leave_management', 'leave_approve', 'manage', TRUE, 'CHRO'),
        (pos_id, 'leave_management', 'leave_configure', 'manage', TRUE, 'CHRO'),
        -- Attendance
        (pos_id, 'attendance_management', 'attendance_view', 'manage', FALSE, 'CHRO'),
        (pos_id, 'attendance_management', 'attendance_edit', 'manage', FALSE, 'CHRO'),
        -- Recruitment
        (pos_id, 'recruitment', 'jobs', 'manage', TRUE, 'CHRO'),
        (pos_id, 'recruitment', 'candidates', 'manage', FALSE, 'CHRO'),
        -- Performance
        (pos_id, 'performance_management', 'reviews', 'manage', TRUE, 'CHRO'),
        (pos_id, 'performance_management', 'goals', 'manage', FALSE, 'CHRO'),
        -- Memos & Documents
        (pos_id, 'memo_management', 'memos', 'manage', FALSE, 'CHRO'),
        (pos_id, 'documents', 'employee_documents', 'manage', FALSE, 'CHRO'),
        -- Read access on payroll
        (pos_id, 'payroll', 'payroll_runs', 'read', FALSE, 'CHRO - Read only'),
        (pos_id, 'payroll', 'payroll_reports', 'read', FALSE, 'CHRO - Read only')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for CHRO position (ID: %)', pos_id;
    END IF;

    -- CFO Position (Payroll/Finance with override, read on HR)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%cfo%' OR LOWER(name) LIKE '%chief financial%' OR LOWER(name) LIKE '%finance director%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Payroll Management with override
        (pos_id, 'payroll', 'payroll_runs', 'manage', TRUE, 'CFO - Release payroll'),
        (pos_id, 'payroll', 'payroll_approve', 'manage', TRUE, 'CFO'),
        (pos_id, 'payroll', 'payroll_release', 'manage', TRUE, 'CFO - Release to bank'),
        (pos_id, 'payroll', 'payroll_reports', 'manage', FALSE, 'CFO'),
        (pos_id, 'payroll', 'payroll_configure', 'manage', FALSE, 'CFO'),
        -- Read access on HR modules
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'CFO - View for cost planning'),
        (pos_id, 'leave_management', 'leave_requests', 'read', FALSE, 'CFO'),
        (pos_id, 'attendance_management', 'attendance_view', 'read', FALSE, 'CFO'),
        (pos_id, 'recruitment', 'jobs', 'read', FALSE, 'CFO - Headcount planning')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for CFO position (ID: %)', pos_id;
    END IF;

    -- HR Manager Position (Manage HR modules, no override)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%hr manager%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Employee Management
        (pos_id, 'employee_management', 'employees', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'employee_management', 'employee_view', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'employee_management', 'employee_create', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'employee_management', 'employee_edit', 'manage', FALSE, 'HR Manager'),
        -- Leave Management
        (pos_id, 'leave_management', 'leave_requests', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'leave_management', 'leave_approve', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'leave_management', 'leave_configure', 'manage', FALSE, 'HR Manager'),
        -- Attendance
        (pos_id, 'attendance_management', 'attendance_view', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'attendance_management', 'attendance_edit', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'attendance_management', 'attendance_configure', 'manage', FALSE, 'HR Manager'),
        -- Recruitment
        (pos_id, 'recruitment', 'jobs', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'recruitment', 'candidates', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'recruitment', 'recruitment_workflow', 'manage', FALSE, 'HR Manager'),
        -- Performance
        (pos_id, 'performance_management', 'reviews', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'performance_management', 'goals', 'manage', FALSE, 'HR Manager'),
        (pos_id, 'performance_management', 'performance_configure', 'manage', FALSE, 'HR Manager'),
        -- Memos & Documents
        (pos_id, 'memo_management', 'memos', 'write', FALSE, 'HR Manager'),
        (pos_id, 'documents', 'employee_documents', 'write', FALSE, 'HR Manager'),
        -- Read access on payroll
        (pos_id, 'payroll', 'payroll_runs', 'read', FALSE, 'HR Manager - Read only')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for HR Manager position (ID: %)', pos_id;
    END IF;

    -- HR Officer Position (Write on HR operations)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%hr officer%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Employee Management
        (pos_id, 'employee_management', 'employees', 'write', FALSE, 'HR Officer'),
        (pos_id, 'employee_management', 'employee_view', 'write', FALSE, 'HR Officer'),
        (pos_id, 'employee_management', 'employee_create', 'write', FALSE, 'HR Officer'),
        (pos_id, 'employee_management', 'employee_edit', 'write', FALSE, 'HR Officer'),
        -- Leave Management
        (pos_id, 'leave_management', 'leave_requests', 'write', FALSE, 'HR Officer'),
        (pos_id, 'leave_management', 'leave_approve', 'write', FALSE, 'HR Officer'),
        -- Attendance
        (pos_id, 'attendance_management', 'attendance_view', 'write', FALSE, 'HR Officer'),
        (pos_id, 'attendance_management', 'attendance_edit', 'write', FALSE, 'HR Officer'),
        -- Documents
        (pos_id, 'documents', 'employee_documents', 'write', FALSE, 'HR Officer'),
        -- Read access
        (pos_id, 'recruitment', 'jobs', 'read', FALSE, 'HR Officer'),
        (pos_id, 'payroll', 'payroll_runs', 'read', FALSE, 'HR Officer')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for HR Officer position (ID: %)', pos_id;
    END IF;

    -- Payroll Manager Position (Manage payroll with override)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%payroll manager%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Payroll Management
        (pos_id, 'payroll', 'payroll_runs', 'manage', TRUE, 'Payroll Manager'),
        (pos_id, 'payroll', 'payroll_approve', 'manage', FALSE, 'Payroll Manager'),
        (pos_id, 'payroll', 'payroll_configure', 'manage', FALSE, 'Payroll Manager'),
        (pos_id, 'payroll', 'payroll_reports', 'manage', FALSE, 'Payroll Manager'),
        -- Attendance (for time data)
        (pos_id, 'attendance_management', 'attendance_view', 'write', FALSE, 'Payroll Manager'),
        (pos_id, 'attendance_management', 'attendance_edit', 'write', FALSE, 'Payroll Manager'),
        -- Employee (read for payroll processing)
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'Payroll Manager'),
        -- Leave (read for deductions)
        (pos_id, 'leave_management', 'leave_requests', 'read', FALSE, 'Payroll Manager')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for Payroll Manager position (ID: %)', pos_id;
    END IF;

    -- Payroll Clerk Position (Write on payroll operations)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%payroll clerk%' OR LOWER(name) LIKE '%payroll officer%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Payroll Operations
        (pos_id, 'payroll', 'payroll_runs', 'write', FALSE, 'Payroll Clerk'),
        (pos_id, 'payroll', 'payroll_reports', 'read', FALSE, 'Payroll Clerk'),
        -- Attendance
        (pos_id, 'attendance_management', 'attendance_view', 'write', FALSE, 'Payroll Clerk'),
        (pos_id, 'attendance_management', 'attendance_edit', 'write', FALSE, 'Payroll Clerk'),
        -- Employee (read only)
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'Payroll Clerk'),
        -- Leave (read for deductions)
        (pos_id, 'leave_management', 'leave_requests', 'read', FALSE, 'Payroll Clerk')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for Payroll Clerk position (ID: %)', pos_id;
    END IF;

    -- Recruiter Position (Write on recruitment)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%recruiter%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Recruitment
        (pos_id, 'recruitment', 'jobs', 'write', FALSE, 'Recruiter'),
        (pos_id, 'recruitment', 'candidates', 'write', FALSE, 'Recruiter'),
        (pos_id, 'recruitment', 'recruitment_workflow', 'write', FALSE, 'Recruiter'),
        -- Employee (read for team composition)
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'Recruiter'),
        -- Positions (read)
        (pos_id, 'employee_management', 'positions', 'read', FALSE, 'Recruiter'),
        -- Departments (read)
        (pos_id, 'employee_management', 'departments', 'read', FALSE, 'Recruiter')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for Recruiter position (ID: %)', pos_id;
    END IF;

    -- Department Supervisor Position (Read on team, write on approvals)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%supervisor%' OR LOWER(name) LIKE '%team lead%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Employee (read team data)
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'Supervisor'),
        -- Leave Approvals
        (pos_id, 'leave_management', 'leave_requests', 'read', FALSE, 'Supervisor'),
        (pos_id, 'leave_management', 'leave_approve', 'write', FALSE, 'Supervisor - First-level approval'),
        -- Attendance Approvals
        (pos_id, 'attendance_management', 'attendance_view', 'read', FALSE, 'Supervisor'),
        (pos_id, 'attendance_management', 'attendance_approve', 'write', FALSE, 'Supervisor'),
        -- Performance Reviews
        (pos_id, 'performance_management', 'reviews', 'write', FALSE, 'Supervisor - Conduct appraisals')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for Supervisor position (ID: %)', pos_id;
    END IF;

    -- Internal Auditor Position (Read all, manage audit)
    SELECT id INTO pos_id FROM positions WHERE LOWER(name) LIKE '%auditor%' OR LOWER(name) LIKE '%compliance%' LIMIT 1;
    IF pos_id IS NOT NULL THEN
        DELETE FROM position_access_permissions WHERE position_id = pos_id;
        
        INSERT INTO position_access_permissions (position_id, domain, resource_key, access_level, allow_override, notes) VALUES
        -- Audit Module (manage)
        (pos_id, 'user_management', 'audit_logs', 'manage', FALSE, 'Auditor'),
        (pos_id, 'user_management', 'audit_reports', 'manage', FALSE, 'Auditor'),
        -- Read access on all operational modules
        (pos_id, 'employee_management', 'employees', 'read', FALSE, 'Auditor'),
        (pos_id, 'leave_management', 'leave_requests', 'read', FALSE, 'Auditor'),
        (pos_id, 'attendance_management', 'attendance_view', 'read', FALSE, 'Auditor'),
        (pos_id, 'payroll', 'payroll_runs', 'read', FALSE, 'Auditor'),
        (pos_id, 'payroll', 'payroll_reports', 'read', FALSE, 'Auditor'),
        (pos_id, 'recruitment', 'jobs', 'read', FALSE, 'Auditor'),
        (pos_id, 'recruitment', 'candidates', 'read', FALSE, 'Auditor'),
        (pos_id, 'performance_management', 'reviews', 'read', FALSE, 'Auditor'),
        (pos_id, 'memo_management', 'memos', 'read', FALSE, 'Auditor'),
        (pos_id, 'documents', 'employee_documents', 'read', FALSE, 'Auditor')
        ON CONFLICT (position_id, domain, resource_key) 
        DO UPDATE SET access_level = EXCLUDED.access_level, allow_override = EXCLUDED.allow_override;
        
        RAISE NOTICE 'Seeded permissions for Auditor position (ID: %)', pos_id;
    END IF;

    -- Log completion
    INSERT INTO audit_log (user_id, action, data, ip_address, created_at)
    VALUES (1, 'seed_position_permissions', 
            '{"migration": "2025-11-12_seed_position_permissions", "positions_seeded": "CEO, CHRO, CFO, HR Manager, HR Officer, Payroll Manager, Payroll Clerk, Recruiter, Supervisor, Auditor"}',
            '127.0.0.1', CURRENT_TIMESTAMP)
    ON CONFLICT DO NOTHING;

    RAISE NOTICE '✅ Position permission seeding complete!';
END $$;
