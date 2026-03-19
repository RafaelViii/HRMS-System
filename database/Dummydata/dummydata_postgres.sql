-- Demo data for HRMS (PostgreSQL-safe)
-- Run against a Postgres DB with schema_postgres.sql applied

-- Clean previous demo data (best-effort, FK-safe order)
DO $$ BEGIN
  PERFORM 1;
EXCEPTION WHEN others THEN
  -- ignore
END $$;

-- Minimal demo departments/positions
INSERT INTO departments (name, description)
VALUES
 ('Demo - HR', 'Demo Human Resources')
ON CONFLICT (name) DO UPDATE SET description = EXCLUDED.description;

INSERT INTO positions (department_id, name, description, base_salary)
VALUES
 ((SELECT id FROM departments WHERE name='Demo - HR'), 'Demo - HR Specialist', 'Handles HR tasks', 35000.00)
ON CONFLICT DO NOTHING;

-- Demo users
INSERT INTO users (email, password_hash, full_name, role, status)
VALUES ('hr@demo.local', '$2y$10$gO7xvL2OQdFQy8OdK4qZqOqkmuJf1b0XnQJdVfQbzi9cL7s6J8M2a', 'Demo HR', 'hr', 'active')
ON CONFLICT (email) DO NOTHING;

-- Demo employee
INSERT INTO employees (user_id, employee_code, first_name, last_name, email, department_id, position_id, employment_type, status, salary)
VALUES (NULL, 'DEMO-EMP-001', 'Demo', 'One', 'emp1@demo.local',
        (SELECT id FROM departments WHERE name='Demo - HR'),
        (SELECT id FROM positions WHERE name='Demo - HR Specialist'), 'regular', 'active', 36000.00)
ON CONFLICT (employee_code) DO NOTHING;
