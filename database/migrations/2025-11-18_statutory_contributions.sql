-- ============================================================================
-- Statutory Contributions (Employee Share) - Philippine Standard
-- Created: 2025-11-18
-- Purpose: Add standard Philippine statutory contributions as compensation
--          templates for SSS, PhilHealth, Pag-IBIG, and Withholding Tax
-- ============================================================================

DO $$
BEGIN
    -- Check if templates already exist to avoid duplicates
    IF NOT EXISTS (SELECT 1 FROM compensation_templates WHERE code = 'SSS_EE' AND category = 'contribution') THEN
        -- SSS Employee Share
        -- ₱1,150.00/month → ₱575.00 per cutoff (bi-monthly)
        INSERT INTO compensation_templates (
            category, name, code, amount_type, static_amount, 
            is_modifiable, effectivity_until, notes, is_active
        ) VALUES (
            'contribution',
            'SSS Employee Share',
            'SSS_EE',
            'static',
            1150.00,
            FALSE,
            NULL,
            'Social Security System employee contribution. Fixed monthly rate divided by 2 for bi-monthly payroll.',
            TRUE
        );
        
        RAISE NOTICE 'Added SSS Employee Share template';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM compensation_templates WHERE code = 'PHIC_EE' AND category = 'contribution') THEN
        -- PhilHealth Employee Share
        -- 5% of monthly rate / 2 → ₱287.50 per cutoff (based on ₱11,500 sample)
        INSERT INTO compensation_templates (
            category, name, code, amount_type, percentage, 
            is_modifiable, effectivity_until, notes, is_active
        ) VALUES (
            'contribution',
            'PhilHealth Employee Share',
            'PHIC_EE',
            'percentage',
            5.00,
            FALSE,
            NULL,
            'Philippine Health Insurance Corporation employee contribution. 5% of monthly basic salary divided by 2 for bi-monthly payroll.',
            TRUE
        );
        
        RAISE NOTICE 'Added PhilHealth Employee Share template';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM compensation_templates WHERE code = 'HDMF_EE' AND category = 'contribution') THEN
        -- Pag-IBIG Employee Share
        -- min(MonthlyRate * 0.02, ₱100) → typically ₱100.00
        INSERT INTO compensation_templates (
            category, name, code, amount_type, percentage, 
            is_modifiable, effectivity_until, notes, is_active
        ) VALUES (
            'contribution',
            'Pag-IBIG Employee Share',
            'HDMF_EE',
            'percentage',
            2.00,
            FALSE,
            NULL,
            'Home Development Mutual Fund (Pag-IBIG) employee contribution. 2% of monthly basic salary with ₱100 monthly cap (₱50 per cutoff).',
            TRUE
        );
        
        RAISE NOTICE 'Added Pag-IBIG Employee Share template';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM compensation_templates WHERE code = 'WTAX' AND category = 'tax') THEN
        -- Withholding Tax (TRAIN Law)
        -- Progressive tax based on BIR tax table
        -- TaxableIncome = MonthlyRate - (SSS + PhilHealth + Pag-IBIG)
        -- Applied to annualized amount, then divided by 24 for bi-monthly
        INSERT INTO compensation_templates (
            category, name, code, amount_type, static_amount, 
            is_modifiable, effectivity_until, notes, is_active
        ) VALUES (
            'tax',
            'Withholding Tax (TRAIN)',
            'WTAX',
            'static',
            0.00,
            TRUE,
            NULL,
            'Philippine Withholding Tax per TRAIN Law. Calculated: TaxableIncome = MonthlyRate - (SSS + PhilHealth + Pag-IBIG); AnnualTaxable = TaxableIncome × 12; Tax per BIR table; divided by 24 for bi-monthly.',
            TRUE
        );
        
        RAISE NOTICE 'Added Withholding Tax template';
    END IF;

    RAISE NOTICE 'Statutory contributions migration completed successfully';
    
EXCEPTION
    WHEN OTHERS THEN
        RAISE EXCEPTION 'Failed to add statutory contributions: %', SQLERRM;
END$$;

-- Add index for faster lookups by code
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE indexname = 'idx_compensation_templates_code'
    ) THEN
        CREATE INDEX idx_compensation_templates_code 
        ON compensation_templates(code) 
        WHERE is_active = TRUE;
        
        RAISE NOTICE 'Created index on compensation_templates.code';
    END IF;
END$$;

-- Migration complete
