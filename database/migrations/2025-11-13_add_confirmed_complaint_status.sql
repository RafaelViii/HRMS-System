-- Add 'confirmed' status to payroll_complaint_status enum
-- Date: 2025-11-13
-- Purpose: Fix missing 'confirmed' status in the complaint status enum
-- This fixes the error: "Unable to update complaint status" when trying to set status to 'confirmed'

-- Note: ALTER TYPE ... ADD VALUE cannot run inside a transaction block
-- It must run in its own statement

DO $$
BEGIN
    -- Check if 'confirmed' value already exists in the enum
    IF NOT EXISTS (
        SELECT 1 
        FROM pg_enum e
        JOIN pg_type t ON e.enumtypid = t.oid
        WHERE t.typname = 'payroll_complaint_status' 
        AND e.enumlabel = 'confirmed'
    ) THEN
        -- Add 'confirmed' to the enum
        ALTER TYPE payroll_complaint_status ADD VALUE 'confirmed';
        RAISE NOTICE 'Added "confirmed" status to payroll_complaint_status enum';
    ELSE
        RAISE NOTICE '"confirmed" status already exists in payroll_complaint_status enum';
    END IF;
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Could not add "confirmed" to enum. It may already exist or there may be another issue: %', SQLERRM;
END
$$;
