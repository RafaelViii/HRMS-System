-- Demo data for HRMS
-- Re-runnable: cleans prior demo rows first, then inserts fresh data
-- Notes:
--  - Demo users use the same bcrypt hash as the seeded admin (see schema.sql)
--  - You can log in as admin@hrms.local (password per schema seed).

USE hrms;
SET NAMES utf8mb4;

-- Clean previous demo data (safe if none exist)
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM action_reversals WHERE audit_log_id IN (SELECT id FROM audit_logs WHERE action LIKE 'demo_%');
DELETE FROM audit_logs WHERE action LIKE 'demo_%';
DELETE FROM notifications WHERE message LIKE '[DEMO]%';
DELETE FROM payroll WHERE period_id IN (SELECT id FROM payroll_periods WHERE period_start IN ('2025-01-01','2025-01-16'));
DELETE FROM payroll_periods WHERE period_start IN ('2025-01-01','2025-01-16');
DELETE FROM attendance WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');
DELETE FROM performance_reviews WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');
DELETE FROM leave_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'DEMO%');
DELETE FROM document_assignments WHERE document_id IN (SELECT id FROM documents WHERE title LIKE 'Demo - %');
DELETE FROM documents WHERE title LIKE 'Demo - %';
DELETE FROM recruitment WHERE email LIKE '%@demo.local' OR full_name LIKE 'Demo Applicant %';
DELETE FROM user_access_permissions WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@demo.local');
DELETE FROM user_access_modules WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@demo.local');
DELETE FROM access_template_permissions WHERE template_id IN (SELECT id FROM access_templates WHERE name LIKE 'Demo - %');
DELETE FROM access_templates WHERE name LIKE 'Demo - %';
DELETE FROM employees WHERE employee_code LIKE 'DEMO%';
DELETE FROM positions WHERE name LIKE 'Demo - %';
DELETE FROM departments WHERE name LIKE 'Demo - %';
DELETE FROM pdf_templates WHERE report_key LIKE 'demo_%';
DELETE FROM system_logs WHERE code LIKE 'DEMO%';
-- backups cleanup
DELETE FROM employees_backup WHERE employee_code LIKE 'DEMO-BACKUP-%';
DELETE FROM payroll_backup WHERE id >= 900000; -- demo range if used
DELETE FROM leave_requests_backup WHERE id >= 900000; -- demo range if used
DELETE FROM departments_backup WHERE name LIKE 'Demo - %';
DELETE FROM positions_backup WHERE name LIKE 'Demo - %';
DELETE FROM users_backup WHERE email = 'backupuser@demo.local';
-- users last to clean (due to FKs)
DELETE FROM users WHERE email LIKE '%@demo.local';
SET FOREIGN_KEY_CHECKS=1;

