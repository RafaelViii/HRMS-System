-- Migration: Add department_supervisors table for leave approval workflow
-- Date: 2025-11-10
-- Purpose: Allow departments to have multiple supervisors who can approve leave requests
--          for employees in their department (or other departments if override is enabled)

-- Create department_supervisors table
CREATE TABLE IF NOT EXISTS department_supervisors (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  department_id INT NOT NULL,
  supervisor_user_id INT NOT NULL,
  is_override BOOLEAN NOT NULL DEFAULT FALSE, -- TRUE if supervising a dept they don't belong to
  assigned_by INT NULL, -- user_id of admin who assigned
  assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dept_supervisors_dept FOREIGN KEY (department_id)
    REFERENCES departments(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dept_supervisors_user FOREIGN KEY (supervisor_user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_dept_supervisors_assigned_by FOREIGN KEY (assigned_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT uq_dept_supervisor UNIQUE (department_id, supervisor_user_id)
);

CREATE INDEX IF NOT EXISTS idx_dept_supervisors_dept ON department_supervisors(department_id);
CREATE INDEX IF NOT EXISTS idx_dept_supervisors_user ON department_supervisors(supervisor_user_id);

COMMENT ON TABLE department_supervisors IS 'Maps supervisors to departments for leave approval workflow';
COMMENT ON COLUMN department_supervisors.is_override IS 'TRUE when supervisor is from a different department (cross-department supervision)';
COMMENT ON COLUMN department_supervisors.assigned_by IS 'Admin/HR user who assigned this supervisor';
