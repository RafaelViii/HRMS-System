-- HRMS Database Schema (PostgreSQL)
-- Target DB: PostgreSQL 12+
-- Notes:
-- - Converted from MySQL schema (database/schema.sql)
-- - MySQL ENUMs mapped to PostgreSQL ENUM types
-- - AUTO_INCREMENT mapped to GENERATED ALWAYS AS IDENTITY
-- - updated_at auto-update implemented via a reusable trigger
-- - JSON switched to jsonb (preferred in Postgres)
-- - Seed uses ON CONFLICT DO NOTHING (replaces MySQL's ON DUPLICATE KEY)
-- - Optional idempotent ALTERs included at bottom to align existing DBs

-- Optional: create database, then connect
-- CREATE DATABASE hrms WITH ENCODING 'UTF8';
-- \c hrms

-- =============================
-- Helper: updated_at trigger
-- =============================
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS trigger AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================
-- Enum types
-- =============================
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
    CREATE TYPE user_role AS ENUM ('admin','hr','employee','accountant','manager');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_status') THEN
    CREATE TYPE user_status AS ENUM ('active','inactive');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'employment_type') THEN
    CREATE TYPE employment_type AS ENUM ('regular','probationary','contract','part-time');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'employee_status') THEN
    CREATE TYPE employee_status AS ENUM ('active','terminated','resigned','on-leave');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'doc_type') THEN
    CREATE TYPE doc_type AS ENUM ('memo','contract','policy','other');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'attendance_status') THEN
    CREATE TYPE attendance_status AS ENUM ('present','absent','late','on-leave','holiday');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'leave_type') THEN
    CREATE TYPE leave_type AS ENUM ('sick','vacation','emergency','unpaid','other');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'payroll_status') THEN
    CREATE TYPE payroll_status AS ENUM ('open','processed','released');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'recruitment_status') THEN
    CREATE TYPE recruitment_status AS ENUM ('new','shortlist','interviewed','hired','rejected');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'leave_action') THEN
    CREATE TYPE leave_action AS ENUM ('approved','rejected');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'leave_request_status') THEN
    CREATE TYPE leave_request_status AS ENUM ('pending','approved','rejected','cancelled');
  END IF;
END $$;

