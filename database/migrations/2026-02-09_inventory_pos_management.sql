-- Inventory - POS Management & Customization
-- Migration: 2026-02-09 (part 2)
-- Description: POS payment types, discount rules, and inventory grouping enhancements

BEGIN;

-- ============================================================
-- POS Payment Types (customizable payment methods)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_payment_types (
    id              SERIAL PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,
    label           VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    icon            VARCHAR(50) NULL DEFAULT 'cash',
    requires_reference BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed default payment types (matching existing hardcoded values)
INSERT INTO inv_payment_types (code, label, icon, sort_order) VALUES
    ('cash', 'Cash', 'cash', 1),
    ('card', 'Card', 'card', 2),
    ('charge', 'Charge to Dept', 'charge', 3),
    ('check', 'Check', 'check', 4)
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- POS Discount Types (customizable discount rules)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_discount_types (
    id              SERIAL PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE,
    label           VARCHAR(150) NOT NULL,
    discount_mode   VARCHAR(20) NOT NULL DEFAULT 'percentage' CHECK (discount_mode IN ('percentage', 'fixed')),
    value           NUMERIC(12,2) NOT NULL DEFAULT 0,
    description     TEXT NULL,
    applies_to      VARCHAR(20) NOT NULL DEFAULT 'transaction' CHECK (applies_to IN ('transaction', 'item')),
    min_amount      NUMERIC(12,2) NULL,
    max_discount    NUMERIC(12,2) NULL,
    requires_approval BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed sample discount types
INSERT INTO inv_discount_types (code, label, discount_mode, value, applies_to, sort_order) VALUES
    ('senior', 'Senior Citizen (20%)', 'percentage', 20, 'transaction', 1),
    ('pwd', 'PWD Discount (20%)', 'percentage', 20, 'transaction', 2),
    ('employee', 'Employee Discount (10%)', 'percentage', 10, 'transaction', 3),
    ('promo5', 'Promo - P5 Off per Item', 'fixed', 5, 'item', 4)
ON CONFLICT (code) DO NOTHING;

-- ============================================================
-- POS Configuration (general POS settings)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_pos_config (
    id              SERIAL PRIMARY KEY,
    config_key      VARCHAR(100) NOT NULL UNIQUE,
    config_value    TEXT NOT NULL DEFAULT '',
    description     TEXT NULL,
    updated_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed default POS config
INSERT INTO inv_pos_config (config_key, config_value, description) VALUES
    ('tax_rate', '0', 'Tax rate percentage applied to transactions (0 = no tax)'),
    ('allow_negative_stock', 'false', 'Allow sales even if stock goes below zero'),
    ('require_customer_name', 'false', 'Require customer name for every transaction'),
    ('auto_print_receipt', 'false', 'Automatically open print dialog after sale'),
    ('default_payment_type', 'cash', 'Default payment type for new transactions'),
    ('currency_symbol', 'P', 'Currency symbol displayed on POS and receipts'),
    ('low_stock_warning_pos', 'true', 'Show low stock warnings on POS screen')
ON CONFLICT (config_key) DO NOTHING;

-- ============================================================
-- Inventory Import Batches (track bulk imports)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_import_batches (
    id              SERIAL PRIMARY KEY,
    batch_ref       VARCHAR(50) NOT NULL UNIQUE,
    filename        VARCHAR(255) NULL,
    total_rows      INT NOT NULL DEFAULT 0,
    imported_count  INT NOT NULL DEFAULT 0,
    skipped_count   INT NOT NULL DEFAULT 0,
    error_count     INT NOT NULL DEFAULT 0,
    errors          JSONB NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('processing', 'completed', 'failed')),
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Relax payment_method CHECK on inv_transactions
-- to accept any active payment type code instead of hardcoded list
-- ============================================================
ALTER TABLE inv_transactions DROP CONSTRAINT IF EXISTS inv_transactions_payment_method_check;

COMMIT;
