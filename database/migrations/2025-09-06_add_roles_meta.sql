-- Create roles_meta table to manage role metadata and activation state
CREATE TABLE IF NOT EXISTS roles_meta (
  role_name VARCHAR(100) PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  description TEXT NULL,
  is_active SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed existing enum roles into roles_meta if missing
INSERT INTO roles_meta (role_name,label,description)
SELECT v, INITCAP(REPLACE(v,'_',' ')) as lbl, NULL
FROM (
  VALUES
    ('admin'),('hr'),('employee'),('accountant'),('manager'),
    ('hr_supervisor'),('hr_recruit'),('hr_payroll'),('admin_assistant')
) t(v)
WHERE NOT EXISTS (SELECT 1 FROM roles_meta m WHERE m.role_name = t.v);

-- Trigger + function to maintain updated_at (use distinct dollar tags to avoid nesting issues)
CREATE OR REPLACE FUNCTION fn_roles_meta_set_updated()
RETURNS trigger AS $ROLEUPD$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$ROLEUPD$ LANGUAGE plpgsql;

DO $ROLETRIG$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_roles_meta_updated') THEN
    EXECUTE 'CREATE TRIGGER trg_roles_meta_updated BEFORE UPDATE ON roles_meta FOR EACH ROW EXECUTE FUNCTION fn_roles_meta_set_updated()';
  END IF;
END;
$ROLETRIG$;
