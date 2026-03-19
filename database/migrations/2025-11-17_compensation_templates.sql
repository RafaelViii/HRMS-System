-- ============================================================================
-- Compensation Templates and Shift Benefits Migration
-- Created: 2025-11-17
-- Purpose: Restructure compensation system to support templates for benefits,
--          contributions, deductions, tax, and shift-based allowances
-- ============================================================================

-- Create compensation_templates table
CREATE TABLE IF NOT EXISTS compensation_templates (
    id                     INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category               VARCHAR(50) NOT NULL CHECK (category IN ('allowance', 'contribution', 'tax', 'deduction')),
    name                   VARCHAR(191) NOT NULL,
    code                   VARCHAR(32) NULL,
    amount_type            VARCHAR(20) NOT NULL DEFAULT 'static' CHECK (amount_type IN ('static', 'percentage')),
    static_amount          NUMERIC(12,2) NULL CHECK (static_amount >= 0),
    percentage             NUMERIC(5,2) NULL CHECK (percentage >= 0 AND percentage <= 100),
    is_modifiable          BOOLEAN NOT NULL DEFAULT FALSE,
    effectivity_until      DATE NULL,
    notes                  TEXT NULL,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_by             INT NULL,
    updated_by             INT NULL,
    created_at             TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_amount_value CHECK (
        (amount_type = 'static' AND static_amount IS NOT NULL AND percentage IS NULL) OR
        (amount_type = 'percentage' AND percentage IS NOT NULL AND static_amount IS NULL)
    )
);

-- Create shift_benefits table for shift-based allowances
CREATE TABLE IF NOT EXISTS shift_benefits (
    id                     INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    shift_name             VARCHAR(100) NOT NULL,
    shift_code             VARCHAR(32) NULL,
    benefit_type           VARCHAR(50) NOT NULL DEFAULT 'night_differential' CHECK (benefit_type IN ('night_differential', 'shift_allowance', 'hazard_pay', 'other')),
    amount_type            VARCHAR(20) NOT NULL DEFAULT 'percentage' CHECK (amount_type IN ('static', 'percentage')),
    static_amount          NUMERIC(12,2) NULL CHECK (static_amount >= 0),
    percentage             NUMERIC(5,2) NULL CHECK (percentage >= 0 AND percentage <= 100),
    effectivity_until      DATE NULL,
    notes                  TEXT NULL,
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    created_by             INT NULL,
    updated_by             INT NULL,
    created_at             TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_shift_amount_value CHECK (
        (amount_type = 'static' AND static_amount IS NOT NULL AND percentage IS NULL) OR
        (amount_type = 'percentage' AND percentage IS NOT NULL AND static_amount IS NULL)
    )
);

-- Add foreign keys for created_by and updated_by
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'compensation_templates'
          AND constraint_name = 'fk_comp_template_created_by'
    ) THEN
        ALTER TABLE compensation_templates
            ADD CONSTRAINT fk_comp_template_created_by
                FOREIGN KEY (created_by)
                REFERENCES users (id)
                ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'compensation_templates'
          AND constraint_name = 'fk_comp_template_updated_by'
    ) THEN
        ALTER TABLE compensation_templates
            ADD CONSTRAINT fk_comp_template_updated_by
                FOREIGN KEY (updated_by)
                REFERENCES users (id)
                ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'shift_benefits'
          AND constraint_name = 'fk_shift_benefit_created_by'
    ) THEN
        ALTER TABLE shift_benefits
            ADD CONSTRAINT fk_shift_benefit_created_by
                FOREIGN KEY (created_by)
                REFERENCES users (id)
                ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'shift_benefits'
          AND constraint_name = 'fk_shift_benefit_updated_by'
    ) THEN
        ALTER TABLE shift_benefits
            ADD CONSTRAINT fk_shift_benefit_updated_by
                FOREIGN KEY (updated_by)
                REFERENCES users (id)
                ON DELETE SET NULL;
    END IF;
END$$;

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_comp_template_category ON compensation_templates (category);
CREATE INDEX IF NOT EXISTS idx_comp_template_active ON compensation_templates (is_active);
CREATE INDEX IF NOT EXISTS idx_comp_template_effectivity ON compensation_templates (effectivity_until) WHERE effectivity_until IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_shift_benefit_active ON shift_benefits (is_active);
CREATE INDEX IF NOT EXISTS idx_shift_benefit_type ON shift_benefits (benefit_type);

-- Create updated_at trigger for compensation_templates
CREATE OR REPLACE FUNCTION trg_compensation_templates_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_compensation_templates_updated_at ON compensation_templates;
    CREATE TRIGGER trg_compensation_templates_updated_at
        BEFORE UPDATE ON compensation_templates
        FOR EACH ROW
        EXECUTE FUNCTION trg_compensation_templates_updated_at();
END$$;

-- Create updated_at trigger for shift_benefits
CREATE OR REPLACE FUNCTION trg_shift_benefits_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_shift_benefits_updated_at ON shift_benefits;
    CREATE TRIGGER trg_shift_benefits_updated_at
        BEFORE UPDATE ON shift_benefits
        FOR EACH ROW
        EXECUTE FUNCTION trg_shift_benefits_updated_at();
END$$;

-- Add comments for documentation
COMMENT ON TABLE compensation_templates IS 'Templates for benefits, contributions, deductions, and tax that can be applied to employees';
COMMENT ON COLUMN compensation_templates.category IS 'Category: allowance, contribution, tax, or deduction';
COMMENT ON COLUMN compensation_templates.amount_type IS 'Whether amount is static or percentage-based';
COMMENT ON COLUMN compensation_templates.is_modifiable IS 'Allow HR to modify this template amount per employee';
COMMENT ON COLUMN compensation_templates.effectivity_until IS 'Optional expiry date for this template';

COMMENT ON TABLE shift_benefits IS 'Shift-based benefits and allowances (e.g., night differential, shift allowances)';
COMMENT ON COLUMN shift_benefits.benefit_type IS 'Type of shift benefit: night_differential, shift_allowance, hazard_pay, other';
COMMENT ON COLUMN shift_benefits.amount_type IS 'Whether amount is static or percentage-based';

-- Migration complete
