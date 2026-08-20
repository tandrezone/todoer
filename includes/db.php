<?php
// Database bootstrap: opens (and initializes/migrates on first run) the SQLite file.

function todoer_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    $dbFile = $dataDir . '/todoer.sqlite';

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $schema = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schema);

    todoer_migrate($pdo);

    // Seed the prize pool once.
    $count = (int) $pdo->query('SELECT COUNT(*) FROM prizes')->fetchColumn();
    if ($count === 0) {
        $prizes = [
            '1 hour of uninterrupted rest / nap time, no interruptions allowed',
            'A 20-minute massage from the runner-up',
            'Pick the movie or show for the next two movie nights',
            'Skip one chore of your choice this week',
            'Breakfast in bed, served by the runner-up',
            'Choose the restaurant or takeout for the next dinner out',
            '30 minutes of extra guilt-free screen time',
            'Someone else does your laundry this week',
            'First shower / bathroom priority for a day',
            'Winner picks the weekend activity',
            'A handwritten "why I appreciate you" note from the others',
            'Free coffee or tea, made for you for 3 days straight',
            'Skip dish duty for a week',
            'Control the music playlist for a full day',
            'A surprise treat or dessert, bought by the runner-up',
            'One "get out of a task" free pass for next week',
            'A 15-minute foot rub from the runner-up',
            'Pick where to eat out next',
            'A lazy Sunday morning: no chores, no alarms',
            'The runner-up handles your least favorite chore next week',
        ];
        $stmt = $pdo->prepare('INSERT INTO prizes (description) VALUES (?)');
        foreach ($prizes as $p) {
            $stmt->execute([$p]);
        }
    }

    return $pdo;
}

/**
 * Upgrades a pre-existing database (created before assignment/priority/window support existed)
 * to the current shape. Safe to call on every boot: each step checks whether it's already been
 * applied before doing anything, so a brand-new install (which gets the current schema.sql
 * straight away) is a no-op here.
 */
function todoer_migrate(PDO $pdo): void {
    // `active` has no CHECK constraint riding on it, so a plain ADD COLUMN is safe.
    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('active', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    }

    $taskCols = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
    if (!in_array('assigned_type', $taskCols, true)) {
        todoer_migrate_tasks_table($pdo);
    }
}

/**
 * Rebuilds `tasks` onto the new shape (new columns + the relaxed status CHECK that allows
 * 'unassigned'/'expired'). SQLite can't ALTER a CHECK constraint in place, so this renames the
 * old table, creates the new one, copies rows across with sensible defaults for columns that
 * didn't exist before, then drops the renamed original. Every pre-existing task already had an
 * owner (`user_id`), which under the old model was also its creator and its only possible
 * assignee -- so those rows map straight onto "already assigned, ANY_USER, MODERATE priority,
 * no window/timer" and keep whatever `status`/points/history they had.
 */
function todoer_migrate_tasks_table(PDO $pdo): void {
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE tasks RENAME TO tasks_pre_assignment');
        $pdo->exec(todoer_tasks_table_ddl());
        $pdo->exec(
            "INSERT INTO tasks (id, user_id, created_by, list_type, period_key, title, points, status,
                                window_start, window_end, assigned_type, assigned_user_id, priority,
                                time_limit_minutes, assigned_at, created_at, completed_at)
             SELECT id, user_id, user_id, list_type, period_key, title, points, status,
                    NULL, NULL, 'ANY_USER', NULL, 'MODERATE',
                    NULL, CASE WHEN status = 'open' THEN created_at ELSE NULL END, created_at, completed_at
             FROM tasks_pre_assignment"
        );
        $pdo->exec('DROP TABLE tasks_pre_assignment');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user_period ON tasks(user_id, list_type, period_key)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_period_status ON tasks(list_type, period_key, status)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/** Keep in sync with the `tasks` table definition in schema.sql -- see the note there. */
function todoer_tasks_table_ddl(): string {
    return "CREATE TABLE tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        list_type TEXT NOT NULL CHECK (list_type IN ('daily','weekly','monthly')),
        period_key TEXT NOT NULL,
        title TEXT NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'unassigned' CHECK (status IN ('unassigned','open','done','expired')),
        window_start TEXT,
        window_end TEXT,
        assigned_type TEXT NOT NULL DEFAULT 'ANY_USER' CHECK (assigned_type IN ('ANY_USER','SPECIFIC_USER')),
        assigned_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        priority TEXT NOT NULL DEFAULT 'MODERATE' CHECK (priority IN ('HIGH','MODERATE','LOW')),
        time_limit_minutes INTEGER,
        assigned_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        completed_at TEXT
    )";
}
