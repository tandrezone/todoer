-- Todoer: competitive todo list schema

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#5b8def',
    active INTEGER NOT NULL DEFAULT 1,  -- inactive users are skipped by distribution/reassignment
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------------
-- Groups: the privacy + competition boundary. Everything scoreable (tasks,
-- start/stop state, closed periods, awards) belongs to exactly one group, and
-- every query in the app is filtered by the caller's group -- so two groups
-- using the same install never see each other's tasks and never appear in each
-- other's leaderboards or prize history.
--
-- Membership is exactly one group per user (enforced by the unique index on
-- group_members.user_id below). Registering creates a personal group with the
-- new user as its admin, so "no group" is never a state the app has to handle;
-- joining someone else's group (via invite code, or by being added by that
-- group's admin) moves the user across.
--
-- role = 'admin' -> can add/remove members, rename the group, roll the invite
-- code. A group always keeps at least one admin.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    invite_code TEXT NOT NULL UNIQUE,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS group_members (
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','member')),
    joined_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (group_id, user_id)
);

-- One group per user: this is what makes "the scope is only the group" a data
-- invariant rather than something each query has to remember.
CREATE UNIQUE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id);

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
-- 'unassigned' -- see App\Service\AssignmentService::processExpirations().
--
-- NOTE: this DDL is intentionally duplicated in App\Database\Migrator::tasksTableDdl(), which is
-- what actually runs against an *existing* install -- SQLite can't ALTER a CHECK constraint in
-- place, so upgrading an old install rebuilds the table instead of adding columns to it. Keep the
-- two definitions in sync if you change this.
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE,            -- owning group; every task query filters on this
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
-- NOTE: the group_id index lives in App\Database\Migrator rather than here. On an existing
-- install the CREATE TABLE above is skipped (IF NOT EXISTS) so tasks.group_id doesn't exist yet,
-- and indexing a missing column would abort this whole script -- the migration creates the
-- column and the index together instead, for new and upgraded installs alike.

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
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    running INTEGER NOT NULL DEFAULT 1,
    started_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(group_id, list_type, period_key)
);

-- Tracks which (list_type, period_key) periods have already been tallied & awarded,
-- so we never double-award a prize for the same period.
CREATE TABLE IF NOT EXISTS periods_closed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    closed_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(group_id, list_type, period_key)
);

CREATE TABLE IF NOT EXISTS prizes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    description TEXT NOT NULL
);

-- The prize list is per group: UNIQUE(group_id, list_type, period_key) means each
-- group crowns its own winner for the same day/week/month, and one group's awards
-- never show up in another's history (see App\Controller\Api\PrizeApiController).
CREATE TABLE IF NOT EXISTS awards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    list_type TEXT NOT NULL,
    period_key TEXT NOT NULL,
    points INTEGER NOT NULL,
    prize_id INTEGER NOT NULL REFERENCES prizes(id),
    claimed INTEGER NOT NULL DEFAULT 0,
    awarded_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(group_id, list_type, period_key)
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
