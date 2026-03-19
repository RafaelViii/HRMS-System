-- HRMS Database Schema (PostgreSQL) – Unified and Up-to-date
-- Target: PostgreSQL 12+
-- This file consolidates the base schema plus all migrations/fixes as of 2025-09-05.
-- Notes:
-- - MySQL artifacts removed; this is PostgreSQL-only.
-- - ENUMs defined up-front (including v1.1 role extensions).
-- - Identity columns: GENERATED ALWAYS AS IDENTITY.
-- - JSON stored as jsonb.
-- - updated_at maintained via trigger set_updated_at() where applicable.
-- - Includes notification_reads and access control tables used by the app.

-- Optional: create database then connect (run manually as needed)
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
-- Enum types (with v1.1 role extensions)
-- =============================
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
    CREATE TYPE user_role AS ENUM (
      'admin','hr','employee','accountant','manager',
      'hr_supervisor','hr_recruit','hr_payroll','admin_assistant'
    );
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

-- Branches
CREATE TABLE IF NOT EXISTS branches (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(191) NOT NULL,
  address TEXT NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Users
CREATE TABLE IF NOT EXISTS users (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(191) NOT NULL,
  role user_role NOT NULL DEFAULT 'employee',
  status user_status NOT NULL DEFAULT 'active',
  branch_id INT NULL,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id)
    REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_users_branch ON users(branch_id);

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
  branch_id INT NULL,
  hire_date DATE NULL,
  employment_type employment_type DEFAULT 'regular',
  status employee_status DEFAULT 'active',
  salary NUMERIC(12,2) NOT NULL DEFAULT 0,
  profile_photo_path VARCHAR(255) NULL,
  profile_photo_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_employees_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_department FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_position FOREIGN KEY (position_id)
    REFERENCES positions(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_employees_branch FOREIGN KEY (branch_id)
    REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_employees_dept ON employees(department_id);
CREATE INDEX IF NOT EXISTS idx_employees_pos ON employees(position_id);
CREATE INDEX IF NOT EXISTS idx_employees_branch ON employees(branch_id);

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

CREATE TABLE IF NOT EXISTS memos (
  id BIGSERIAL PRIMARY KEY,
  memo_code VARCHAR(50) NOT NULL UNIQUE,
  header VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  issued_by_user_id INT NULL,
  issued_by_name VARCHAR(150) NOT NULL,
  issued_by_position VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  allow_downloads BOOLEAN NOT NULL DEFAULT FALSE,
  published_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memos_user FOREIGN KEY (issued_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS memo_recipients (
  id BIGSERIAL PRIMARY KEY,
  memo_id BIGINT NOT NULL,
  audience_type VARCHAR(20) NOT NULL,
  audience_identifier VARCHAR(100) NULL,
  audience_label VARCHAR(150) NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memo_recipient_memo FOREIGN KEY (memo_id)
    REFERENCES memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_memo_audience_type CHECK (audience_type IN ('all','department','employee','role'))
);
CREATE INDEX IF NOT EXISTS idx_memo_recipients_memo ON memo_recipients(memo_id);
CREATE INDEX IF NOT EXISTS idx_memo_recipients_type ON memo_recipients(audience_type, audience_identifier);

CREATE TABLE IF NOT EXISTS memo_attachments (
  id BIGSERIAL PRIMARY KEY,
  memo_id BIGINT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_size BIGINT NOT NULL DEFAULT 0,
  mime_type VARCHAR(100) NULL,
  description TEXT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memo_attachment_memo FOREIGN KEY (memo_id)
    REFERENCES memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_memo_attachment_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_memo_attachments_memo ON memo_attachments(memo_id);
CREATE INDEX IF NOT EXISTS idx_memo_attachments_uploaded ON memo_attachments(uploaded_at DESC);

CREATE OR REPLACE FUNCTION fn_memo_set_updated()
RETURNS trigger AS $MEMO$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$MEMO$ LANGUAGE plpgsql;

DO $MEMOTRG$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_memo_set_updated') THEN
    EXECUTE 'CREATE TRIGGER trg_memo_set_updated BEFORE UPDATE ON memos FOR EACH ROW EXECUTE FUNCTION fn_memo_set_updated()';
  END IF;
END;
$MEMOTRG$;

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

CREATE TABLE IF NOT EXISTS leave_request_attachments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  leave_request_id INT NOT NULL,
  document_type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_size BIGINT NOT NULL DEFAULT 0,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_attachment_request FOREIGN KEY (leave_request_id)
    REFERENCES leave_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_leave_attachment_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_leave_attachment_request ON leave_request_attachments(leave_request_id);
CREATE INDEX IF NOT EXISTS idx_leave_attachment_uploaded_at ON leave_request_attachments(uploaded_at DESC);

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
  details_raw TEXT NULL,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(150),
  body TEXT,
  message VARCHAR(255) NOT NULL,
  payload JSONB,
  is_read BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id);

-- Per-user read tracking for global notifications
CREATE TABLE IF NOT EXISTS notification_reads (
  notification_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id, user_id),
  CONSTRAINT fk_nr_notification FOREIGN KEY (notification_id)
    REFERENCES notifications(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_nr_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notification_reads_user ON notification_reads(user_id);

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
  code VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  module VARCHAR(50) NULL,
  file VARCHAR(255) NULL,
  line INT NULL,
  func VARCHAR(100) NULL,
  context TEXT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE IF EXISTS system_logs
  ADD COLUMN IF NOT EXISTS code VARCHAR(50),
  ADD COLUMN IF NOT EXISTS module VARCHAR(50),
  ADD COLUMN IF NOT EXISTS context TEXT;

DO $$
BEGIN
  IF to_regclass('public.system_logs') IS NOT NULL THEN
    BEGIN
      EXECUTE 'CREATE INDEX IF NOT EXISTS idx_system_logs_code ON system_logs (code)';
    EXCEPTION WHEN others THEN
      NULL;
    END;
    BEGIN
      EXECUTE 'CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON system_logs (created_at DESC)';
    EXCEPTION WHEN others THEN
      NULL;
    END;
    BEGIN
      EXECUTE 'CREATE INDEX IF NOT EXISTS idx_system_logs_module ON system_logs (module)';
    EXCEPTION WHEN others THEN
      NULL;
    END;
  END IF;
END $$;


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
-- Access Control Tables (Account module)
-- =============================

-- Per-user module permission levels
CREATE TABLE IF NOT EXISTS user_access_permissions (
  user_id INT NOT NULL,
  module VARCHAR(100) NOT NULL,
  level VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module),
  CONSTRAINT fk_uap_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_uap_level CHECK (level IN ('none','read','write','admin'))
);
CREATE INDEX IF NOT EXISTS idx_uap_user ON user_access_permissions(user_id);

-- Named permission templates
CREATE TABLE IF NOT EXISTS access_templates (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- Template permissions
CREATE TABLE IF NOT EXISTS access_template_permissions (
  template_id INT NOT NULL,
  module VARCHAR(100) NOT NULL,
  level VARCHAR(20) NOT NULL,
  PRIMARY KEY (template_id, module),
  CONSTRAINT fk_atp_tpl FOREIGN KEY (template_id)
    REFERENCES access_templates(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_atp_level CHECK (level IN ('none','read','write','admin'))
);

-- =============================
-- Triggers: set updated_at on change
-- =============================
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_users') THEN
    CREATE TRIGGER trg_set_updated_at_users BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_departments') THEN
    CREATE TRIGGER trg_set_updated_at_departments BEFORE UPDATE ON departments
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_positions') THEN
    CREATE TRIGGER trg_set_updated_at_positions BEFORE UPDATE ON positions
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_employees') THEN
    CREATE TRIGGER trg_set_updated_at_employees BEFORE UPDATE ON employees
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_documents') THEN
    CREATE TRIGGER trg_set_updated_at_documents BEFORE UPDATE ON documents
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_document_assignments') THEN
    CREATE TRIGGER trg_set_updated_at_document_assignments BEFORE UPDATE ON document_assignments
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_attendance') THEN
    CREATE TRIGGER trg_set_updated_at_attendance BEFORE UPDATE ON attendance
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_leave_requests') THEN
    CREATE TRIGGER trg_set_updated_at_leave_requests BEFORE UPDATE ON leave_requests
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_payroll_periods') THEN
    CREATE TRIGGER trg_set_updated_at_payroll_periods BEFORE UPDATE ON payroll_periods
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_payroll') THEN
    CREATE TRIGGER trg_set_updated_at_payroll BEFORE UPDATE ON payroll
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_performance_reviews') THEN
    CREATE TRIGGER trg_set_updated_at_performance_reviews BEFORE UPDATE ON performance_reviews
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_recruitment') THEN
    CREATE TRIGGER trg_set_updated_at_recruitment BEFORE UPDATE ON recruitment
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_audit_logs') THEN
    CREATE TRIGGER trg_set_updated_at_audit_logs BEFORE UPDATE ON audit_logs
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_notifications') THEN
    CREATE TRIGGER trg_set_updated_at_notifications BEFORE UPDATE ON notifications
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_set_updated_at_pdf_templates') THEN
    CREATE TRIGGER trg_set_updated_at_pdf_templates BEFORE UPDATE ON pdf_templates
    FOR EACH ROW EXECUTE PROCEDURE set_updated_at();
  END IF;
END $$;

-- =============================
-- Seed (minimal, idempotent)
-- =============================
DO $$
BEGIN
  BEGIN
    INSERT INTO users (email, password_hash, full_name, role)
    VALUES (
      'admin@hrms.local',
      '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a',
      'System Admin',
      'admin'
    ) ON CONFLICT (email) DO NOTHING;
  EXCEPTION
    WHEN undefined_column THEN
      BEGIN
        INSERT INTO users (email, password, full_name, role)
        VALUES (
          'admin@hrms.local',
          '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a',
          'System Admin',
          'admin'
        ) ON CONFLICT (email) DO NOTHING;
      EXCEPTION
        WHEN others THEN
          RAISE NOTICE 'Admin seed skipped: %', SQLERRM;
      END;
  END;
END $$;

-- Default access template example used by Account module UI (safe to re-run)
INSERT INTO access_templates (name, description)
VALUES ('Default Employee', 'Basic read access to personal modules')
ON CONFLICT (name) DO NOTHING;

INSERT INTO access_template_permissions (template_id, module, level)
SELECT t.id, v.module, v.level
FROM access_templates t
JOIN (
  VALUES
    ('employees','read'),
    ('attendance','read'),
    ('documents','read')
) AS v(module, level) ON t.name = 'Default Employee'
ON CONFLICT (template_id, module) DO NOTHING;

-- =============================
-- Backup tables (mirrors of core tables)
-- =============================
CREATE TABLE IF NOT EXISTS employees_backup (LIKE employees INCLUDING ALL);
CREATE TABLE IF NOT EXISTS payroll_backup (LIKE payroll INCLUDING ALL);
CREATE TABLE IF NOT EXISTS leave_requests_backup (LIKE leave_requests INCLUDING ALL);
CREATE TABLE IF NOT EXISTS departments_backup (LIKE departments INCLUDING ALL);
CREATE TABLE IF NOT EXISTS positions_backup (LIKE positions INCLUDING ALL);
CREATE TABLE IF NOT EXISTS users_backup (LIKE users INCLUDING ALL);

-- =============================
-- Schema alignment for existing DBs (idempotent)
-- =============================
-- Harmonize legacy user columns with current HRMS expectations
DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'password'
      )
     AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'password_hash'
      ) THEN
    EXECUTE 'ALTER TABLE public.users RENAME COLUMN password TO password_hash';
  END IF;

  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'name'
      )
     AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'full_name'
      ) THEN
    EXECUTE 'ALTER TABLE public.users RENAME COLUMN name TO full_name';
  END IF;

  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'last_login_at'
      )
     AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'last_login'
      ) THEN
    EXECUTE 'ALTER TABLE public.users RENAME COLUMN last_login_at TO last_login';
  END IF;
