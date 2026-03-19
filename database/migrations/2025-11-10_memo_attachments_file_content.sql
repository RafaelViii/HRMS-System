-- Migration: Add file_content column to memo_attachments
-- Date: 2025-11-10
-- Purpose: Store file content in database for Heroku compatibility and better preview

-- Add file_content column to store binary file data
ALTER TABLE memo_attachments 
ADD COLUMN IF NOT EXISTS file_content BYTEA NULL;

COMMENT ON COLUMN memo_attachments.file_content IS 'Binary content of the file, stored in database for cloud deployments';

-- Note: Existing files will have NULL file_content and will fallback to filesystem reading
-- New uploads will store content in both filesystem (backup) and database (primary)
