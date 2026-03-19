-- HRMS Database Schema (MySQL)
-- Database: hrms

CREATE DATABASE IF NOT EXISTS hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hrms;

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(191) NOT NULL,
  role ENUM('admin','hr','employee','accountant','manager') NOT NULL DEFAULT 'employee',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Departments
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Positions
CREATE TABLE IF NOT EXISTS positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NULL,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_positions_department ON positions(department_id);

-- Employees
CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  employee_code VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(191) NOT NULL,
  phone VARCHAR(50) NULL,
  address TEXT NULL,
  department_id INT NULL,
  position_id INT NULL,
  hire_date DATE NULL,
  employment_type ENUM('regular','probationary','contract','part-time') DEFAULT 'regular',
  status ENUM('active','terminated','resigned','on-leave') DEFAULT 'active',
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_employees_dept ON employees(department_id);
CREATE INDEX idx_employees_pos ON employees(position_id);

-- Documents & Memos
CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(191) NOT NULL,
  doc_type ENUM('memo','contract','policy','other') NOT NULL DEFAULT 'memo',
  file_path VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_documents_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  employee_id INT NULL,
  department_id INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_assign_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_doc_assign_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_doc_assign_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Attendance
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  date DATE NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  overtime_minutes INT NOT NULL DEFAULT 0,
  status ENUM('present','absent','late','on-leave','holiday') DEFAULT 'present',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uniq_attendance_employee_date (employee_id, date)
) ENGINE=InnoDB;
CREATE INDEX idx_attendance_employee ON attendance(employee_id);
CREATE INDEX idx_attendance_date ON attendance(date);

-- Leave Requests
CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  leave_type ENUM('sick','vacation','emergency','unpaid','other') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_days DECIMAL(5,2) NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  remarks TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_leave_employee ON leave_requests(employee_id);
CREATE INDEX idx_leave_status ON leave_requests(status);

-- Payroll
CREATE TABLE IF NOT EXISTS payroll_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('open','processed','released') DEFAULT 'open',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  period_id INT NOT NULL,
  basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  released_at DATETIME NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payroll_period FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uniq_employee_period (employee_id, period_id)
) ENGINE=InnoDB;
CREATE INDEX idx_payroll_employee ON payroll(employee_id);
CREATE INDEX idx_payroll_period ON payroll(period_id);

-- Performance Reviews
CREATE TABLE IF NOT EXISTS performance_reviews (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  review_date DATE NOT NULL,
  kpi_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  remarks TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_perf_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_perf_employee ON performance_reviews(employee_id);

-- Recruitment
CREATE TABLE IF NOT EXISTS recruitment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,
  position_applied VARCHAR(191) NULL,
  template_id INT NULL,
  converted_employee_id INT NULL,
  resume_path VARCHAR(255) NULL,
  status ENUM('new','shortlist','interviewed','hired','rejected') DEFAULT 'new',
  notes TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(191) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_audit_user ON audit_logs(user_id);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_notifications_user ON notifications(user_id);

-- Seed admin user (password: Admin@123)
-- Note: If a user with this email already exists, the password is NOT overwritten by this seed.
-- Use tools/reset_admin.php or the login page bootstrap to (re)initialize the admin if needed.
INSERT INTO users (email, password_hash, full_name, role) VALUES (
  'admin@hrms.local',
  '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a',
  'System Admin',
  'admin'
) ON DUPLICATE KEY UPDATE email=email;

-- Backup tables (mirror schemas of main tables)
-- Note: These are used to store records before deletion for recovery.
CREATE TABLE IF NOT EXISTS employees_backup LIKE employees;
CREATE TABLE IF NOT EXISTS payroll_backup LIKE payroll;
CREATE TABLE IF NOT EXISTS leave_requests_backup LIKE leave_requests;
CREATE TABLE IF NOT EXISTS departments_backup LIKE departments;
CREATE TABLE IF NOT EXISTS positions_backup LIKE positions;
CREATE TABLE IF NOT EXISTS users_backup LIKE users;

-- PDF Templates (configurable report formats)
CREATE TABLE IF NOT EXISTS pdf_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_key VARCHAR(100) NOT NULL UNIQUE,
  settings JSON NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- System Logs (technical errors, admin-only view)
CREATE TABLE IF NOT EXISTS system_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  module VARCHAR(100) NULL,
  file VARCHAR(255) NULL,
  line INT NULL,
  func VARCHAR(100) NULL,
  context TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE INDEX idx_system_logs_code ON system_logs(code);
CREATE INDEX idx_system_logs_created ON system_logs(created_at);

-- Action Reversals (tracks reversed audit entries; prevents double reversal)
CREATE TABLE IF NOT EXISTS action_reversals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  audit_log_id INT NOT NULL UNIQUE,
  reversed_by INT NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ar_audit FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ar_user FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Recruitment templates and requirements
CREATE TABLE IF NOT EXISTS recruitment_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Which basic fields are required by template (from: full_name, email, phone, position_applied)
CREATE TABLE IF NOT EXISTS recruitment_template_fields (
  template_id INT NOT NULL,
  field_name VARCHAR(50) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (template_id, field_name),
  CONSTRAINT fk_rtf_tpl FOREIGN KEY (template_id) REFERENCES recruitment_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Required file labels by template (e.g., Resume/CV, Portfolio)
CREATE TABLE IF NOT EXISTS recruitment_template_files (
  template_id INT NOT NULL,
  label VARCHAR(100) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (template_id, label),
  CONSTRAINT fk_rtf2_tpl FOREIGN KEY (template_id) REFERENCES recruitment_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Multiple files per applicant with labels
CREATE TABLE IF NOT EXISTS recruitment_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recruitment_id INT NOT NULL,
  label VARCHAR(100) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_files_app FOREIGN KEY (recruitment_id) REFERENCES recruitment(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rec_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Decision history for leave requests (approvals/declines)
CREATE TABLE IF NOT EXISTS leave_request_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  leave_request_id INT NOT NULL,
  action ENUM('approved','rejected') NOT NULL,
  reason TEXT NULL,
  acted_by INT NULL,
  acted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lra_leave FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lra_user FOREIGN KEY (acted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Schema alignment for existing installations (portable)
-- Ensure the users.status column exists without failing on older MySQL
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'status'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN status ENUM(''active'',''inactive'') NOT NULL DEFAULT ''active'' AFTER role',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure recruitment.template_id exists for template tracking
SET @rec_tpl_col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'recruitment'
    AND COLUMN_NAME = 'template_id'
);
SET @ddl2 := IF(@rec_tpl_col_exists = 0,
  'ALTER TABLE recruitment ADD COLUMN template_id INT NULL AFTER position_applied',
  'SELECT 1'
);
PREPARE stmt2 FROM @ddl2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- Ensure recruitment.converted_employee_id exists for transition tracking
SET @rec_emp_col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'recruitment'
    AND COLUMN_NAME = 'converted_employee_id'
);
SET @ddl3 := IF(@rec_emp_col_exists = 0,
  'ALTER TABLE recruitment ADD COLUMN converted_employee_id INT NULL AFTER template_id',
  'SELECT 1'
);
PREPARE stmt3 FROM @ddl3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