END $$;

ALTER TABLE IF EXISTS users
  ADD COLUMN IF NOT EXISTS full_name VARCHAR(191),
  ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255),
  ADD COLUMN IF NOT EXISTS last_login TIMESTAMP WITHOUT TIME ZONE,
  ADD COLUMN IF NOT EXISTS branch_id INT;

DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'password'
      ) THEN
    EXECUTE $$UPDATE public.users SET password_hash = password WHERE password_hash IS NULL OR password_hash = ''$$;
  END IF;

  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'name'
      ) THEN
    EXECUTE $$UPDATE public.users SET full_name = name WHERE (full_name IS NULL OR full_name = '') AND name IS NOT NULL$$;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'role' AND udt_name <> 'user_role'
      ) THEN
    UPDATE public.users
       SET role = LOWER(role)
     WHERE role IS NOT NULL;
    UPDATE public.users
       SET role = 'employee'
     WHERE role IS NULL OR role NOT IN ('admin','hr','employee','accountant','manager','hr_supervisor','hr_recruit','hr_payroll','admin_assistant');
    BEGIN
      ALTER TABLE public.users
        ALTER COLUMN role TYPE user_role USING role::user_role;
      ALTER TABLE public.users
        ALTER COLUMN role SET DEFAULT 'employee';
    EXCEPTION WHEN others THEN
      NULL;
    END;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'status' AND udt_name <> 'user_status'
      ) THEN
    UPDATE public.users
       SET status = LOWER(status)
     WHERE status IS NOT NULL;
    UPDATE public.users
       SET status = 'active'
     WHERE status IS NULL OR status NOT IN ('active','inactive');
    BEGIN
      ALTER TABLE public.users
        ALTER COLUMN status TYPE user_status USING status::user_status;
      ALTER TABLE public.users
        ALTER COLUMN status SET DEFAULT 'active';
    EXCEPTION WHEN others THEN
      NULL;
    END;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'last_login' AND data_type = 'timestamp with time zone'
      ) THEN
    BEGIN
      ALTER TABLE public.users
        ALTER COLUMN last_login TYPE timestamp without time zone USING last_login AT TIME ZONE 'UTC';
    EXCEPTION WHEN others THEN
      NULL;
    END;
  END IF;
