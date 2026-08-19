-- Todoer: competitive todo list schema

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#5b8def',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- list_type: 'daily' | 'weekly' | 'monthly'
-- period_key identifies which instance of that list the task belongs to:
--   daily   -> 'YYYY-MM-DD'
--   weekly  -> 'YYYY-WW' (ISO year-week)
--   monthly -> 'YYYY-MM'
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    list_type TEXT NOT NULL CHECK (list_type IN ('daily','weekly','monthly')),
    period_key TEXT NOT NULL,
    title TEXT NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','done')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_tasks_user_period ON tasks(user_id, list_type, period_key);

-- Tracks which (list_type, period_key) periods have already been tallied & awarded,
-- so we never double-award a prize for the same period.
CREATE TABLE IF NOT EXISTS periods_closed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    closed_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(list_type, period_key)
);

CREATE TABLE IF NOT EXISTS prizes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    description TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS awards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    points INTEGER NOT NULL,
    prize_id INTEGER NOT NULL REFERENCES prizes(id),
    claimed INTEGER NOT NULL DEFAULT 0,
    awarded_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(list_type, period_key)
);
