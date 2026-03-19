-- ================================================================
-- Notification Triggers for Automated Notifications
-- Created: 2025-11-08
-- Purpose: Automatically create notifications for:
--   1. Leave request status changes (approved/rejected)
--   2. Memo posts (via published_at)
--   3. Payroll releases (via released_at)
-- ================================================================

-- ================================================================
-- 1. Leave Request Status Change Notification Function
-- ================================================================
-- Creates notification when leave_requests.status changes to 'approved' or 'rejected'
-- Format: "Your [Leave Type] ([Dates]) has been [approved/rejected] by [Approver]"
CREATE OR REPLACE FUNCTION fn_notify_leave_status_change()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_employee_user_id INT;
    v_employee_name TEXT;
    v_approver_name TEXT;
    v_leave_type TEXT;
    v_start_date TEXT;
    v_end_date TEXT;
    v_status_action TEXT;
    v_title TEXT;
    v_body TEXT;
    v_payload JSONB;
BEGIN
    -- Only trigger on status change to approved or rejected
    IF NEW.status IN ('approved', 'rejected') AND OLD.status != NEW.status THEN
        -- Get employee user_id and name
        SELECT u.id, COALESCE(e.full_name, u.full_name)
        INTO v_employee_user_id, v_employee_name
        FROM employees e
        JOIN users u ON e.user_id = u.id
        WHERE e.id = NEW.employee_id;

        -- Get approver name
        SELECT COALESCE(e.full_name, u.full_name)
        INTO v_approver_name
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id
        WHERE u.id = NEW.approved_by;

        -- Get leave type name
        SELECT name INTO v_leave_type FROM leave_types WHERE id = NEW.leave_type_id;

        -- Format dates
        v_start_date := TO_CHAR(NEW.start_date, 'Mon DD, YYYY');
        v_end_date := TO_CHAR(NEW.end_date, 'Mon DD, YYYY');

        -- Build notification message
        v_status_action := CASE 
            WHEN NEW.status = 'approved' THEN 'approved'
            WHEN NEW.status = 'rejected' THEN 'rejected'
            ELSE 'updated'
        END;

        v_title := 'Leave Request ' || INITCAP(v_status_action);
        
        IF v_start_date = v_end_date THEN
            v_body := 'Your ' || COALESCE(v_leave_type, 'leave request') || ' on ' || v_start_date || 
                      ' has been ' || v_status_action || ' by ' || COALESCE(v_approver_name, 'your manager') || '.';
        ELSE
            v_body := 'Your ' || COALESCE(v_leave_type, 'leave request') || ' (' || v_start_date || ' - ' || v_end_date || ')' ||
                      ' has been ' || v_status_action || ' by ' || COALESCE(v_approver_name, 'your manager') || '.';
        END IF;

        -- Build payload
        v_payload := jsonb_build_object(
            'type', 'leave_request',
            'leave_request_id', NEW.id,
            'status', NEW.status,
            'leave_type', v_leave_type,
            'start_date', v_start_date,
            'end_date', v_end_date,
            'approved_by', v_approver_name,
            'view_path', '/modules/leave/view?id=' || NEW.id
        );

        -- Insert notification if employee has a user account
        IF v_employee_user_id IS NOT NULL THEN
            INSERT INTO notifications (user_id, title, body, message, payload)
            VALUES (
                v_employee_user_id,
                v_title,
                v_body,
                v_body,
                v_payload
            );
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

-- Drop trigger if exists and create new one
DROP TRIGGER IF EXISTS trg_notify_leave_status ON leave_requests;
CREATE TRIGGER trg_notify_leave_status
AFTER UPDATE ON leave_requests
FOR EACH ROW
EXECUTE FUNCTION fn_notify_leave_status_change();


-- ================================================================
-- 2. Memo Published Notification Function
-- ================================================================
-- Creates notification when memos.published_at is set (memo is posted)
-- Format: "New memo from [Name]: '[Title]'"
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
    -- Only trigger when published_at changes from NULL to a value
    IF NEW.published_at IS NOT NULL AND (OLD.published_at IS NULL OR OLD.published_at != NEW.published_at) THEN
        
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
                    SELECT 1 FROM users u2 WHERE u2.id = e.user_id AND u2.role = mr.audience_identifier
                ))
            )
            LEFT JOIN users u ON e.user_id = u.id
            WHERE mr.memo_id = NEW.id
            AND u.id IS NOT NULL
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

-- Drop trigger if exists and create new one
DROP TRIGGER IF EXISTS trg_notify_memo_published ON memos;
CREATE TRIGGER trg_notify_memo_published
AFTER UPDATE ON memos
FOR EACH ROW
EXECUTE FUNCTION fn_notify_memo_published();


-- ================================================================
-- 3. Payroll Release Notification Function
-- ================================================================
-- Creates notification when payroll_runs.released_at is set
-- Format: "Your payroll for [Period] has been released"
CREATE OR REPLACE FUNCTION fn_notify_payroll_released()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_title TEXT;
    v_body TEXT;
    v_period_label TEXT;
    v_payload JSONB;
    v_employee RECORD;
BEGIN
    -- Only trigger when released_at changes from NULL to a value
    IF NEW.released_at IS NOT NULL AND (OLD.released_at IS NULL OR OLD.released_at != NEW.released_at) THEN
        
        -- Get period label
        SELECT 
            TO_CHAR(start_date, 'Mon DD') || ' - ' || TO_CHAR(end_date, 'Mon DD, YYYY')
        INTO v_period_label
        FROM payroll_periods
        WHERE id = NEW.period_id;

        -- Build notification title and body
        v_title := 'Payroll Released';
        v_body := 'Your payroll for ' || COALESCE(v_period_label, 'this period') || ' has been released and is now available to view.';
        
        -- Build payload
        v_payload := jsonb_build_object(
            'type', 'payroll_release',
            'run_id', NEW.id,
            'period_id', NEW.period_id,
            'period_label', v_period_label,
            'view_path', '/modules/payroll/run_view?id=' || NEW.id
        );

        -- Send notification to all employees in this payroll run
        -- Get all employees from payroll_data where run_id matches
        FOR v_employee IN 
            SELECT DISTINCT u.id AS user_id
            FROM payroll_data pd
            JOIN employees e ON pd.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE pd.run_id = NEW.id
            AND u.id IS NOT NULL
        LOOP
            INSERT INTO notifications (user_id, title, body, message, payload)
            VALUES (
                v_employee.user_id,
                v_title,
                v_body,
                v_body,
                v_payload
            );
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$;

-- Drop trigger if exists and create new one
DROP TRIGGER IF EXISTS trg_notify_payroll_released ON payroll_runs;
CREATE TRIGGER trg_notify_payroll_released
AFTER UPDATE ON payroll_runs
FOR EACH ROW
EXECUTE FUNCTION fn_notify_payroll_released();


-- ================================================================
-- Verification Queries
-- ================================================================
-- Run these to verify triggers are installed:
-- SELECT * FROM pg_trigger WHERE tgname LIKE 'trg_notify_%';
-- SELECT proname, prosrc FROM pg_proc WHERE proname LIKE 'fn_notify_%';
