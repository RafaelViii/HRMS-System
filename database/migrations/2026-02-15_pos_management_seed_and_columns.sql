-- 2026-02-15: Seed default POS management data & add transaction columns
-- Adds discount_type and reference_number columns to inv_transactions
-- Seeds default payment types, discount types, and POS config

-- ─── New columns on inv_transactions ───────────────────────────────────────
ALTER TABLE inv_transactions
  ADD COLUMN IF NOT EXISTS discount_type VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100) DEFAULT NULL;

-- ─── Seed payment types (if empty) ─────────────────────────────────────────
INSERT INTO inv_payment_types (code, label, icon, requires_reference, sort_order, is_active)
SELECT * FROM (VALUES
    ('cash',     'Cash',     '💵', FALSE, 1, TRUE),
    ('e-wallet', 'E-Wallet', '📱', TRUE,  2, TRUE),
    ('card',     'Card',     '💳', TRUE,  3, TRUE)
) AS v(code, label, icon, requires_reference, sort_order, is_active)
WHERE NOT EXISTS (SELECT 1 FROM inv_payment_types LIMIT 1);

-- ─── Seed discount types (if empty) ────────────────────────────────────────
INSERT INTO inv_discount_types (code, label, discount_mode, value, applies_to, min_amount, max_discount, requires_approval, sort_order, is_active)
SELECT * FROM (VALUES
    ('none',           'No Discount',   'fixed'::varchar,      0::numeric,  'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 0, TRUE),
    ('senior_citizen', 'Senior Citizen', 'percentage'::varchar, 20::numeric, 'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 1, TRUE),
    ('pwd',            'PWD',            'percentage'::varchar, 20::numeric, 'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 2, TRUE),
    ('employee',       'Employee',       'percentage'::varchar, 10::numeric, 'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 3, TRUE),
    ('promo_5',        '5% Promo',       'percentage'::varchar,  5::numeric, 'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 4, TRUE),
    ('promo_10',       '10% Promo',      'percentage'::varchar, 10::numeric, 'transaction'::varchar, 0::numeric, NULL::numeric, FALSE, 5, TRUE),
    ('flat_50',        'Flat P50 Off',   'fixed'::varchar,     50::numeric, 'transaction'::varchar, 500::numeric, NULL::numeric, FALSE, 6, TRUE),
    ('flat_100',       'Flat P100 Off',  'fixed'::varchar,    100::numeric, 'transaction'::varchar, 1000::numeric, NULL::numeric, FALSE, 7, TRUE)
) AS v(code, label, discount_mode, value, applies_to, min_amount, max_discount, requires_approval, sort_order, is_active)
WHERE NOT EXISTS (SELECT 1 FROM inv_discount_types LIMIT 1);

-- ─── Seed POS config (if empty) ────────────────────────────────────────────
INSERT INTO inv_pos_config (config_key, config_value, description)
SELECT * FROM (VALUES
    ('require_customer_name', 'false', 'Require customer name for every transaction'),
    ('default_payment_method', 'cash', 'Default payment method selected in POS'),
    ('auto_print_receipt', 'false', 'Automatically print receipt after checkout'),
    ('allow_zero_tender', 'false', 'Allow zero tender for non-cash payments')
) AS v(config_key, config_value, description)
WHERE NOT EXISTS (SELECT 1 FROM inv_pos_config LIMIT 1);
