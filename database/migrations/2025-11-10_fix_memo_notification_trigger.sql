-- Migration: Fix memo notification trigger to fire on INSERT
-- Date: 2025-11-10
-- Purpose: Memo notifications were only firing on UPDATE, not INSERT
--          This adds INSERT trigger so notifications work when memos are first created

-- Drop the existing trigger
DROP TRIGGER IF EXISTS trg_notify_memo_published ON memos;

-- Recreate trigger to fire on both INSERT and UPDATE
CREATE TRIGGER trg_notify_memo_published
AFTER INSERT OR UPDATE ON memos
FOR EACH ROW
EXECUTE FUNCTION fn_notify_memo_published();

-- Update the function to handle INSERT case
CREATE OR REPLACE FUNCTION fn_notify_memo_published()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_title TEXT;
    v_body TEXT;
    v_issuer_name TEXT;
    v_payload JSONB;
    v_recipient RECORD;
BEGIN
    -- Trigger when:
    -- 1. INSERT with published_at set
    -- 2. UPDATE where published_at changes from NULL to a value
    IF NEW.published_at IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.published_at IS NULL OR OLD.published_at != NEW.published_at) THEN
        
        -- Get issuer name
        v_issuer_name := COALESCE(NEW.issued_by_name, 'Admin');
        
        -- Build notification
        v_title := 'New Memo: ' || SUBSTRING(NEW.header, 1, 100);
        v_body := 'New memo from ' || v_issuer_name || ': "' || NEW.header || '". Click to preview.';
        
        -- Build payload
        v_payload := jsonb_build_object(
            'type', 'memo',
            'memo_id', NEW.id,
            'header', NEW.header,
            'issued_by', v_issuer_name,
            'view_path', '/modules/memos/view?id=' || NEW.id,
            'preview_path', '/modules/memos/preview_modal.php?id=' || NEW.id
        );

        -- Send to all recipients based on memo_recipients table
        FOR v_recipient IN 
            SELECT DISTINCT u.id AS user_id
            FROM memo_recipients mr
            LEFT JOIN departments d ON mr.audience_type = 'department' AND mr.audience_identifier = CAST(d.id AS TEXT)
            LEFT JOIN employees e ON (
                -- Direct employee match
                (mr.audience_type = 'employee' AND mr.audience_identifier = CAST(e.id AS TEXT))
                OR 
                -- Department match
                (mr.audience_type = 'department' AND e.department_id = d.id)
                OR
                -- Role match (users.role)
                (mr.audience_type = 'role' AND EXISTS (
                    SELECT 1 FROM users u2 WHERE u2.id = e.user_id AND CAST(u2.role AS TEXT) = mr.audience_identifier
                ))
            )
            LEFT JOIN users u ON e.user_id = u.id
            WHERE mr.memo_id = NEW.id
            AND u.id IS NOT NULL
            AND u.status = 'active'
        LOOP
            INSERT INTO notifications (user_id, title, body, message, payload)
            VALUES (
                v_recipient.user_id,
                v_title,
                v_body,
                v_body,
                v_payload
            );
        END LOOP;

        -- Handle "all" audience type - send to all active users
        IF EXISTS (
            SELECT 1 FROM memo_recipients 
            WHERE memo_id = NEW.id 
            AND audience_type = 'all'
        ) THEN
            INSERT INTO notifications (user_id, title, body, message, payload)
            SELECT 
                u.id,
                v_title,
                v_body,
                v_body,
                v_payload
            FROM users u
            WHERE u.status = 'active';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION fn_notify_memo_published IS 'Send notifications when memo is published (INSERT or UPDATE)';
