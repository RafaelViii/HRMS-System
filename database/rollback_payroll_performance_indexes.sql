-- ================================================================
-- Rollback Performance Indexes ONLY (Safe Version)
-- Created: 2025-11-11
-- Purpose: Remove performance indexes without data loss
-- ================================================================

-- ================================================================
-- Performance Indexes Rollback (Safe - No Data Loss)
-- ================================================================

-- Payroll module indexes
DROP INDEX IF EXISTS idx_employee_custom_compensation_employee_level_active;
DROP INDEX IF EXISTS idx_employee_custom_compensation_config_level;
DROP INDEX IF EXISTS idx_employee_custom_compensation_branch;
DROP INDEX IF EXISTS idx_employee_custom_compensation_department;
DROP INDEX IF EXISTS idx_employee_custom_benefits_employee_level_active;
DROP INDEX IF EXISTS idx_employee_custom_deductions_employee_level_active;
DROP INDEX IF EXISTS idx_employee_custom_leave_employee_level_active;
DROP INDEX IF EXISTS idx_employee_work_schedules_employee_active;
DROP INDEX IF EXISTS idx_work_schedule_templates_level_active;
DROP INDEX IF EXISTS idx_employee_payroll_profiles_ot_enabled;
DROP INDEX IF EXISTS idx_employees_status_branch;

-- Profile picture indexes
DROP INDEX IF EXISTS idx_users_profile_picture;
DROP INDEX IF EXISTS idx_employees_profile_picture;

-- Overtime indexes
DROP INDEX IF EXISTS idx_overtime_employee;
DROP INDEX IF EXISTS idx_overtime_status;
DROP INDEX IF EXISTS idx_overtime_date;
DROP INDEX IF EXISTS idx_overtime_approver;
DROP INDEX IF EXISTS idx_overtime_payroll_run;
DROP INDEX IF EXISTS idx_overtime_employee_status;
DROP INDEX IF EXISTS idx_overtime_employee_date;
DROP INDEX IF EXISTS idx_overtime_employee_date_status;

-- ================================================================
-- DESTRUCTIVE ROLLBACKS COMMENTED OUT
-- Uncomment sections below ONLY after backing up your database
-- ================================================================

/*
-- ================================================================
-- Profile Fields Rollback (DATA LOSS WARNING)
-- ================================================================

ALTER TABLE users
  DROP COLUMN IF EXISTS profile_picture,
  DROP COLUMN IF EXISTS date_of_birth,
  DROP COLUMN IF EXISTS gender,
  DROP COLUMN IF EXISTS phone,
  DROP COLUMN IF EXISTS bio;

ALTER TABLE employees
  DROP COLUMN IF EXISTS profile_picture,
  DROP COLUMN IF EXISTS date_of_birth,
  DROP COLUMN IF EXISTS gender,
  DROP COLUMN IF EXISTS nationality,
  DROP COLUMN IF EXISTS marital_status,
  DROP COLUMN IF EXISTS emergency_contact_name,
  DROP COLUMN IF EXISTS emergency_contact_phone,
  DROP COLUMN IF EXISTS bio;
*/

/*
-- ================================================================
-- Overtime Schema Rollback (DATA LOSS WARNING)
-- ================================================================

DROP TRIGGER IF EXISTS trg_overtime_set_updated ON overtime_requests;
DROP FUNCTION IF EXISTS fn_overtime_set_updated();

ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS chk_overtime_status;
ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS chk_overtime_type;
ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS chk_overtime_hours;
ALTER TABLE overtime_requests DROP CONSTRAINT IF EXISTS fk_overtime_created_by;

ALTER TABLE overtime_requests
  DROP COLUMN IF EXISTS start_time,
  DROP COLUMN IF EXISTS end_time,
  DROP COLUMN IF EXISTS overtime_type,
  DROP COLUMN IF EXISTS work_description,
  DROP COLUMN IF EXISTS notes,
  DROP COLUMN IF EXISTS created_by,
  DROP COLUMN IF EXISTS is_paid;
*/

/*
-- ================================================================
-- Role Permissions Rollback (DESTRUCTIVE - CUSTOM PERMS LOST)
-- ================================================================

DELETE FROM roles_meta_permissions 
WHERE role_name IN ('admin', 'hr', 'hr_supervisor', 'hr_recruit', 'hr_payroll', 
                    'accountant', 'manager', 'admin_assistant', 'employee');
*/

-- ================================================================
-- Safe Rollback Complete
-- ================================================================
-- Only indexes were removed. No data loss occurred.
-- To rollback schema changes, uncomment sections above AFTER backing up.
