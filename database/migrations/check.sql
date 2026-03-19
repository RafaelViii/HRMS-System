-- optional sanity check before rerunning the migration
SELECT typname FROM pg_type WHERE typname = 'user_role';

-- rerun the migration script
BEGIN;
-- paste the updated SQL here
COMMIT;