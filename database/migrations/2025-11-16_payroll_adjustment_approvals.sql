-- Migration: Payroll Adjustment Approval Workflow
-- Date: 2025-11-16
-- Purpose: Add approval workflow for payroll adjustments before they can be applied

BEGIN;

-- Add approval columns to payroll_adjustment_queue
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND column_name = 'approval_status'
    ) THEN
        ALTER TABLE payroll_adjustment_queue 
            ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending_approval' 
            CHECK (approval_status IN ('pending_approval','approved','rejected'));
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND column_name = 'approved_by'
    ) THEN
        ALTER TABLE payroll_adjustment_queue 
            ADD COLUMN approved_by INT NULL;
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND column_name = 'approved_at'
    ) THEN
        ALTER TABLE payroll_adjustment_queue 
            ADD COLUMN approved_at TIMESTAMP WITHOUT TIME ZONE NULL;
    END IF;
    
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND column_name = 'rejection_reason'
    ) THEN
        ALTER TABLE payroll_adjustment_queue 
            ADD COLUMN rejection_reason TEXT NULL;
    END IF;
END$$;

-- Add foreign key for approved_by
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_queue'
          AND constraint_name = 'fk_payadj_approved_by'
    ) THEN
        ALTER TABLE payroll_adjustment_queue
            ADD CONSTRAINT fk_payadj_approved_by
                FOREIGN KEY (approved_by)
                REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$;

-- Create payroll_adjustment_approvers table (separate from payroll_approvers)
CREATE TABLE IF NOT EXISTS payroll_adjustment_approvers (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INT NOT NULL,
    approval_order INT NOT NULL DEFAULT 1,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_adjustment_approver_user UNIQUE (user_id)
);

-- Add foreign key for user_id
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = current_schema()
          AND table_name = 'payroll_adjustment_approvers'
          AND constraint_name = 'fk_adjustment_approver_user'
    ) THEN
        ALTER TABLE payroll_adjustment_approvers
            ADD CONSTRAINT fk_adjustment_approver_user
                FOREIGN KEY (user_id)
                REFERENCES users (id)
                ON DELETE CASCADE ON UPDATE CASCADE;
    END IF;
END$$;

-- Trigger for updated_at maintenance on adjustment_approvers
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.triggers
        WHERE event_object_schema = current_schema()
          AND event_object_table = 'payroll_adjustment_approvers'
          AND trigger_name = 'trg_adjustment_approvers_updated_at'
    ) THEN
        CREATE TRIGGER trg_adjustment_approvers_updated_at
            BEFORE UPDATE ON payroll_adjustment_approvers
            FOR EACH ROW
            EXECUTE FUNCTION set_updated_at();
    END IF;
END$$;

-- Add indexes for performance
CREATE INDEX IF NOT EXISTS idx_payadj_approval_status 
    ON payroll_adjustment_queue (approval_status);

CREATE INDEX IF NOT EXISTS idx_payadj_approved_by 
    ON payroll_adjustment_queue (approved_by);

CREATE INDEX IF NOT EXISTS idx_adjustment_approvers_active 
    ON payroll_adjustment_approvers (active, approval_order);

-- Update existing 'pending' adjustments to 'pending_approval' for approval workflow
-- (Existing queued adjustments default to old behavior - will be treated as 'pending_approval')
UPDATE payroll_adjustment_queue
   SET approval_status = 'pending_approval'
 WHERE status IN ('queued', 'pending')
   AND approval_status IS NULL;

-- Comment on new columns
COMMENT ON COLUMN payroll_adjustment_queue.approval_status IS 'Approval state: pending_approval, approved, rejected';
COMMENT ON COLUMN payroll_adjustment_queue.approved_by IS 'User ID who approved/rejected the adjustment';
COMMENT ON COLUMN payroll_adjustment_queue.approved_at IS 'Timestamp when approved/rejected';
COMMENT ON COLUMN payroll_adjustment_queue.rejection_reason IS 'Reason for rejection if applicable';

COMMENT ON TABLE payroll_adjustment_approvers IS 'Users authorized to approve payroll adjustments (separate from payroll run approvers)';
COMMENT ON COLUMN payroll_adjustment_approvers.approval_order IS 'Sequential approval order for this approver';
COMMENT ON COLUMN payroll_adjustment_approvers.active IS 'Whether this approver is currently active';

COMMIT;