-- =============================
-- Tables
-- =============================

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(191) NOT NULL,
  role user_role NOT NULL DEFAULT 'employee',
  status user_status NOT NULL DEFAULT 'active',
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Departments
CREATE TABLE IF NOT EXISTS departments (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Positions
CREATE TABLE IF NOT EXISTS positions (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  department_id INT NULL,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  base_salary NUMERIC(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_positions_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_positions_department ON positions(department_id);

-- Employees
CREATE TABLE IF NOT EXISTS employees (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
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
  employment_type employment_type DEFAULT 'regular',
  status employee_status DEFAULT 'active',
  salary NUMERIC(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_position FOREIGN KEY (position_id)
    REFERENCES positions(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_employees_dept ON employees(department_id);
CREATE INDEX IF NOT EXISTS idx_employees_pos ON employees(position_id);

-- Documents & Memos
CREATE TABLE IF NOT EXISTS documents (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  title VARCHAR(191) NOT NULL,
  doc_type doc_type NOT NULL DEFAULT 'memo',
  file_path VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_documents_user FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS document_assignments (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  document_id INT NOT NULL,
  employee_id INT NULL,
  department_id INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_assign_document FOREIGN KEY (document_id)
    REFERENCES documents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_doc_assign_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_doc_assign_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Attendance
CREATE TABLE IF NOT EXISTS attendance (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id INT NOT NULL,
  date DATE NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  overtime_minutes INT NOT NULL DEFAULT 0,
  status attendance_status DEFAULT 'present',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uniq_attendance_employee_date UNIQUE (employee_id, date)
);
CREATE INDEX IF NOT EXISTS idx_attendance_employee ON attendance(employee_id);
CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance(date);

-- Leave Requests
CREATE TABLE IF NOT EXISTS leave_requests (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id INT NOT NULL,
  leave_type leave_type NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  total_days NUMERIC(5,2) NOT NULL,
  status leave_request_status NOT NULL DEFAULT 'pending',
  remarks TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_leave_employee ON leave_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_leave_status ON leave_requests(status);

-- Payroll Periods
CREATE TABLE IF NOT EXISTS payroll_periods (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status payroll_status DEFAULT 'open',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Payroll
CREATE TABLE IF NOT EXISTS payroll (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id INT NOT NULL,
  period_id INT NOT NULL,
  basic_pay NUMERIC(12,2) NOT NULL DEFAULT 0,
  allowances NUMERIC(12,2) NOT NULL DEFAULT 0,
  deductions NUMERIC(12,2) NOT NULL DEFAULT 0,
  net_pay NUMERIC(12,2) NOT NULL DEFAULT 0,
  released_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_payroll_period FOREIGN KEY (period_id)
    REFERENCES payroll_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uniq_employee_period UNIQUE (employee_id, period_id)
);
CREATE INDEX IF NOT EXISTS idx_payroll_employee ON payroll(employee_id);
CREATE INDEX IF NOT EXISTS idx_payroll_period ON payroll(period_id);

-- Performance Reviews
CREATE TABLE IF NOT EXISTS performance_reviews (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id INT NOT NULL,
  review_date DATE NOT NULL,
  kpi_score NUMERIC(5,2) NOT NULL DEFAULT 0,
  remarks TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_perf_employee FOREIGN KEY (employee_id)
    REFERENCES employees(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_perf_employee ON performance_reviews(employee_id);

-- Recruitment
CREATE TABLE IF NOT EXISTS recruitment (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  full_name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,
  position_applied VARCHAR(191) NULL,
  template_id INT NULL,
  converted_employee_id INT NULL,
  resume_path VARCHAR(255) NULL,
  status recruitment_status DEFAULT 'new',
  notes TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(191) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INT NULL,
  message VARCHAR(255) NOT NULL,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);

-- PDF Templates
CREATE TABLE IF NOT EXISTS pdf_templates (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  report_key VARCHAR(100) NOT NULL UNIQUE,
  settings JSONB NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- System Logs
CREATE TABLE IF NOT EXISTS system_logs (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  module VARCHAR(100) NULL,
  file VARCHAR(255) NULL,
  line INT NULL,
  func VARCHAR(100) NULL,
  context TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_system_logs_code ON system_logs(code);
CREATE INDEX IF NOT EXISTS idx_system_logs_created ON system_logs(created_at);

-- Action Reversals
CREATE TABLE IF NOT EXISTS action_reversals (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  audit_log_id INT NOT NULL UNIQUE,
  reversed_by INT NOT NULL,
  reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ar_audit FOREIGN KEY (audit_log_id)
    REFERENCES audit_logs(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ar_user FOREIGN KEY (reversed_by)
    REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Recruitment templates and requirements
CREATE TABLE IF NOT EXISTS recruitment_templates (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(191) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Which basic fields are required by template
CREATE TABLE IF NOT EXISTS recruitment_template_fields (
  template_id INT NOT NULL,
  field_name VARCHAR(50) NOT NULL,
  is_required SMALLINT NOT NULL DEFAULT 1,
  PRIMARY KEY (template_id, field_name),
  CONSTRAINT fk_rtf_tpl FOREIGN KEY (template_id)
    REFERENCES recruitment_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Required file labels by template
CREATE TABLE IF NOT EXISTS recruitment_template_files (
  template_id INT NOT NULL,
  label VARCHAR(100) NOT NULL,
  is_required SMALLINT NOT NULL DEFAULT 1,
  PRIMARY KEY (template_id, label),
  CONSTRAINT fk_rtf2_tpl FOREIGN KEY (template_id)
    REFERENCES recruitment_templates(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Multiple files per applicant with labels
CREATE TABLE IF NOT EXISTS recruitment_files (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  recruitment_id INT NOT NULL,
  label VARCHAR(100) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_by INT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_files_app FOREIGN KEY (recruitment_id)
    REFERENCES recruitment(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_rec_files_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Decision history for leave requests
CREATE TABLE IF NOT EXISTS leave_request_actions (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  leave_request_id INT NOT NULL,
  action leave_action NOT NULL,
  reason TEXT NULL,
  acted_by INT NULL,
  acted_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lra_leave FOREIGN KEY (leave_request_id)
    REFERENCES leave_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lra_user FOREIGN KEY (acted_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- =============================
-- Triggers: set updated_at on change
-- =============================
DO $$ BEGIN
  -- users
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_users'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_users
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- departments
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_departments'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_departments
    BEFORE UPDATE ON departments
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- positions
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_positions'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_positions
    BEFORE UPDATE ON positions
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- employees
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_employees'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_employees
    BEFORE UPDATE ON employees
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- documents
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_documents'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_documents
    BEFORE UPDATE ON documents
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- document_assignments
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_document_assignments'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_document_assignments
    BEFORE UPDATE ON document_assignments
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- attendance
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_attendance'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_attendance
    BEFORE UPDATE ON attendance
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- leave_requests
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_leave_requests'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_leave_requests
    BEFORE UPDATE ON leave_requests
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- payroll_periods
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_payroll_periods'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_payroll_periods
    BEFORE UPDATE ON payroll_periods
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- payroll
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_payroll'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_payroll
    BEFORE UPDATE ON payroll
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- performance_reviews
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_performance_reviews'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_performance_reviews
    BEFORE UPDATE ON performance_reviews
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- recruitment
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_recruitment'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_recruitment
    BEFORE UPDATE ON recruitment
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- audit_logs
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_audit_logs'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_audit_logs
    BEFORE UPDATE ON audit_logs
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- notifications
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_notifications'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_notifications
    BEFORE UPDATE ON notifications
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  -- pdf_templates
  IF NOT EXISTS (
    SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_pdf_templates'
  ) THEN
    CREATE TRIGGER trg_set_updated_at_pdf_templates
    BEFORE UPDATE ON pdf_templates
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
END $$;

-- =============================
-- Seed admin user (password: Admin@123)
-- =============================
INSERT INTO users (email, password_hash, full_name, role)
VALUES (
  'admin@hrms.local',
  '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a',
  'System Admin',
  'admin'
) ON CONFLICT (email) DO NOTHING;

-- =============================
-- Backup tables (mirror schemas of main tables)
-- =============================
CREATE TABLE IF NOT EXISTS employees_backup (LIKE employees INCLUDING ALL);
CREATE TABLE IF NOT EXISTS payroll_backup (LIKE payroll INCLUDING ALL);
CREATE TABLE IF NOT EXISTS leave_requests_backup (LIKE leave_requests INCLUDING ALL);
CREATE TABLE IF NOT EXISTS departments_backup (LIKE departments INCLUDING ALL);
CREATE TABLE IF NOT EXISTS positions_backup (LIKE positions INCLUDING ALL);
CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL);

-- =============================
-- Alignment for existing installations (idempotent)
-- =============================
-- Ensure users.status exists
ALTER TABLE IF EXISTS users
  ADD COLUMN IF NOT EXISTS status user_status NOT NULL DEFAULT 'active';

-- Ensure recruitment.template_id exists
ALTER TABLE IF EXISTS recruitment
  ADD COLUMN IF NOT EXISTS template_id INT NULL;

-- Ensure recruitment.converted_employee_id exists
ALTER TABLE IF EXISTS recruitment
  ADD COLUMN IF NOT EXISTS converted_employee_id INT NULL;
