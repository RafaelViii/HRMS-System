-- Store memo attachment file content in database (for Heroku ephemeral filesystem)
-- This allows attachments to persist across dyno restarts and be accessible from all instances

ALTER TABLE memo_attachments 
ADD COLUMN IF NOT EXISTS file_content BYTEA;

-- Note: Existing attachments will have NULL file_content and will fall back to filesystem
-- New uploads will store both in database (primary) and filesystem (optional backup)
