-- Branch rollout for branch-aware human resources flows
-- Date: 2025-10-23

BEGIN;

-- Ensure branches reference table exists for new installs or environments that skipped payroll foundation
CREATE TABLE IF NOT EXISTS branches (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    address TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- users.branch_id
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS branch_id INT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.table_constraints
         WHERE constraint_name = 'fk_users_branch'
           AND table_schema = current_schema()
           AND table_name = 'users'
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT fk_users_branch
            FOREIGN KEY (branch_id)
            REFERENCES branches (id)
            ON DELETE SET NULL
            ON UPDATE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_users_branch ON users (branch_id);

-- employees.branch_id
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS branch_id INT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.table_constraints
         WHERE constraint_name = 'fk_employees_branch'
           AND table_schema = current_schema()
           AND table_name = 'employees'
    ) THEN
        ALTER TABLE employees
            ADD CONSTRAINT fk_employees_branch
            FOREIGN KEY (branch_id)
            REFERENCES branches (id)
            ON DELETE SET NULL
            ON UPDATE CASCADE;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_employees_branch ON employees (branch_id);

-- Seed default branch and align existing records
DO $$
DECLARE
    default_branch_id INT;
BEGIN
    INSERT INTO branches (code, name, address)
    SELECT 'QC', 'Quezon City', 'Quezon City'
    WHERE NOT EXISTS (SELECT 1 FROM branches WHERE LOWER(code) = 'qc');

    SELECT id INTO default_branch_id
      FROM branches
     WHERE LOWER(code) = 'qc'
     ORDER BY id
     LIMIT 1;

    IF default_branch_id IS NULL THEN
        SELECT id INTO default_branch_id
          FROM branches
         ORDER BY id
         LIMIT 1;
    END IF;

    IF default_branch_id IS NOT NULL THEN
        UPDATE users
           SET branch_id = default_branch_id,
               updated_at = CURRENT_TIMESTAMP
         WHERE branch_id IS NULL;

        UPDATE employees
           SET branch_id = default_branch_id,
               updated_at = CURRENT_TIMESTAMP
         WHERE branch_id IS NULL;
    END IF;
END $$;

COMMIT;
