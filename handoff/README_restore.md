# HRMS DB Restore - Public only

This file documents how to restore only the `public` schema objects and data into the target RDS PostgreSQL database.

## Files
- `hrms_full_20250923_105547_public.sql` — sanitized for RDS and trimmed to only public objects and data (no `heroku_ext`, no ACL on pg_stat_statements).

## Steps (via VS Code PostgreSQL extension)
1. Connect the editor to the RDS database `ddh3o0bnf6d62e` on host `cd7f19r8oktbkp.cluster-czrs8kj4isg7.us-east-1.rds.amazonaws.com`.
2. Open `c:\hrms-sysfunda\handoff\hrms_full_20250923_105547_public.sql`.
3. Execute the entire script.
4. Run validation (open "Post-restore validation checks" script) to confirm counts, enums, and sequence alignment.

## Notes
- If the target already has objects, drop them first or use the provided cleanup statements.
- This script contains: types, functions, tables, data (COPY), sequences setval, constraints, indexes, triggers, and FKs — limited to the `public` schema.
