-- Roles meta permissions table unifying access templates into roles
CREATE TABLE IF NOT EXISTS roles_meta_permissions (
  role_name VARCHAR(100) NOT NULL,
  module VARCHAR(100) NOT NULL,
  level VARCHAR(20) NOT NULL CHECK (level IN ('none','read','write','admin')),
  PRIMARY KEY (role_name, module),
  CONSTRAINT fk_rmp_role FOREIGN KEY (role_name)
    REFERENCES roles_meta(role_name) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Optionally seed from access_template_permissions if a template name matches a role name
INSERT INTO roles_meta_permissions (role_name, module, level)
SELECT rm.role_name, atp.module, atp.level
FROM roles_meta rm
JOIN access_templates t ON LOWER(t.name)=LOWER(rm.role_name)
JOIN access_template_permissions atp ON atp.template_id = t.id
ON CONFLICT (role_name, module) DO NOTHING;
