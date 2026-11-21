-- PRD 2.0 heritage tables (SDD §15.2). Idempotent: applied on first boot and
-- to existing v1 databases. The v1 products/orders tables are rebuilt and the
-- new order_items/stock_audit columns added by HeritageUpgrade (PHP), because
-- SQLite cannot alter CHECK constraints or add columns conditionally in SQL.

CREATE TABLE IF NOT EXISTS weavers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    community TEXT NOT NULL DEFAULT '',
    region TEXT NOT NULL DEFAULT '',
    fibers TEXT NOT NULL DEFAULT '',
    techniques TEXT NOT NULL DEFAULT '',
    story TEXT NOT NULL DEFAULT '',
    story_fil TEXT,
    photo_path TEXT,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_by_admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL UNIQUE,
    name_fil TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))
);

CREATE TABLE IF NOT EXISTS journal_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    title_fil TEXT,
    excerpt TEXT NOT NULL DEFAULT '',
    excerpt_fil TEXT,
    body TEXT NOT NULL DEFAULT '',
    body_fil TEXT,
    sources TEXT NOT NULL DEFAULT '',
    cover_path TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
    published_at TEXT,
    updated_by_admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS order_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    field TEXT NOT NULL CHECK (field IN ('status', 'payment_status')),
    old_value TEXT,
    new_value TEXT NOT NULL,
    actor_role TEXT NOT NULL CHECK (actor_role IN ('customer', 'admin', 'system')),
    actor_id INTEGER,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS payment_proofs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    path TEXT NOT NULL,
    sender_reference TEXT NOT NULL DEFAULT '',
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now')),
    reviewed_by_admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    reviewed_at TEXT,
    review_note TEXT
);

CREATE INDEX IF NOT EXISTS idx_order_events_order_created ON order_events (order_id, created_at);
