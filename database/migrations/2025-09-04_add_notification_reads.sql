-- Migration: Add notification_reads table for per-user read tracking of global notifications
-- Idempotent and PostgreSQL-safe. Does not modify existing tables.
-- Applies only if the table does not already exist.

BEGIN;

-- Create table if missing
CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id)
);

-- Optional indexes (composite PK already helps, add user lookup convenience)
CREATE INDEX IF NOT EXISTS idx_notification_reads_user ON notification_reads(user_id);

COMMIT;
