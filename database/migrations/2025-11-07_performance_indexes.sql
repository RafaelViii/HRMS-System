-- Performance optimization indexes
-- Add indexes to frequently queried columns for better performance

-- audit_logs: frequently queried by created_at for active user counts
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'audit_logs' 
        AND indexname = 'idx_audit_logs_created_at'
    ) THEN
        CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at DESC);
    END IF;
END
$$;

-- audit_logs: frequently queried by user_id for user activity tracking
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'audit_logs' 
        AND indexname = 'idx_audit_logs_user_id'
    ) THEN
        CREATE INDEX idx_audit_logs_user_id ON audit_logs(user_id);
    END IF;
END
$$;

-- audit_logs: composite index for the most common query pattern (user + recent time)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'audit_logs' 
        AND indexname = 'idx_audit_logs_user_created'
    ) THEN
        CREATE INDEX idx_audit_logs_user_created ON audit_logs(user_id, created_at DESC);
    END IF;
END
$$;

-- system_logs: frequently queried by created_at for recent logs
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'system_logs' 
        AND indexname = 'idx_system_logs_created_at'
    ) THEN
        CREATE INDEX idx_system_logs_created_at ON system_logs(created_at DESC);
    END IF;
END
$$;

-- system_logs: frequently filtered by code pattern (ERR%)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'system_logs' 
        AND indexname = 'idx_system_logs_code_created'
    ) THEN
        CREATE INDEX idx_system_logs_code_created ON system_logs(code, created_at DESC);
    END IF;
END
$$;

-- users: frequently queried by status for active user counts
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'users' 
        AND indexname = 'idx_users_status'
    ) THEN
        CREATE INDEX idx_users_status ON users(status);
    END IF;
END
$$;

-- leave_requests: frequently filtered by status
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'leave_requests' 
        AND indexname = 'idx_leave_requests_status'
    ) THEN
        CREATE INDEX idx_leave_requests_status ON leave_requests(status) WHERE tablename = 'leave_requests';
    EXCEPTION WHEN undefined_table THEN
        -- Table doesn't exist yet, skip
        NULL;
    END IF;
END
$$;

-- payroll_periods: frequently filtered by status
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'payroll_periods' 
        AND indexname = 'idx_payroll_periods_status'
    ) THEN
        CREATE INDEX idx_payroll_periods_status ON payroll_periods(status);
    EXCEPTION WHEN undefined_table THEN
        -- Table doesn't exist yet, skip
        NULL;
    END IF;
END
$$;

-- employees: frequently filtered by status
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'employees' 
        AND indexname = 'idx_employees_status'
    ) THEN
        CREATE INDEX idx_employees_status ON employees(status);
    EXCEPTION WHEN undefined_table THEN
        -- Table doesn't exist yet, skip
        NULL;
    END IF;
END
$$;

-- memos: frequently filtered by status and published_at
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_indexes 
        WHERE schemaname = 'public' 
        AND tablename = 'memos' 
        AND indexname = 'idx_memos_status_published'
    ) THEN
        CREATE INDEX idx_memos_status_published ON memos(status, published_at DESC);
    EXCEPTION WHEN undefined_table THEN
        -- Table doesn't exist yet, skip
        NULL;
    END IF;
END
$$;
