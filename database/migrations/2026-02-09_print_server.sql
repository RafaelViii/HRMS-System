-- Print Server: printers, print jobs, print history
-- Migration: 2026-02-09_print_server.sql

-- ──────────────────────────────────────────────
-- Printers table
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS printers (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    printer_type    VARCHAR(50) NOT NULL DEFAULT 'receipt',  -- receipt, label, document
    connection_type VARCHAR(50) NOT NULL DEFAULT 'network',  -- network, usb, bluetooth
    ip_address      VARCHAR(45),          -- for network printers
    port            INTEGER DEFAULT 9100, -- default raw print port
    location        VARCHAR(255),         -- physical location description
    description     TEXT,
    status          VARCHAR(30) NOT NULL DEFAULT 'offline',  -- online, offline, error, busy
    is_default      BOOLEAN NOT NULL DEFAULT FALSE,
    is_enabled      BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at    TIMESTAMP WITH TIME ZONE,
    last_error      TEXT,
    config_json     JSONB DEFAULT '{}',   -- driver settings, paper size, etc.
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_printers_status ON printers(status);
CREATE INDEX IF NOT EXISTS idx_printers_type ON printers(printer_type);
CREATE INDEX IF NOT EXISTS idx_printers_enabled ON printers(is_enabled);

-- ──────────────────────────────────────────────
-- Print jobs table (queue + completed)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS print_jobs (
    id              SERIAL PRIMARY KEY,
    printer_id      INTEGER NOT NULL REFERENCES printers(id) ON DELETE CASCADE,
    job_number      VARCHAR(50) NOT NULL UNIQUE,  -- PJ-YYYYMMDD-NNNN
    document_type   VARCHAR(80) NOT NULL DEFAULT 'receipt', -- receipt, payslip, report, label, document, test_page
    document_ref    VARCHAR(255),         -- e.g. TXN-20260209-0001, payslip ID, etc.
    document_title  VARCHAR(255),
    content_type    VARCHAR(100) DEFAULT 'text/plain', -- text/plain, text/html, application/pdf
    content_data    TEXT,                 -- print content or reference path
    copies          INTEGER NOT NULL DEFAULT 1,
    priority        INTEGER NOT NULL DEFAULT 5, -- 1=highest, 10=lowest
    status          VARCHAR(30) NOT NULL DEFAULT 'queued', -- queued, printing, completed, failed, cancelled
    error_message   TEXT,
    file_path       VARCHAR(500),         -- optional path for PDF/file prints
    metadata        JSONB DEFAULT '{}',   -- additional context
    created_by      INTEGER REFERENCES users(id),
    started_at      TIMESTAMP WITH TIME ZONE,
    completed_at    TIMESTAMP WITH TIME ZONE,
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_print_jobs_printer ON print_jobs(printer_id);
CREATE INDEX IF NOT EXISTS idx_print_jobs_status ON print_jobs(status);
CREATE INDEX IF NOT EXISTS idx_print_jobs_created ON print_jobs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_print_jobs_doc_type ON print_jobs(document_type);

-- ──────────────────────────────────────────────
-- Print history (audit trail for prints)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS print_history (
    id              SERIAL PRIMARY KEY,
    printer_id      INTEGER REFERENCES printers(id) ON DELETE SET NULL,
    print_job_id    INTEGER REFERENCES print_jobs(id) ON DELETE SET NULL,
    printer_name    VARCHAR(255) NOT NULL,  -- denormalized for history
    document_type   VARCHAR(80),
    document_ref    VARCHAR(255),
    document_title  VARCHAR(255),
    copies          INTEGER DEFAULT 1,
    status          VARCHAR(30) NOT NULL,   -- completed, failed, cancelled
    error_message   TEXT,
    pages_printed   INTEGER DEFAULT 0,
    duration_ms     INTEGER DEFAULT 0,      -- print duration in milliseconds
    created_by      INTEGER REFERENCES users(id),
    user_name       VARCHAR(255),           -- denormalized
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_print_history_printer ON print_history(printer_id);
CREATE INDEX IF NOT EXISTS idx_print_history_created ON print_history(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_print_history_status ON print_history(status);
CREATE INDEX IF NOT EXISTS idx_print_history_doc_type ON print_history(document_type);
