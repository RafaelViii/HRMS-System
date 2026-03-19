-- Payroll queue and DTR intake additions (idempotent)

-- Table for compute jobs
CREATE TABLE IF NOT EXISTS payroll_compute_jobs (
    id VARCHAR(100) PRIMARY KEY,
    payroll_batch_id BIGINT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued', -- queued|running|completed|failed
    progress SMALLINT DEFAULT 0,
    error_text TEXT NULL,
    payload_path TEXT NULL,
    claimed_by TEXT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP WITHOUT TIME ZONE NULL,
    finished_at TIMESTAMP WITHOUT TIME ZONE NULL,
    CONSTRAINT fk_compute_jobs_batch FOREIGN KEY (payroll_batch_id)
        REFERENCES payroll_batches (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_compute_jobs_status ON payroll_compute_jobs (status);
CREATE INDEX IF NOT EXISTS idx_compute_jobs_batch ON payroll_compute_jobs (payroll_batch_id);

-- Add DTR columns to payroll_batches
ALTER TABLE payroll_batches
    ADD COLUMN IF NOT EXISTS dtr_file_path TEXT NULL,
    ADD COLUMN IF NOT EXISTS dtr_uploaded_at TIMESTAMP WITHOUT TIME ZONE NULL;

-- Add FK from payroll_batches.computation_job_id to payroll_compute_jobs(id) if not exists
DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_name = 'payroll_batches' AND constraint_name = 'fk_payroll_batches_job'
    ) THEN
        ALTER TABLE payroll_batches
            ADD CONSTRAINT fk_payroll_batches_job
            FOREIGN KEY (computation_job_id) REFERENCES payroll_compute_jobs (id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END $$;
