-- Inventory Seed Data: Categories, Suppliers, Locations & 50 Items
-- Migration: 2026-02-14
-- Description: Populate inventory with realistic medical/office supply items across 8 categories

BEGIN;

-- ============================================================
-- Categories (8 top-level)
-- ============================================================
INSERT INTO inv_categories (id, name, description, sort_order) VALUES
    (1,  'Medicine',              'Prescription and over-the-counter medicines',                1),
    (2,  'Medical Supplies',      'Consumable medical devices and disposables',                 2),
    (3,  'Laboratory Supplies',   'Reagents, test kits, and lab consumables',                   3),
    (4,  'Office Supplies',       'Paper, pens, stationery, and general office items',          4),
    (5,  'Cleaning & Sanitation', 'Disinfectants, cleaning agents, and janitorial supplies',    5),
    (6,  'Personal Protective Equipment', 'Gloves, masks, gowns, and safety gear',             6),
    (7,  'First Aid',             'Emergency and first-aid kit components',                     7),
    (8,  'IT & Electronics',      'Computer peripherals, cables, and small electronics',        8)
ON CONFLICT (id) DO NOTHING;

-- Advance the sequence past seeded IDs
SELECT setval('inv_categories_id_seq', GREATEST((SELECT MAX(id) FROM inv_categories), 8));

-- ============================================================
-- Suppliers (4 sample vendors)
-- ============================================================
INSERT INTO inv_suppliers (id, name, contact_person, phone, email, address) VALUES
    (1, 'MedLine Philippines',     'Maria Santos',   '02-8888-1234', 'sales@medlineph.com',     'Makati City, Metro Manila'),
    (2, 'National Bookstore Corp', 'Juan dela Cruz',  '02-8888-5678', 'orders@nationalbookstore.com', 'Quezon City, Metro Manila'),
    (3, 'CleanPro Distributors',   'Ana Reyes',       '0917-123-4567', 'info@cleanpro.ph',       'Pasig City, Metro Manila'),
    (4, 'TechSource Trading',      'Mark Villanueva', '02-7777-9012', 'sales@techsource.ph',    'Taguig City, Metro Manila')
ON CONFLICT (id) DO NOTHING;

SELECT setval('inv_suppliers_id_seq', GREATEST((SELECT MAX(id) FROM inv_suppliers), 4));

-- ============================================================
-- Locations (3 storage areas)
-- ============================================================
INSERT INTO inv_locations (id, name, description) VALUES
    (1, 'Main Stockroom',  'Primary storage for all supplies'),
    (2, 'Pharmacy Room',   'Temperature-controlled medicine storage'),
    (3, 'IT Closet',       'Electronics and peripherals storage')
ON CONFLICT (id) DO NOTHING;

SELECT setval('inv_locations_id_seq', GREATEST((SELECT MAX(id) FROM inv_locations), 3));

-- ============================================================
-- Items (50 products across 8 categories)
-- ============================================================

-- ---- Medicine (cat 1) ----
INSERT INTO inv_items (sku, name, generic_name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level, expiry_date) VALUES
    ('MED-0001', 'Biogesic 500mg (Box/100)',      'Paracetamol',       'Paracetamol 500mg tablets, 100 per box',            1, 1, 2, 'box',    85.00,  120.00, 150, 20, '2027-06-30'),
    ('MED-0002', 'Neozep Forte (Box/100)',         'Phenylephrine HCl', 'Cold & flu tablets, 100 per box',                   1, 1, 2, 'box',    95.00,  135.00, 100, 15, '2027-03-31'),
    ('MED-0003', 'Amoxicillin 500mg (Box/100)',    'Amoxicillin',       'Antibiotic capsules, 100 per box',                  1, 1, 2, 'box',   250.00,  350.00,  80, 10, '2027-01-31'),
    ('MED-0004', 'Ibuprofen 200mg (Box/100)',      'Ibuprofen',         'Anti-inflammatory tablets, 100 per box',            1, 1, 2, 'box',   110.00,  160.00, 120, 15, '2027-09-30'),
    ('MED-0005', 'Cetirizine 10mg (Box/100)',      'Cetirizine',        'Antihistamine tablets, 100 per box',                1, 1, 2, 'box',    70.00,  100.00, 200, 20, '2027-12-31'),
    ('MED-0006', 'Loperamide 2mg (Box/50)',        'Loperamide',        'Anti-diarrheal capsules, 50 per box',               1, 1, 2, 'box',    45.00,   65.00,  90, 10, '2027-04-30'),
    ('MED-0007', 'Mefenamic Acid 500mg (Box/100)', 'Mefenamic Acid',    'Analgesic capsules, 100 per box',                   1, 1, 2, 'box',   130.00,  185.00, 110, 15, '2027-08-31'),
    ('MED-0008', 'Omeprazole 20mg (Box/100)',      'Omeprazole',        'Proton pump inhibitor capsules, 100 per box',       1, 1, 2, 'box',   200.00,  280.00,  70, 10, '2027-05-31')
