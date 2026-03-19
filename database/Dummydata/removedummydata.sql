-- Remove demo data inserted by database/dummydata.sql
USE hrms;
SET NAMES utf8mb4;

START TRANSACTION;
SET FOREIGN_KEY_CHECKS=0;

-- Reverse-order dependent deletes first
DELETE FROM action_reversals WHERE audit_log_id IN (SELECT id FROM audit_logs WHERE action LIKE 'demo_%');
DELETE FROM audit_logs WHERE action LIKE 'demo_%';
DELETE FROM notifications WHERE message LIKE '[DEMO]%';

-- Documents and assignments
DELETE FROM document_assignments WHERE document_id IN (SELECT id FROM documents WHERE title LIKE 'Demo - %');
DELETE FROM documents WHERE title LIKE 'Demo - %';

-- Attendance/Perf/Leave tied to demo employees
DELETE FROM attendance WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');
DELETE FROM performance_reviews WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');
DELETE FROM leave_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');

-- Payroll and periods
DELETE FROM payroll WHERE period_id IN (
	SELECT id FROM payroll_periods WHERE period_start IN ('2025-01-01','2025-01-16')
);
DELETE FROM payroll_periods WHERE period_start IN ('2025-01-01','2025-01-16');

-- Recruitment
DELETE FROM recruitment WHERE email LIKE '%@demo.local' OR full_name LIKE 'Demo Applicant %';

-- Access templates and user access mappings
DELETE FROM user_access_permissions WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@demo.local');
DELETE FROM user_access_modules WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@demo.local');
DELETE FROM access_template_permissions WHERE template_id IN (SELECT id FROM access_templates WHERE name LIKE 'Demo - %');
DELETE FROM access_templates WHERE name LIKE 'Demo - %';

-- Core demo domain rows
DELETE FROM employees WHERE employee_code LIKE 'DEMO%';
DELETE FROM positions WHERE name LIKE 'Demo - %';
DELETE FROM departments WHERE name LIKE 'Demo - %';

-- Misc
DELETE FROM pdf_templates WHERE report_key LIKE 'demo_%';
DELETE FROM system_logs WHERE code LIKE 'DEMO%';

-- Backups cleanup
DELETE FROM employees_backup WHERE employee_code LIKE 'DEMO-BACKUP-%';
DELETE FROM payroll_backup WHERE id >= 900000; -- demo range if used
DELETE FROM leave_requests_backup WHERE id >= 900000; -- demo range if used
DELETE FROM departments_backup WHERE name LIKE 'Demo - %';
DELETE FROM positions_backup WHERE name LIKE 'Demo - %';
DELETE FROM users_backup WHERE email = 'backupuser@demo.local';

-- Users last (due to various FKs)
DELETE FROM users WHERE email LIKE '%@demo.local';

SET FOREIGN_KEY_CHECKS=1;
COMMIT;
