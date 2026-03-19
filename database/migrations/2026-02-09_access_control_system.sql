-- Access Control System: whitelist/blacklist with device binding
-- Feature is OFF by default. Enable via admin settings page.

-- 1. Settings table for feature configuration
CREATE TABLE IF NOT EXISTS access_control_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings (system OFF)
INSERT INTO access_control_settings (setting_key, setting_value, description) VALUES
    ('enabled', 'false', 'Master switch for the access control system. When false, no rules are enforced.'),
    ('enforcement_mode', 'blacklist', 'Default enforcement mode: blacklist (block listed), whitelist (allow only listed), or both (whitelist takes precedence).'),
    ('device_binding_enabled', 'false', 'When true, registered devices can be bound to specific user accounts.'),
    ('module_restriction_enabled', 'false', 'When true, device-to-module access rules are enforced.'),
    ('unregistered_device_action', 'allow', 'What happens when an unregistered device accesses the system: allow, log, or block.'),
    ('override_duration_minutes', '60', 'Default duration in minutes for admin overrides.'),
    ('cache_ttl_seconds', '300', 'How long access rules are cached in session (seconds).')
ON CONFLICT (setting_key) DO NOTHING;

-- 2. Registered devices
CREATE TABLE IF NOT EXISTS device_fingerprints (
    id SERIAL PRIMARY KEY,
    fingerprint_hash VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(255) NOT NULL DEFAULT 'Unknown Device',
    device_type VARCHAR(50) DEFAULT 'desktop',
    user_agent TEXT,
    screen_info VARCHAR(100),
    timezone VARCHAR(100),
    platform VARCHAR(100),
    language VARCHAR(50),
    notes TEXT,
    is_active BOOLEAN DEFAULT true,
    last_seen_ip INET,
    last_seen_at TIMESTAMPTZ,
    registered_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_device_fingerprints_hash ON device_fingerprints(fingerprint_hash);
CREATE INDEX IF NOT EXISTS idx_device_fingerprints_active ON device_fingerprints(is_active) WHERE is_active = true;

-- 3. Access rules (whitelist/blacklist entries)
CREATE TABLE IF NOT EXISTS access_rules (
    id SERIAL PRIMARY KEY,
    rule_type VARCHAR(20) NOT NULL CHECK (rule_type IN ('whitelist', 'blacklist')),
    entry_type VARCHAR(20) NOT NULL CHECK (entry_type IN ('ip', 'ip_range', 'device', 'user', 'device_user_bind', 'device_module_bind')),
    scope VARCHAR(50) NOT NULL DEFAULT 'global',
    -- For ip/ip_range: the IP or CIDR. For device: fingerprint_hash. For user: user_id. For bindings: composite.
    value TEXT NOT NULL,
    -- For device_user_bind: the device fingerprint hash
    device_fingerprint_hash VARCHAR(64),
    -- For device_user_bind: the target user id
    target_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    -- For device_module_bind: which module the device can access
    target_module VARCHAR(100),
    label VARCHAR(255),
    reason TEXT,
    is_active BOOLEAN DEFAULT true,
    priority INTEGER DEFAULT 0,
    expires_at TIMESTAMPTZ,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_access_rules_active ON access_rules(is_active, rule_type, entry_type);
CREATE INDEX IF NOT EXISTS idx_access_rules_scope ON access_rules(scope) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_access_rules_device ON access_rules(device_fingerprint_hash) WHERE device_fingerprint_hash IS NOT NULL AND is_active = true;
CREATE INDEX IF NOT EXISTS idx_access_rules_target_user ON access_rules(target_user_id) WHERE target_user_id IS NOT NULL AND is_active = true;

-- 4. Temporary admin overrides for blocked access
CREATE TABLE IF NOT EXISTS access_overrides (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    access_rule_id INTEGER REFERENCES access_rules(id) ON DELETE SET NULL,
    scope VARCHAR(50) DEFAULT 'global',
    reason TEXT,
    granted_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    granted_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    is_active BOOLEAN DEFAULT true,
    revoked_at TIMESTAMPTZ,
    revoked_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_access_overrides_active ON access_overrides(user_id, is_active) WHERE is_active = true;

-- 5. Audit log for access control events
CREATE TABLE IF NOT EXISTS access_control_logs (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(30) NOT NULL,
    entry_type VARCHAR(20),
    scope VARCHAR(50),
    matched_value TEXT,
    matched_rule_id INTEGER,
    user_id INTEGER,
    ip_address INET,
    user_agent TEXT,
    device_fingerprint VARCHAR(64),
    details JSONB,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_acl_logs_created ON access_control_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_acl_logs_event ON access_control_logs(event_type);
CREATE INDEX IF NOT EXISTS idx_acl_logs_user ON access_control_logs(user_id) WHERE user_id IS NOT NULL;
