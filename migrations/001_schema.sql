-- Habi schema (SDD §5). Money is stored as integer centavos (₱1 = 100).
-- Executed transactionally on first boot only; later boots never re-run it.

CREATE TABLE admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('Tote', 'Satchel', 'Sling', 'Clutch')),
    description TEXT NOT NULL DEFAULT '',
    materials TEXT NOT NULL DEFAULT '',
    place_of_make TEXT NOT NULL DEFAULT '',
    price_centavos INTEGER NOT NULL CHECK (price_centavos >= 0),
    stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    low_stock_threshold INTEGER NOT NULL DEFAULT 3 CHECK (low_stock_threshold >= 0),
    dimensions TEXT NOT NULL DEFAULT '{}',
    three_config TEXT NOT NULL DEFAULT '{}',
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE product_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_primary INTEGER NOT NULL DEFAULT 1 CHECK (is_primary IN (0, 1))
);

CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_number TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL CHECK (status IN (
        'pending_payment', 'paid', 'packed', 'shipped', 'delivered', 'cancelled', 'failed'
    )),
    customer_name TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_phone TEXT NOT NULL DEFAULT '',
    shipping_address1 TEXT NOT NULL DEFAULT '',
    shipping_address2 TEXT NOT NULL DEFAULT '',
    shipping_city TEXT NOT NULL DEFAULT '',
    shipping_postal_code TEXT NOT NULL DEFAULT '',
    shipping_region TEXT NOT NULL DEFAULT '',
    shipping_fee_centavos INTEGER NOT NULL DEFAULT 0 CHECK (shipping_fee_centavos >= 0),
    subtotal_centavos INTEGER NOT NULL DEFAULT 0 CHECK (subtotal_centavos >= 0),
    total_centavos INTEGER NOT NULL DEFAULT 0 CHECK (total_centavos >= 0),
    payment_last4 TEXT,
    payment_brand TEXT,
    payment_result TEXT,
    idempotency_key TEXT UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    name_snapshot TEXT NOT NULL,
    price_centavos INTEGER NOT NULL CHECK (price_centavos >= 0),
    qty INTEGER NOT NULL CHECK (qty > 0),
    line_total_centavos INTEGER NOT NULL CHECK (line_total_centavos >= 0)
);

CREATE TABLE stock_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    old_stock INTEGER NOT NULL,
    new_stock INTEGER NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_products_category_active ON products (category, is_active);
CREATE INDEX idx_orders_status_created ON orders (status, created_at);
CREATE INDEX idx_order_items_order ON order_items (order_id);
