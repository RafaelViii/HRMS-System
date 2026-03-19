-- Migration: Backfill audit_logs.details into structured JSON when simple patterns are detected.
-- Safe & idempotent: only transforms rows whose details field is not already JSON (NOT LIKE '{%').

ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS details_raw TEXT;
UPDATE audit_logs SET details_raw = details WHERE details_raw IS NULL;

-- Single key=value pattern (no additional '=')
UPDATE audit_logs
SET details = (
  '{"' || split_part(details_raw,'=',1) || '":' ||
    CASE WHEN split_part(details_raw,'=',2) ~ '^[0-9]+$' THEN split_part(details_raw,'=',2)
         ELSE '"' || replace(split_part(details_raw,'=',2),'"','""') || '"' END
  || '}'
)::text
WHERE details_raw IS NOT NULL
  AND (details IS NULL OR details NOT LIKE '{%')
  AND details_raw ~ '^[A-Za-z_]+=[^=]+$'
  AND details_raw NOT LIKE '%=%=%';

-- Multi key=value;key2=value2 pattern
DO $$
DECLARE r RECORD; j JSONB; part TEXT; k TEXT; v TEXT; seg TEXT;
BEGIN
  FOR r IN SELECT id, details_raw FROM audit_logs WHERE details_raw IS NOT NULL AND (details IS NULL OR details NOT LIKE '{%') AND details_raw LIKE '%=%;%'
  LOOP
    j = '{}'::jsonb;
    FOR part IN SELECT regexp_split_to_table(r.details_raw, ';') LOOP
      seg = trim(part);
      IF seg LIKE '%=%' THEN
        k = split_part(seg,'=',1); v = split_part(seg,'=',2);
        IF v ~ '^[0-9]+$' THEN
          j = j || jsonb_build_object(k, (v)::int);
        ELSE
          j = j || jsonb_build_object(k, v);
        END IF;
      END IF;
    END LOOP;
    IF j <> '{}'::jsonb THEN
      UPDATE audit_logs SET details = j::text WHERE id = r.id;
    END IF;
  END LOOP;
END$$;

-- End of backfill