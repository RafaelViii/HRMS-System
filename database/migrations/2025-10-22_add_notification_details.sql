-- Add richer notification metadata for UI/UX improvements
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS title VARCHAR(150);

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS body TEXT;

-- Backfill existing rows so the new columns always have sensible values
UPDATE notifications
   SET title = COALESCE(NULLIF(title, ''), LEFT(message, 100)),
       body  = COALESCE(NULLIF(body, ''), message)
 WHERE message IS NOT NULL;
