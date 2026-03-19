-- Schema Fix v1 (PostgreSQL)
-- Purpose: Add missing access control tables used by Account module
-- Safe to run multiple times (IF NOT EXISTS used); targets PostgreSQL 12+

-- user_access_permissions: per-user module permission levels
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

-- access_templates: named bundles of permissions
CREATE TABLE IF NOT EXISTS access_templates (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

-- access_template_permissions: permissions per template
CREATE TABLE IF NOT EXISTS access_template_permissions (
  template_id INT NOT NULL,
  module VARCHAR(100) NOT NULL,
  level VARCHAR(20) NOT NULL,
  PRIMARY KEY (template_id, module),
  CONSTRAINT fk_atp_tpl FOREIGN KEY (template_id)
    REFERENCES access_templates(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_atp_level CHECK (level IN ('none','read','write','admin'))
);

-- Optional seed: a minimal template example (safe on re-run)
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
