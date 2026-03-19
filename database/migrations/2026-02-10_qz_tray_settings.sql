-- QZ Tray integration: per-user QZ Tray preferences
-- Migration: 2026-02-10_qz_tray_settings.sql

-- ──────────────────────────────────────────────
-- QZ Tray user preferences table
-- Stores per-user QZ Tray configuration (printer name, paper width, auto-print)
-- ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qz_tray_settings (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    default_printer VARCHAR(255),                    -- QZ printer name (OS-level name)
    paper_width     INTEGER NOT NULL DEFAULT 48,     -- chars per line (48 for 58mm, 42 for 80mm)
    auto_print      BOOLEAN NOT NULL DEFAULT FALSE,  -- auto-print on checkout completion
    created_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at      TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE(user_id)
);

CREATE INDEX IF NOT EXISTS idx_qz_tray_settings_user ON qz_tray_settings(user_id);

COMMENT ON TABLE qz_tray_settings IS 'Per-user QZ Tray preferences for silent thermal printing via local QZ Tray agent';
COMMENT ON COLUMN qz_tray_settings.default_printer IS 'OS-level printer name as reported by QZ Tray (must match exactly)';
COMMENT ON COLUMN qz_tray_settings.paper_width IS 'Characters per line: 48 for 58mm paper, 42 for 80mm condensed';
COMMENT ON COLUMN qz_tray_settings.auto_print IS 'When true, receipt auto-prints after POS checkout without user clicking Print';