ON CONFLICT (sku) DO NOTHING;

-- ---- Medical Supplies (cat 2) ----
INSERT INTO inv_items (sku, name, generic_name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('SUP-0001', 'Disposable Syringe 3ml (Box/100)',  NULL, '3ml syringe with needle, 100 per box',            2, 1, 1, 'box',   180.00,  250.00, 60, 10),
    ('SUP-0002', 'IV Cannula 22G (Box/50)',            NULL, 'Intravenous cannula gauge 22, 50 per box',        2, 1, 1, 'box',   350.00,  480.00, 40, 10),
    ('SUP-0003', 'Cotton Balls 300g',                  NULL, 'Absorbent cotton balls, 300g pack',               2, 1, 1, 'pack',   55.00,   80.00, 100, 15),
    ('SUP-0004', 'Elastic Bandage 4" (Roll)',          NULL, '4-inch elastic compression bandage',              2, 1, 1, 'roll',   35.00,   55.00, 120, 20),
    ('SUP-0005', 'Adhesive Plaster 1" x 5yds',        NULL, 'Medical adhesive tape roll',                      2, 1, 1, 'roll',   25.00,   40.00, 200, 25),
    ('SUP-0006', 'Tongue Depressor (Box/100)',         NULL, 'Wooden tongue depressors, sterile, 100 per box',  2, 1, 1, 'box',    40.00,   60.00,  80, 10),
    ('SUP-0007', 'Digital Thermometer',                NULL, 'Clinical digital thermometer with beeper',        2, 1, 1, 'pcs',  120.00,  180.00,  30,  5)
ON CONFLICT (sku) DO NOTHING;

-- ---- Laboratory Supplies (cat 3) ----
INSERT INTO inv_items (sku, name, generic_name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('LAB-0001', 'Urine Test Strips (Bottle/100)',    NULL, '10-parameter urine reagent strips',                3, 1, 1, 'bottle', 450.00,  620.00, 25, 5),
    ('LAB-0002', 'Blood Glucose Strips (Box/50)',      NULL, 'Glucometer test strips, 50 per box',              3, 1, 1, 'box',    380.00,  520.00, 35, 5),
    ('LAB-0003', 'Microscope Slides (Box/50)',         NULL, 'Plain glass slides, 50 per box',                  3, 1, 1, 'box',     80.00,  120.00, 40, 10),
    ('LAB-0004', 'Specimen Container 60ml (Pack/50)', NULL, 'Sterile urine specimen cups with lid, 50 pack',   3, 1, 1, 'pack',  200.00,  290.00, 50, 10),
    ('LAB-0005', 'Latex Tourniquet (Box/25)',           NULL, 'Disposable latex tourniquets, 25 per box',        3, 1, 1, 'box',    95.00,  140.00, 30,  5),
    ('LAB-0006', 'Rapid Antigen Test Kit (Box/25)',    NULL, 'COVID-19 rapid antigen test, 25 kits per box',    3, 1, 1, 'box',   750.00, 1050.00, 20,  5)
ON CONFLICT (sku) DO NOTHING;

-- ---- Office Supplies (cat 4) ----
INSERT INTO inv_items (sku, name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('OFC-0001', 'Bond Paper A4 (Ream/500)',        'White 80gsm copy paper, 500 sheets per ream',          4, 2, 1, 'ream',  190.00,  250.00, 200, 30),
    ('OFC-0002', 'Ballpoint Pen Black (Box/12)',    'Retractable ballpoint pen, black ink, 12 per box',     4, 2, 1, 'box',    48.00,   72.00, 150, 20),
    ('OFC-0003', 'Yellow Pad Legal (Pack/3)',       'Yellow ruled writing pads, 3 per pack',                4, 2, 1, 'pack',   45.00,   65.00, 100, 15),
    ('OFC-0004', 'Stapler Heavy-Duty',              'Desktop stapler, 40-sheet capacity',                   4, 2, 1, 'pcs',   180.00,  260.00,  25,  5),
    ('OFC-0005', 'Staple Wire #35 (Box/5000)',      'Standard staple wire, 5000 per box',                   4, 2, 1, 'box',    35.00,   50.00, 120, 15),
    ('OFC-0006', 'Folder Long Brown (Pack/50)',     'Kraft expanding folder, legal size, 50 per pack',      4, 2, 1, 'pack',  250.00,  340.00,  60, 10),
    ('OFC-0007', 'Whiteboard Marker Set (Pack/4)',  'Assorted colors dry-erase markers, 4 per pack',        4, 2, 1, 'pack',   80.00,  115.00,  70, 10),
    ('OFC-0008', 'Correction Tape 5mm x 8m',       'White-out correction tape',                            4, 2, 1, 'pcs',    25.00,   38.00, 140, 20)
ON CONFLICT (sku) DO NOTHING;

-- ---- Cleaning & Sanitation (cat 5) ----
INSERT INTO inv_items (sku, name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('CLN-0001', 'Isopropyl Alcohol 70% (Gallon)',     '70% isopropyl rubbing alcohol, 1 gallon',           5, 3, 1, 'gallon', 280.00,  380.00, 40, 10),
    ('CLN-0002', 'Hand Soap Liquid 500ml',              'Antibacterial liquid hand soap, pump bottle',       5, 3, 1, 'bottle',  65.00,   95.00, 80, 15),
    ('CLN-0003', 'Floor Disinfectant 1L',               'Pine-scented floor cleaner/disinfectant, 1 liter',  5, 3, 1, 'bottle',  85.00,  120.00, 50, 10),
    ('CLN-0004', 'Bleach Solution 1 Gallon',            'Sodium hypochlorite cleaning solution',              5, 3, 1, 'gallon',  95.00,  140.00, 35,  8),
    ('CLN-0005', 'Trash Bag XL Black (Pack/50)',        'Extra-large garbage bags, 50 per pack',              5, 3, 1, 'pack',  120.00,  170.00, 60, 10),
    ('CLN-0006', 'Microfiber Cleaning Cloth (Pack/5)',  'Reusable microfiber towels, 5 per pack',             5, 3, 1, 'pack',   75.00,  110.00, 45, 10)
ON CONFLICT (sku) DO NOTHING;

-- ---- Personal Protective Equipment (cat 6) ----
INSERT INTO inv_items (sku, name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('PPE-0001', 'Surgical Mask 3-Ply (Box/50)',        'Disposable 3-ply face masks, 50 per box',          6, 1, 1, 'box',    95.00,  140.00, 300, 40),
    ('PPE-0002', 'Nitrile Gloves Medium (Box/100)',     'Powder-free nitrile exam gloves, 100 per box',      6, 1, 1, 'box',   250.00,  360.00, 120, 20),
    ('PPE-0003', 'Nitrile Gloves Large (Box/100)',      'Powder-free nitrile exam gloves, large, 100/box',   6, 1, 1, 'box',   250.00,  360.00, 100, 20),
    ('PPE-0004', 'Face Shield (Pack/10)',               'Full-face transparent shield, 10 per pack',         6, 1, 1, 'pack',  150.00,  220.00,  50, 10),
    ('PPE-0005', 'Isolation Gown (Pack/10)',            'Disposable non-woven isolation gown, 10 per pack',  6, 1, 1, 'pack',  300.00,  420.00,  40,  8),
    ('PPE-0006', 'KN95 Mask (Box/20)',                  'KN95 protective face mask, 20 per box',             6, 1, 1, 'box',   180.00,  260.00,  80, 15),
    ('PPE-0007', 'Safety Goggles',                      'Anti-fog splash-proof safety goggles',               6, 1, 1, 'pcs',   120.00,  175.00,  25,  5)
ON CONFLICT (sku) DO NOTHING;

-- ---- First Aid (cat 7) ----
INSERT INTO inv_items (sku, name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('AID-0001', 'First Aid Kit Basic',                 'Compact first aid kit, 50+ components',              7, 1, 1, 'kit',   450.00,  620.00,  15, 3),
    ('AID-0002', 'Band-Aid Adhesive Strips (Box/100)',  'Fabric adhesive bandages, assorted sizes, 100/box',  7, 1, 1, 'box',    85.00,  125.00, 100, 15),
    ('AID-0003', 'Povidone Iodine 120ml',               'Betadine antiseptic solution, 120ml bottle',         7, 1, 1, 'bottle', 65.00,   95.00,  60, 10),
    ('AID-0004', 'Hydrogen Peroxide 120ml',             '3% hydrogen peroxide solution, 120ml',               7, 1, 1, 'bottle', 30.00,   48.00,  70, 10),
    ('AID-0005', 'Gauze Pad Sterile 4x4 (Box/100)',     'Sterile gauze pads 4"x4", 100 per box',              7, 1, 1, 'box',   160.00,  230.00,  45, 10),
    ('AID-0006', 'Cold Compress Instant',               'Single-use instant cold pack',                       7, 1, 1, 'pcs',    25.00,   40.00,  80, 15)
ON CONFLICT (sku) DO NOTHING;

-- ---- IT & Electronics (cat 8) ----
INSERT INTO inv_items (sku, name, description, category_id, supplier_id, location_id, unit, cost_price, selling_price, qty_on_hand, reorder_level) VALUES
    ('ITE-0001', 'USB Flash Drive 32GB',                'USB 3.0 flash drive, 32GB capacity',                 8, 4, 3, 'pcs',   180.00,  260.00,  40, 5),
    ('ITE-0002', 'USB-C Charging Cable 1m',             'USB-C to USB-A fast charging cable, 1 meter',        8, 4, 3, 'pcs',    95.00,  140.00,  50, 10),
    ('ITE-0003', 'AAA Alkaline Battery (Pack/4)',       'AAA batteries, 4 per pack',                          8, 4, 3, 'pack',   55.00,   80.00,  80, 15),
    ('ITE-0004', 'Wireless Mouse',                      'Ergonomic wireless optical mouse, USB receiver',     8, 4, 3, 'pcs',   350.00,  490.00,  20, 5),
    ('ITE-0005', 'Ink Cartridge Black (HP 680)',         'HP 680 black ink cartridge',                         8, 4, 3, 'pcs',   380.00,  520.00,  25, 5),
    ('ITE-0006', 'Ink Cartridge Color (HP 680)',         'HP 680 tri-color ink cartridge',                     8, 4, 3, 'pcs',   450.00,  600.00,  20, 5)
ON CONFLICT (sku) DO NOTHING;

-- ============================================================
-- Record initial stock movements for traceability
-- ============================================================
INSERT INTO inv_stock_movements (item_id, movement_type, quantity, notes, created_at)
SELECT id, 'initial', qty_on_hand, 'Inventory seed – initial stock', CURRENT_TIMESTAMP
FROM inv_items
WHERE qty_on_hand > 0
  AND NOT EXISTS (
      SELECT 1 FROM inv_stock_movements sm
      WHERE sm.item_id = inv_items.id AND sm.movement_type = 'initial'
  );

COMMIT;
