-- Administrator sign-in throttle (SDD §10, PRD R19): failed attempts per
-- hashed email+IP key; five failures lock the key for 60 seconds.
-- Idempotent so Initializer can apply it to databases created before M6.

CREATE TABLE IF NOT EXISTS login_attempts (
    throttle_key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0 CHECK (failures >= 0),
    locked_until INTEGER NOT NULL DEFAULT 0
);
