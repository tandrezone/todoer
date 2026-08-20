-- Todoer: competitive todo list schema

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#5b8def',
    active INTEGER NOT NULL DEFAULT 1,  -- inactive users are skipped by distribution/reassignment
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- list_type: 'daily' | 'weekly' | 'monthly'
-- period_key identifies which instance of that list the task belongs to:
--   daily   -> 'YYYY-MM-DD'
--   weekly  -> 'YYYY-WW' (ISO year-week)
--   monthly -> 'YYYY-MM'
--
-- status lifecycle:
--   unassigned -> open -> done
--                     \-> expired   (missed its window/timer and there was nobody left to hand it to)
-- A timed-out ANY_USER task goes straight back to 'open' (with a new user_id), never through
-- 'unassigned' -- see todoer_process_expirations() in includes/assignment.php.
--
-- NOTE: this DDL is intentionally duplicated in includes/db.php's todoer_tasks_table_ddl(),
-- which is what actually runs against an *existing* install -- SQLite can't ALTER a CHECK
-- constraint in place, so upgrading an old install rebuilds the table instead of adding
-- columns to it. Keep the two definitions in sync if you change this.
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,             -- current holder; NULL while unassigned
    created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,  -- who added the task
    list_type TEXT NOT NULL CHECK (list_type IN ('daily','weekly','monthly')),
    period_key TEXT NOT NULL,
    title TEXT NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'unassigned' CHECK (status IN ('unassigned','open','done','expired')),
    window_start TEXT,               -- Time1: earliest datetime the task can be performed
    window_end TEXT,                 -- Time2: latest datetime it must be completed by
    assigned_type TEXT NOT NULL DEFAULT 'ANY_USER' CHECK (assigned_type IN ('ANY_USER','SPECIFIC_USER')),
    assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,    -- the locked/designated user when SPECIFIC_USER
    priority TEXT NOT NULL DEFAULT 'MODERATE' CHECK (priority IN ('HIGH','MODERATE','LOW')),
    time_limit_minutes INTEGER,      -- minutes allowed once assigned; HIGH priority ignores this and always
                                      -- uses the shorter dynamic HIGH-priority limit instead (see assignment.php)
    assigned_at TEXT,                 -- when the *current* holder received it; resets on every (re)assignment
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_tasks_user_period ON tasks(user_id, list_type, period_key);
CREATE INDEX IF NOT EXISTS idx_tasks_period_status ON tasks(list_type, period_key, status);

-- Append-only audit trail of assignment/reassignment/expiry events, so a timed-out
-- reassignment is explainable ("why did this move to Sam?") instead of a silent mutation.
CREATE TABLE IF NOT EXISTS task_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
    event TEXT NOT NULL CHECK (event IN ('assigned','reassigned','expired','completed','reopened')),
    from_user_id INTEGER REFERENCES users(id),
    to_user_id INTEGER REFERENCES users(id),
    note TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Tracks the Start/Stop state of a given (list_type, period_key)'s game. `running` is the live
-- toggle the Start/Stop button flips: while running, new tasks can't be added and the list view
-- is locked down to just marking assigned tasks done; while stopped, tasks can be added/edited
-- again. The row itself (once created) also means "distribution has run at least once for this
-- period", so re-clicking Start only sweeps up tasks added since rather than reshuffling
-- everything.
CREATE TABLE IF NOT EXISTS game_starts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    running INTEGER NOT NULL DEFAULT 1,
    started_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(list_type, period_key)
);

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

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    event_key TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    read_at TEXT,
    UNIQUE(user_id, event_key)
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
