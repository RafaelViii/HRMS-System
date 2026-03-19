-- Migration: Add encrypted PII columns to employees table
-- Date: 2026-02-14
-- Description: Adds government ID and bank account columns for encrypted storage
-- These fields are stored encrypted at rest via includes/encryption.php

DO $$ BEGIN
    -- SSS Number (encrypted)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'sss_number'
    ) THEN
        ALTER TABLE employees ADD COLUMN sss_number TEXT;
        COMMENT ON COLUMN employees.sss_number IS 'SSS number — stored encrypted via AES-256-CBC';
    END IF;

    -- PhilHealth Number (encrypted)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'philhealth_number'
    ) THEN
        ALTER TABLE employees ADD COLUMN philhealth_number TEXT;
        COMMENT ON COLUMN employees.philhealth_number IS 'PhilHealth number — stored encrypted via AES-256-CBC';
    END IF;

    -- Pag-IBIG / HDMF Number (encrypted)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'pagibig_number'
    ) THEN
        ALTER TABLE employees ADD COLUMN pagibig_number TEXT;
        COMMENT ON COLUMN employees.pagibig_number IS 'Pag-IBIG/HDMF number — stored encrypted via AES-256-CBC';
    END IF;

    -- TIN (encrypted)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'tin'
    ) THEN
        ALTER TABLE employees ADD COLUMN tin TEXT;
        COMMENT ON COLUMN employees.tin IS 'Tax Identification Number — stored encrypted via AES-256-CBC';
    END IF;

    -- Bank Account Number (encrypted)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'bank_account_number'
    ) THEN
        ALTER TABLE employees ADD COLUMN bank_account_number TEXT;
        COMMENT ON COLUMN employees.bank_account_number IS 'Bank account number — stored encrypted via AES-256-CBC';
    END IF;

    -- Bank Name (not encrypted — not PII)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employees' AND column_name = 'bank_name'
    ) THEN
        ALTER TABLE employees ADD COLUMN bank_name VARCHAR(255);
    END IF;
END $$;
