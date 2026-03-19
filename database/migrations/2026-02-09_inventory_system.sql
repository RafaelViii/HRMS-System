-- Inventory & POS System for Medical Supplies
-- Migration: 2026-02-09
-- Description: Full inventory management and point-of-sale system for hospital/medical supplies

BEGIN;

-- ============================================================
-- Item Categories (hierarchical)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_categories (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    parent_id       INT NULL REFERENCES inv_categories(id) ON DELETE SET NULL,
    description     TEXT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Suppliers
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_suppliers (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    contact_person  VARCHAR(150) NULL,
    phone           VARCHAR(50) NULL,
    email           VARCHAR(150) NULL,
    address         TEXT NULL,
    notes           TEXT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Storage Locations (warehouses / rooms / shelves)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_locations (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    description     TEXT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Items (master product catalog)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_items (
    id              SERIAL PRIMARY KEY,
    sku             VARCHAR(80) NOT NULL UNIQUE,
    barcode         VARCHAR(100) NULL,
    name            VARCHAR(250) NOT NULL,
    generic_name    VARCHAR(250) NULL,
    description     TEXT NULL,
    category_id     INT NULL REFERENCES inv_categories(id) ON DELETE SET NULL,
    supplier_id     INT NULL REFERENCES inv_suppliers(id) ON DELETE SET NULL,
    location_id     INT NULL REFERENCES inv_locations(id) ON DELETE SET NULL,
    unit            VARCHAR(50) NOT NULL DEFAULT 'pcs',
    cost_price      NUMERIC(12,2) NOT NULL DEFAULT 0,
    selling_price   NUMERIC(12,2) NOT NULL DEFAULT 0,
    reorder_level   INT NOT NULL DEFAULT 10,
    qty_on_hand     INT NOT NULL DEFAULT 0,
    expiry_date     DATE NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_inv_items_sku ON inv_items(sku);
CREATE INDEX IF NOT EXISTS idx_inv_items_barcode ON inv_items(barcode);
CREATE INDEX IF NOT EXISTS idx_inv_items_category ON inv_items(category_id);
CREATE INDEX IF NOT EXISTS idx_inv_items_supplier ON inv_items(supplier_id);

-- ============================================================
-- Stock Movements (every in/out is tracked)
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_stock_movements (
    id              SERIAL PRIMARY KEY,
    item_id         INT NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
    movement_type   VARCHAR(30) NOT NULL CHECK (movement_type IN ('receipt','sale','adjustment','return','transfer','disposal','initial')),
    quantity        INT NOT NULL,
    reference_type  VARCHAR(30) NULL,
    reference_id    INT NULL,
    unit_cost       NUMERIC(12,2) NULL,
    notes           TEXT NULL,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_inv_movements_item ON inv_stock_movements(item_id);
CREATE INDEX IF NOT EXISTS idx_inv_movements_type ON inv_stock_movements(movement_type);
CREATE INDEX IF NOT EXISTS idx_inv_movements_date ON inv_stock_movements(created_at);

-- ============================================================
-- Purchase Orders
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_purchase_orders (
    id              SERIAL PRIMARY KEY,
    po_number       VARCHAR(50) NOT NULL UNIQUE,
    supplier_id     INT NULL REFERENCES inv_suppliers(id) ON DELETE SET NULL,
    status          VARCHAR(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','ordered','received','partial','cancelled')),
    order_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    expected_date   DATE NULL,
    received_date   DATE NULL,
    total_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
    notes           TEXT NULL,
    created_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    received_by     INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inv_purchase_order_items (
    id              SERIAL PRIMARY KEY,
    purchase_order_id INT NOT NULL REFERENCES inv_purchase_orders(id) ON DELETE CASCADE,
    item_id         INT NOT NULL REFERENCES inv_items(id) ON DELETE CASCADE,
    quantity_ordered INT NOT NULL DEFAULT 1,
    unit_cost       NUMERIC(12,2) NOT NULL DEFAULT 0,
    quantity_received INT NOT NULL DEFAULT 0,
    UNIQUE(purchase_order_id, item_id)
);

-- ============================================================
-- POS Transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_transactions (
    id              SERIAL PRIMARY KEY,
    txn_number      VARCHAR(50) NOT NULL UNIQUE,
    txn_type        VARCHAR(20) NOT NULL DEFAULT 'sale' CHECK (txn_type IN ('sale','return')),
    customer_name   VARCHAR(200) NULL,
    customer_dept   VARCHAR(150) NULL,
    subtotal        NUMERIC(14,2) NOT NULL DEFAULT 0,
    discount_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(14,2) NOT NULL DEFAULT 0,
    total_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
    amount_tendered NUMERIC(14,2) NOT NULL DEFAULT 0,
    change_amount   NUMERIC(14,2) NOT NULL DEFAULT 0,
    payment_method  VARCHAR(30) NOT NULL DEFAULT 'cash' CHECK (payment_method IN ('cash','card','charge','check')),
    status          VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('completed','voided','refunded')),
    notes           TEXT NULL,
    voided_by       INT NULL REFERENCES users(id) ON DELETE SET NULL,
    voided_at       TIMESTAMP WITHOUT TIME ZONE NULL,
    void_reason     TEXT NULL,
    created_by      INT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    transaction_date TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_inv_txn_number ON inv_transactions(txn_number);
CREATE INDEX IF NOT EXISTS idx_inv_txn_date ON inv_transactions(transaction_date);
CREATE INDEX IF NOT EXISTS idx_inv_txn_status ON inv_transactions(status);

CREATE TABLE IF NOT EXISTS inv_transaction_items (
    id              SERIAL PRIMARY KEY,
    txn_id          INT NOT NULL REFERENCES inv_transactions(id) ON DELETE CASCADE,
    item_id         INT NOT NULL REFERENCES inv_items(id) ON DELETE RESTRICT,
    item_name       VARCHAR(250) NOT NULL,
    quantity        INT NOT NULL DEFAULT 1,
    unit_price      NUMERIC(12,2) NOT NULL DEFAULT 0,
    discount        NUMERIC(12,2) NOT NULL DEFAULT 0,
    line_total      NUMERIC(12,2) NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_inv_txn_items_txn ON inv_transaction_items(txn_id);

-- ============================================================
-- Receipt Settings
-- ============================================================
CREATE TABLE IF NOT EXISTS inv_receipt_settings (
    id              SERIAL PRIMARY KEY,
    company_name    VARCHAR(250) NOT NULL DEFAULT '',
    address         TEXT NULL,
    phone           VARCHAR(50) NULL,
    tax_id          VARCHAR(50) NULL,
    header_text     TEXT NULL DEFAULT '',
    footer_text     TEXT NULL DEFAULT 'Thank you for your purchase!',
    show_logo       BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by      INT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at      TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed default receipt settings
INSERT INTO inv_receipt_settings (company_name, address, header_text, footer_text)
VALUES (
    '',
    '',
    '',
    'Thank you! Please keep this receipt for your records.'
) ON CONFLICT DO NOTHING;

COMMIT;