END $$;

DO $$
BEGIN
  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'full_name'
      ) THEN
    UPDATE public.users
       SET full_name = COALESCE(NULLIF(full_name, ''), email)
     WHERE full_name IS NULL OR full_name = '';
  END IF;

  IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'password_hash'
      ) THEN
    UPDATE public.users
       SET password_hash = '$2y$10$Jm5tGv0aKw3t4IzGzV1xLe9UIkZRh0jC6oOt0mM5Pj0q9VvE7YVIO',
           status = 'inactive'
     WHERE password_hash IS NULL OR password_hash = '';
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_users_branch' AND conrelid = 'public.users'::regclass
      ) THEN
    BEGIN
      ALTER TABLE public.users
        ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id)
        REFERENCES branches(id) ON DELETE SET NULL ON UPDATE CASCADE;
    EXCEPTION WHEN others THEN
      NULL;
    END;
  END IF;
END $$;

-- Ensure audit_logs.details_raw exists (for older DBs)
ALTER TABLE IF EXISTS audit_logs
  ADD COLUMN IF NOT EXISTS details_raw TEXT NULL;

-- Ensure recruitment extended columns exist
ALTER TABLE IF EXISTS recruitment
  ADD COLUMN IF NOT EXISTS template_id INT NULL;

ALTER TABLE IF EXISTS recruitment
  ADD COLUMN IF NOT EXISTS converted_employee_id INT NULL;

-- Ensure users.role default remains 'employee'
ALTER TABLE IF EXISTS users ALTER COLUMN role SET DEFAULT 'employee';

-- Optional: migration registry table (used by tools/migrate.php)
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(255) PRIMARY KEY,
  checksum VARCHAR(64) NOT NULL,
  applied_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
