-- Add profile photo path to employees
ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS profile_photo_path VARCHAR(255),
  ADD COLUMN IF NOT EXISTS profile_photo_updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE employees
  ALTER COLUMN profile_photo_updated_at SET DEFAULT CURRENT_TIMESTAMP;

-- Backfill timestamps for existing rows
UPDATE employees
   SET profile_photo_updated_at = COALESCE(profile_photo_updated_at, CURRENT_TIMESTAMP)
 WHERE profile_photo_path IS NOT NULL;
