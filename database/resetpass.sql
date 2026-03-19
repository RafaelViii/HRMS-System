-- Reset/create default admin (PostgreSQL)
CREATE EXTENSION IF NOT EXISTS pgcrypto;

INSERT INTO users (email, password_hash, full_name, role, status)
VALUES (
  'admin@hrms.local',
  crypt('Admin@123', gen_salt('bf')),
  'System Admin',
  'admin',
  'active'
)
ON CONFLICT (email) DO UPDATE
SET password_hash = EXCLUDED.password_hash,
    full_name     = COALESCE(users.full_name, 'System Admin'),
    role          = 'admin',
    status        = 'active';