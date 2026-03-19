-- Clinic Records: Nurse & MedTech Service Tracking
-- Migration: 2026-02-15
-- Description: Record-keeping system for nurse and medtech clinic services

BEGIN;

-- ============================================================
-- Clinic Records (main table)
-- ============================================================
CREATE TABLE IF NOT EXISTS clinic_records (
    id                      SERIAL PRIMARY KEY,

    -- Patient identification (employee OR external)
    employee_id             INT NULL REFERENCES employees(id) ON DELETE SET NULL,
    patient_name            VARCHAR(200) NOT NULL,

    -- Record metadata
    record_date             DATE NOT NULL DEFAULT CURRENT_DATE,
    status                  VARCHAR(30) NOT NULL DEFAULT 'open'
                            CHECK (status IN ('open', 'completed', 'cancelled')),

    -- Nurse service fields
    nurse_employee_id       INT NULL REFERENCES employees(id) ON DELETE SET NULL,
    nurse_service_datetime  TIMESTAMP WITHOUT TIME ZONE NULL,
    nurse_notes             TEXT NULL,

    -- MedTech fields
    medtech_employee_id     INT NULL REFERENCES employees(id) ON DELETE SET NULL,
    medtech_pickup_datetime TIMESTAMP WITHOUT TIME ZONE NULL,
    medtech_notes           TEXT NULL,

    -- Audit
    created_by              INT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by              INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at              TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMP WITHOUT TIME ZONE NULL
);

CREATE INDEX IF NOT EXISTS idx_clinic_records_employee    ON clinic_records(employee_id);
CREATE INDEX IF NOT EXISTS idx_clinic_records_date        ON clinic_records(record_date);
CREATE INDEX IF NOT EXISTS idx_clinic_records_nurse       ON clinic_records(nurse_employee_id);
CREATE INDEX IF NOT EXISTS idx_clinic_records_medtech     ON clinic_records(medtech_employee_id);
CREATE INDEX IF NOT EXISTS idx_clinic_records_status      ON clinic_records(status);
CREATE INDEX IF NOT EXISTS idx_clinic_records_deleted     ON clinic_records(deleted_at);

-- ============================================================
-- Clinic Record History (per-record audit trail)
-- ============================================================
CREATE TABLE IF NOT EXISTS clinic_record_history (
    id                  SERIAL PRIMARY KEY,
    clinic_record_id    INT NOT NULL REFERENCES clinic_records(id) ON DELETE CASCADE,
    action              VARCHAR(50) NOT NULL,
    changed_by          INT NULL REFERENCES users(id) ON DELETE SET NULL,
    changed_by_name     VARCHAR(200) NULL,
    old_values          JSONB NULL,
    new_values          JSONB NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_clinic_history_record ON clinic_record_history(clinic_record_id);
CREATE INDEX IF NOT EXISTS idx_clinic_history_date   ON clinic_record_history(created_at);

-- ============================================================
-- Backup table for soft-delete recovery
-- ============================================================
CREATE TABLE IF NOT EXISTS clinic_records_backup (LIKE clinic_records INCLUDING ALL);

COMMIT;