-- Ensure optional tables created by app exist (for fresh DBs)
CREATE TABLE IF NOT EXISTS user_access_permissions (
  user_id INT NOT NULL,
  module VARCHAR(50) NOT NULL,
  level ENUM('none','read','write','admin') NOT NULL DEFAULT 'read',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module),
  CONSTRAINT fk_uap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_template_permissions (
  template_id INT NOT NULL,
  module VARCHAR(50) NOT NULL,
  level ENUM('none','read','write','admin') NOT NULL DEFAULT 'read',
  PRIMARY KEY (template_id, module),
  CONSTRAINT fk_atp_tpl FOREIGN KEY (template_id) REFERENCES access_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_access_modules (
  user_id INT NOT NULL,
  module VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module),
  CONSTRAINT fk_uam_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users_backup LIKE users;
CREATE TABLE IF NOT EXISTS action_reversals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  audit_log_id INT NOT NULL UNIQUE,
  reversed_by INT NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Departments
INSERT INTO departments (name, description)
VALUES
 ('Demo - HR', 'Demo Human Resources'),
 ('Demo - IT', 'Demo Information Technology'),
 ('Demo - Sales', 'Demo Sales Department')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Positions
INSERT INTO positions (department_id, name, description, base_salary)
VALUES
 ((SELECT id FROM departments WHERE name='Demo - HR'), 'Demo - HR Specialist', 'Handles HR tasks', 35000.00),
 ((SELECT id FROM departments WHERE name='Demo - IT'), 'Demo - Software Engineer', 'Builds software', 60000.00),
 ((SELECT id FROM departments WHERE name='Demo - Sales'), 'Demo - Sales Executive', 'Sells products', 30000.00)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Users (password hash matches the seeded admin hash)
INSERT INTO users (email, password_hash, full_name, role, status)
VALUES
 ('hr@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo HR', 'hr', 'active'),
 ('manager@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Manager', 'manager', 'active'),
 ('acct@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Accountant', 'accountant', 'active'),
 ('emp1@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee One', 'employee', 'active'),
 ('emp2@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee Two', 'employee', 'active'),
 ('emp03@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 03', 'employee', 'active'),
 ('emp04@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 04', 'employee', 'active'),
 ('emp05@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 05', 'employee', 'active'),
 ('emp06@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 06', 'employee', 'active'),
 ('emp07@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 07', 'employee', 'active'),
 ('emp08@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 08', 'employee', 'active'),
 ('emp09@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 09', 'employee', 'active'),
 ('emp10@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 10', 'employee', 'active'),
 ('emp11@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 11', 'employee', 'active'),
 ('emp12@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 12', 'employee', 'active'),
 ('emp13@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 13', 'employee', 'active'),
 ('emp14@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 14', 'employee', 'active'),
 ('emp15@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 15', 'employee', 'active'),
 ('emp16@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 16', 'employee', 'active'),
 ('emp17@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 17', 'employee', 'active'),
 ('emp18@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 18', 'employee', 'active'),
 ('emp19@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 19', 'employee', 'active'),
 ('emp20@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo Employee 20', 'employee', 'active')
ON DUPLICATE KEY UPDATE email = VALUES(email);

-- Employees (some bound to accounts, some not)
INSERT INTO employees (user_id, employee_code, first_name, last_name, email, phone, address, department_id, position_id, hire_date, employment_type, status, salary)
VALUES
 ((SELECT id FROM users WHERE email='emp1@demo.local'), 'DEMO-EMP-001', 'Demo', 'One', 'emp1@demo.local', '+639123456001', 'Demo Address 1', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-01-10', 'regular', 'active', 65000.00),
 (NULL, 'DEMO-EMP-002', 'Demo', 'Two', 'emp2@demo.local', '+639123456002', 'Demo Address 2', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-02-15', 'regular', 'active', 36000.00),
 ((SELECT id FROM users WHERE email='manager@demo.local'), 'DEMO-EMP-003', 'Demo', 'Manager', 'manager@demo.local', '+639123456003', 'Demo Address 3', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2023-09-01', 'regular', 'active', 45000.00),
 ((SELECT id FROM users WHERE email='emp03@demo.local'), 'DEMO-EMP-004', 'Demo', 'Emp04', 'emp03@demo.local', '+639123456004', 'Demo Address 4', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-03-01', 'regular', 'active', 52000.00),
 ((SELECT id FROM users WHERE email='emp04@demo.local'), 'DEMO-EMP-005', 'Demo', 'Emp05', 'emp04@demo.local', '+639123456005', 'Demo Address 5', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-03-05', 'regular', 'active', 38000.00),
 ((SELECT id FROM users WHERE email='emp05@demo.local'), 'DEMO-EMP-006', 'Demo', 'Emp06', 'emp05@demo.local', '+639123456006', 'Demo Address 6', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2024-03-10', 'regular', 'active', 40000.00),
 ((SELECT id FROM users WHERE email='emp06@demo.local'), 'DEMO-EMP-007', 'Demo', 'Emp07', 'emp06@demo.local', '+639123456007', 'Demo Address 7', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-03-12', 'regular', 'active', 60000.00),
 ((SELECT id FROM users WHERE email='emp07@demo.local'), 'DEMO-EMP-008', 'Demo', 'Emp08', 'emp07@demo.local', '+639123456008', 'Demo Address 8', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-03-15', 'regular', 'active', 37000.00),
 ((SELECT id FROM users WHERE email='emp08@demo.local'), 'DEMO-EMP-009', 'Demo', 'Emp09', 'emp08@demo.local', '+639123456009', 'Demo Address 9', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2024-03-18', 'regular', 'active', 41000.00),
 ((SELECT id FROM users WHERE email='emp09@demo.local'), 'DEMO-EMP-010', 'Demo', 'Emp10', 'emp09@demo.local', '+639123456010', 'Demo Address 10', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-03-20', 'regular', 'active', 58000.00),
 ((SELECT id FROM users WHERE email='emp10@demo.local'), 'DEMO-EMP-011', 'Demo', 'Emp11', 'emp10@demo.local', '+639123456011', 'Demo Address 11', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-03-22', 'regular', 'active', 39000.00),
 ((SELECT id FROM users WHERE email='emp11@demo.local'), 'DEMO-EMP-012', 'Demo', 'Emp12', 'emp11@demo.local', '+639123456012', 'Demo Address 12', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2024-03-25', 'regular', 'active', 42000.00),
 ((SELECT id FROM users WHERE email='emp12@demo.local'), 'DEMO-EMP-013', 'Demo', 'Emp13', 'emp12@demo.local', '+639123456013', 'Demo Address 13', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-04-01', 'regular', 'active', 61000.00),
 ((SELECT id FROM users WHERE email='emp13@demo.local'), 'DEMO-EMP-014', 'Demo', 'Emp14', 'emp13@demo.local', '+639123456014', 'Demo Address 14', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-04-03', 'regular', 'active', 36000.00),
 ((SELECT id FROM users WHERE email='emp14@demo.local'), 'DEMO-EMP-015', 'Demo', 'Emp15', 'emp14@demo.local', '+639123456015', 'Demo Address 15', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2024-04-05', 'regular', 'active', 43000.00),
 ((SELECT id FROM users WHERE email='emp15@demo.local'), 'DEMO-EMP-016', 'Demo', 'Emp16', 'emp15@demo.local', '+639123456016', 'Demo Address 16', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-04-07', 'regular', 'active', 59000.00),
 ((SELECT id FROM users WHERE email='emp16@demo.local'), 'DEMO-EMP-017', 'Demo', 'Emp17', 'emp16@demo.local', '+639123456017', 'Demo Address 17', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-04-10', 'regular', 'active', 37500.00),
 ((SELECT id FROM users WHERE email='emp17@demo.local'), 'DEMO-EMP-018', 'Demo', 'Emp18', 'emp17@demo.local', '+639123456018', 'Demo Address 18', (SELECT id FROM departments WHERE name='Demo - Sales'), (SELECT id FROM positions WHERE name='Demo - Sales Executive'), '2024-04-12', 'regular', 'active', 44000.00),
 ((SELECT id FROM users WHERE email='emp18@demo.local'), 'DEMO-EMP-019', 'Demo', 'Emp19', 'emp18@demo.local', '+639123456019', 'Demo Address 19', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2024-04-15', 'regular', 'active', 60500.00),
 ((SELECT id FROM users WHERE email='emp19@demo.local'), 'DEMO-EMP-020', 'Demo', 'Emp20', 'emp19@demo.local', '+639123456020', 'Demo Address 20', (SELECT id FROM departments WHERE name='Demo - HR'), (SELECT id FROM positions WHERE name='Demo - HR Specialist'), '2024-04-18', 'regular', 'active', 38500.00)
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code);

-- Documents
INSERT INTO documents (title, doc_type, file_path, created_by)
VALUES
 ('Demo - Company Policy', 'policy', 'assets/uploads/demo_policy.pdf', (SELECT id FROM users WHERE email='hr@demo.local')),
 ('Demo - Employment Contract', 'contract', 'assets/uploads/demo_contract.pdf', (SELECT id FROM users WHERE email='hr@demo.local'))
;

-- Document Assignments
INSERT INTO document_assignments (document_id, employee_id)
VALUES
 ((SELECT id FROM documents WHERE title='Demo - Company Policy'), (SELECT id FROM employees WHERE employee_code='DEMO-EMP-001')),
 ((SELECT id FROM documents WHERE title='Demo - Employment Contract'), (SELECT id FROM employees WHERE employee_code='DEMO-EMP-001'))
;

-- Attendance (last 30 days for all demo employees)
INSERT IGNORE INTO attendance (employee_id, date, time_in, time_out, overtime_minutes, status)
SELECT
  e.id AS employee_id,
  DATE_SUB(CURDATE(), INTERVAL n.n DAY) AS date,
  CASE
    WHEN WEEKDAY(DATE_SUB(CURDATE(), INTERVAL n.n DAY)) IN (5,6) THEN NULL
    ELSE ADDTIME('09:00:00', SEC_TO_TIME(((e.id % 10) * 5) * 60))
  END AS time_in,
  CASE
    WHEN WEEKDAY(DATE_SUB(CURDATE(), INTERVAL n.n DAY)) IN (5,6) THEN NULL
    ELSE ADDTIME('18:00:00', SEC_TO_TIME(((e.id % 10) * 5 + 10) * 60))
  END AS time_out,
  CASE
    WHEN WEEKDAY(DATE_SUB(CURDATE(), INTERVAL n.n DAY)) IN (5,6) THEN 0
    ELSE (e.id % 4) * 15
  END AS overtime_minutes,
  CASE
    WHEN WEEKDAY(DATE_SUB(CURDATE(), INTERVAL n.n DAY)) IN (5,6) THEN 'holiday'
    WHEN (e.id % 7) = 0 AND (n.n % 13) = 0 THEN 'absent'
    WHEN (e.id % 3) = 0 AND (n.n % 5) = 0 THEN 'late'
    ELSE 'present'
  END AS status
FROM employees e
JOIN (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
  UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
  UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
) AS n
WHERE e.employee_code LIKE 'DEMO-EMP-%';

-- Leave Requests
INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, status, remarks)
VALUES
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-003'), 'vacation', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 2.00, 'approved', 'Demo approved leave'),
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-002'), 'sick', CURDATE(), CURDATE(), 1.00, 'pending', 'Demo sick leave')
;

-- Payroll Periods
INSERT INTO payroll_periods (period_start, period_end, status)
VALUES
 ('2025-01-01','2025-01-15','processed'),
 ('2025-01-16','2025-01-31','open')
ON DUPLICATE KEY UPDATE period_start = VALUES(period_start);

-- Payroll
INSERT INTO payroll (employee_id, period_id, basic_pay, allowances, deductions, net_pay, released_at)
VALUES
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-001'), (SELECT id FROM payroll_periods WHERE period_start='2025-01-01'), 32500.00, 2000.00, 1500.00, 33000.00, NOW()),
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-002'), (SELECT id FROM payroll_periods WHERE period_start='2025-01-01'), 18000.00, 1000.00, 500.00, 18500.00, NOW())
;

-- Performance Reviews
INSERT INTO performance_reviews (employee_id, review_date, kpi_score, remarks)
VALUES
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-001'), '2025-01-15', 4.30, 'Strong performer'),
 ((SELECT id FROM employees WHERE employee_code='DEMO-EMP-002'), '2025-01-15', 3.80, 'Meets expectations')
;

-- Recruitment
INSERT INTO recruitment (full_name, email, phone, resume_path, status, notes)
VALUES
 ('Demo Applicant One', 'applicant1@demo.local', '+639180000001', 'assets/uploads/resume_demo1.pdf', 'shortlist', 'Demo resume'),
 ('Demo Applicant Two', 'applicant2@demo.local', '+639180000002', 'assets/uploads/resume_demo2.pdf', 'new', 'New applicant')
;

-- Action Log (audit_logs) demo entries
INSERT INTO audit_logs (user_id, action, details)
VALUES
 ((SELECT id FROM users WHERE email='hr@demo.local'), 'demo_create_employee', 'DEMO-EMP-001'),
 ((SELECT id FROM users WHERE email='manager@demo.local'), 'demo_delete_employee', 'ID=99999'),
 ((SELECT id FROM users WHERE email='hr@demo.local'), 'demo_unbind_account', 'emp=DEMO-EMP-003, user_id=99998')
;

-- Action Reversal demo (mark the delete demo as reversed)
INSERT INTO action_reversals (audit_log_id, reversed_by, reason)
VALUES
 ((SELECT id FROM audit_logs WHERE action='demo_delete_employee' ORDER BY id DESC LIMIT 1), (SELECT id FROM users WHERE email='admin@hrms.local'), 'Demo reversal')
ON DUPLICATE KEY UPDATE reason=VALUES(reason);

-- Notifications
INSERT INTO notifications (user_id, message)
VALUES
 ((SELECT id FROM users WHERE email='hr@demo.local'), '[DEMO] HR task completed'),
 ((SELECT id FROM users WHERE email='emp1@demo.local'), '[DEMO] Welcome to the portal')
;

-- PDF Templates
INSERT INTO pdf_templates (report_key, settings)
VALUES
 ('demo_employee_profile', JSON_OBJECT('font','Helvetica','size',12,'title','Demo Employee Profile'))
ON DUPLICATE KEY UPDATE settings=VALUES(settings);

-- System logs
INSERT INTO system_logs (code, message, module, file, line, func, context)
VALUES
 ('DEMO1001','Demo system log entry','demo','/path/demo.php',10,'demoFunc','{"info":"demo"}')
;

-- Access Templates & Permissions
INSERT INTO access_templates (name, description) VALUES
 ('Demo - Read Only','Read-only access across modules')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO access_template_permissions (template_id, module, level)
VALUES
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'employees','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'departments','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'positions','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'attendance','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'leave','write'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'payroll','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'recruitment','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'documents','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'performance','read'),
 ((SELECT id FROM access_templates WHERE name='Demo - Read Only'), 'audit','read')
ON DUPLICATE KEY UPDATE level=VALUES(level);

-- Apply some user permissions
INSERT INTO user_access_permissions (user_id, module, level) VALUES
 ((SELECT id FROM users WHERE email='hr@demo.local'),'employees','admin'),
 ((SELECT id FROM users WHERE email='hr@demo.local'),'departments','admin'),
 ((SELECT id FROM users WHERE email='manager@demo.local'),'employees','write'),
 ((SELECT id FROM users WHERE email='emp1@demo.local'),'employees','read'),
 ((SELECT id FROM users WHERE email='emp1@demo.local'),'leave','write')
ON DUPLICATE KEY UPDATE level=VALUES(level);

-- Optional users_backup sample
INSERT INTO users_backup (id, email, password_hash, full_name, role, status, last_login, created_at, updated_at)
VALUES (90001, 'backupuser@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Backup User', 'employee', 'inactive', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Optional employees_backup sample
INSERT INTO employees_backup (id, user_id, employee_code, first_name, last_name, email, phone, address, department_id, position_id, hire_date, employment_type, status, salary, created_at, updated_at)
VALUES (90001, NULL, 'DEMO-BACKUP-EMP1', 'Back', 'Up', 'backup@demo.local', '+639199999999', 'Backup Addr', (SELECT id FROM departments WHERE name='Demo - IT'), (SELECT id FROM positions WHERE name='Demo - Software Engineer'), '2023-01-01','contract','terminated',10000.00, NOW(), NOW())
ON DUPLICATE KEY UPDATE employee_code=VALUES(employee_code);

-- Optional department/position backups
INSERT INTO departments_backup (id, name, description, created_at, updated_at)
VALUES (90001,'Demo - Dept Backup','Backup demo dept',NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO positions_backup (id, department_id, name, description, base_salary, created_at, updated_at)
VALUES (90001,(SELECT id FROM departments WHERE name='Demo - IT'),'Demo - Pos Backup','Backup demo pos',12345.67,NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- User access modules (optional demo)
INSERT INTO user_access_modules (user_id, module) VALUES
 ((SELECT id FROM users WHERE email='hr@demo.local'),'employees'),
 ((SELECT id FROM users WHERE email='hr@demo.local'),'departments')
ON DUPLICATE KEY UPDATE module=VALUES(module);

-- Final note
-- You can safely re-run this script to refresh demo data.
