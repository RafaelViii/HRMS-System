-- ================================================================
-- Database Adjustments for Overtime & Attendance Workflow
-- Created: 2025-11-13
-- Purpose: Add submitted_at to payroll_batches, update attendance_status enum
-- ================================================================

BEGIN;

-- 1. Add submitted_at column to payroll_batches (if not exists)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_batches'
          AND column_name = 'submitted_at'
    ) THEN
        ALTER TABLE payroll_batches 
            ADD COLUMN submitted_at TIMESTAMP WITHOUT TIME ZONE NULL;
        
        COMMENT ON COLUMN payroll_batches.submitted_at IS 'Timestamp when DTR was submitted for this batch';
    END IF;
END$$;

-- 2. Update attendance_status enum to include 'submitted' (if not already present)
DO $$
BEGIN
    -- Check if 'submitted' value already exists in the enum
    IF NOT EXISTS (
        SELECT 1 FROM pg_enum
        WHERE enumlabel = 'submitted'
          AND enumtypid = (SELECT oid FROM pg_type WHERE typname = 'attendance_status')
    ) THEN
        -- Add 'submitted' to the attendance_status enum
        ALTER TYPE attendance_status ADD VALUE 'submitted';
        
        RAISE NOTICE 'Added "submitted" to attendance_status enum';
    ELSE
        RAISE NOTICE 'attendance_status enum already contains "submitted"';
    END IF;
END$$;

-- 3. Create index on submitted_at for performance
CREATE INDEX IF NOT EXISTS idx_payroll_batches_submitted_at 
    ON payroll_batches(submitted_at) 
    WHERE submitted_at IS NOT NULL;

-- 4. Create index on attendance status for filtering
CREATE INDEX IF NOT EXISTS idx_attendance_status 
    ON attendance(status);

COMMIT;

-- Verification queries (for manual testing)
-- SELECT column_name, data_type, is_nullable 
-- FROM information_schema.columns 
-- WHERE table_name = 'payroll_batches' AND column_name = 'submitted_at';

-- SELECT enumlabel 
-- FROM pg_enum 
-- WHERE enumtypid = (SELECT oid FROM pg_type WHERE typname = 'attendance_status')
-- ORDER BY enumsortorder;
