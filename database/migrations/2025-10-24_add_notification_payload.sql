-- Add JSON payload support for richer notification experiences
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS payload JSONB;

-- Ensure legacy rows have a deterministic payload value
UPDATE notifications
   SET payload = NULL
 WHERE payload IS NOT NULL AND jsonb_typeof(payload) IS NULL;