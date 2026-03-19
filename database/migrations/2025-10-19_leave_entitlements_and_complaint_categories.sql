BEGIN;

CREATE TABLE IF NOT EXISTS leave_entitlements (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_type VARCHAR(20) NOT NULL,
    scope_id INT NULL,
    leave_type VARCHAR(50) NOT NULL,
    days NUMERIC(8,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_leave_entitlements_scope CHECK (
        (scope_type = 'global' AND scope_id IS NULL)
        OR (scope_type = 'department' AND scope_id IS NOT NULL)
        OR (scope_type = 'employee' AND scope_id IS NOT NULL)
    ),
    CONSTRAINT chk_leave_entitlements_days CHECK (days >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_leave_entitlements_scope
    ON leave_entitlements (scope_type, scope_id, leave_type);

CREATE INDEX IF NOT EXISTS idx_leave_entitlements_type
    ON leave_entitlements (leave_type);

CREATE OR REPLACE FUNCTION trg_leave_entitlements_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_leave_entitlements_set_updated ON leave_entitlements;
CREATE TRIGGER trg_leave_entitlements_set_updated
    BEFORE UPDATE ON leave_entitlements
    FOR EACH ROW EXECUTE FUNCTION trg_leave_entitlements_updated_at();

ALTER TABLE payroll_complaints
    ADD COLUMN IF NOT EXISTS category_code VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS subcategory_code VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS priority VARCHAR(20) NULL;

COMMIT;
