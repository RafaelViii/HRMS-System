-- v1.1 schema fix: expand user_role enum in-place (no type drop) to avoid dependency issues.

DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
    -- Add new values if they are not present yet. Use IF NOT EXISTS for idempotency.
    BEGIN
      ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'hr_supervisor';
    EXCEPTION WHEN duplicate_object THEN NULL; END;
    BEGIN
      ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'hr_recruit';
    EXCEPTION WHEN duplicate_object THEN NULL; END;
    BEGIN
      ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'hr_payroll';
    EXCEPTION WHEN duplicate_object THEN NULL; END;
    BEGIN
      ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'admin_assistant';
    EXCEPTION WHEN duplicate_object THEN NULL; END;

    -- Ensure default remains 'employee'
    ALTER TABLE IF EXISTS users ALTER COLUMN role SET DEFAULT 'employee';
  ELSE
    -- If the type does not exist (fresh env), create it with the full set
    CREATE TYPE user_role AS ENUM (
      'admin','hr','employee','accountant','manager',
      'hr_supervisor','hr_recruit','hr_payroll','admin_assistant'
    );
    -- Apply to users.role if the table/column exist
    ALTER TABLE IF EXISTS users ALTER COLUMN role TYPE user_role USING role::text::user_role;
    ALTER TABLE IF EXISTS users ALTER COLUMN role SET DEFAULT 'employee';
  END IF;
END $$;
