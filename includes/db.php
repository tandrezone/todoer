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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            event_key TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            read_at TEXT,
            UNIQUE(user_id, event_key)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );

    // `active` has no CHECK constraint riding on it, so a plain ADD COLUMN is safe.
    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('active', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
    }

    $taskCols = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
    if (!in_array('assigned_type', $taskCols, true)) {
        todoer_migrate_tasks_table($pdo);
    }

    todoer_migrate_groups($pdo);

    // `running` has no CHECK constraint either -- plain ADD COLUMN, defaulting existing rows
    // (periods that were already started under the old one-way "Start" behavior) to running=1.
    $gameStartCols = array_column($pdo->query('PRAGMA table_info(game_starts)')->fetchAll(), 'name');
    if (!in_array('running', $gameStartCols, true)) {
        $pdo->exec('ALTER TABLE game_starts ADD COLUMN running INTEGER NOT NULL DEFAULT 1');
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
        // group_id may or may not exist on the pre-assignment table (it does if this install
        // already went through the groups migration). Carry it over when present; otherwise the
        // groups migration below backfills it.
        $preCols = array_column($pdo->query('PRAGMA table_info(tasks_pre_assignment)')->fetchAll(), 'name');
        $groupCol = in_array('group_id', $preCols, true) ? 'group_id' : 'NULL';
        $pdo->exec(
            "INSERT INTO tasks (id, group_id, user_id, created_by, list_type, period_key, title, points, status,
                                window_start, window_end, assigned_type, assigned_user_id, priority,
                                time_limit_minutes, assigned_at, created_at, completed_at)
             SELECT id, $groupCol, user_id, user_id, list_type, period_key, title, points, status,
                    NULL, NULL, 'ANY_USER', NULL, 'MODERATE',
                    NULL, CASE WHEN status = 'open' THEN created_at ELSE NULL END, created_at, completed_at
             FROM tasks_pre_assignment"
        );
        $pdo->exec('DROP TABLE tasks_pre_assignment');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user_period ON tasks(user_id, list_type, period_key)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_period_status ON tasks(list_type, period_key, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_group_period ON tasks(group_id, list_type, period_key, status)');
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
        group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE,
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

/**
 * Adds the group scoping introduced with shared/competing groups to an install that predates
 * it: the group_id column on `tasks`, the group_id column plus group-scoped UNIQUE constraints
 * on `game_starts`/`periods_closed`/`awards`, and a single "legacy" group that every existing
 * user, task, award and period-close row is attached to -- because before groups existed, one
 * install *was* one group, so folding it into one group is the only migration that preserves
 * the scores and prize history people already have.
 *
 * Each step is guarded by a column check, so this is a no-op on a fresh install (which gets the
 * current schema.sql straight away) and on every boot after the first upgrade.
 */
function todoer_migrate_groups(PDO $pdo): void {
    // The tables themselves come from schema.sql (CREATE TABLE IF NOT EXISTS), so by the time
    // we get here `groups`/`group_members` exist -- only the *other* tables need reshaping.

    // tasks.group_id: no CHECK constraint rides on it, so a plain ADD COLUMN is safe.
    $taskCols = array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
    if (!in_array('group_id', $taskCols, true)) {
        $pdo->exec('ALTER TABLE tasks ADD COLUMN group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE');
    }
    // Created here rather than in schema.sql, which can't index a column that an existing
    // install's `tasks` table doesn't have yet -- see the note there.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_group_period ON tasks(group_id, list_type, period_key, status)');

    // These three carry a UNIQUE(...) that now has to include group_id, and SQLite can't ALTER a
    // constraint in place -- so each is rebuilt rather than altered.
    todoer_migrate_add_group_column($pdo, 'game_starts',
        'CREATE TABLE game_starts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            list_type TEXT NOT NULL,
            period_key TEXT NOT NULL,
            running INTEGER NOT NULL DEFAULT 1,
            started_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(group_id, list_type, period_key)
        )',
        ['id', 'list_type', 'period_key', 'running', 'started_at']
    );
    todoer_migrate_add_group_column($pdo, 'periods_closed',
        'CREATE TABLE periods_closed (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            list_type TEXT NOT NULL,
            period_key TEXT NOT NULL,
            closed_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(group_id, list_type, period_key)
        )',
        ['id', 'list_type', 'period_key', 'closed_at']
    );
    todoer_migrate_add_group_column($pdo, 'awards',
        'CREATE TABLE awards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            list_type TEXT NOT NULL,
            period_key TEXT NOT NULL,
            points INTEGER NOT NULL,
            prize_id INTEGER NOT NULL REFERENCES prizes(id),
            claimed INTEGER NOT NULL DEFAULT 0,
            awarded_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE(group_id, list_type, period_key)
        )',
        ['id', 'user_id', 'list_type', 'period_key', 'points', 'prize_id', 'claimed', 'awarded_at']
    );

    todoer_migrate_backfill_legacy_group($pdo);
}

/**
 * Rebuilds $table onto $newDdl (which adds a NOT NULL group_id and a group-scoped UNIQUE),
 * copying $carryColumns across and stamping the legacy group id onto every copied row. No-op
 * once the column exists.
 *
 * Because group_id is NOT NULL, the copy has to supply it inline rather than backfilling
 * afterwards. If there is no legacy group (no users at all -- i.e. a fresh install that somehow
 * has start/close/award rows), there is no group these rows could belong to, so the rebuilt
 * table is simply left empty.
 */
function todoer_migrate_add_group_column(PDO $pdo, string $table, string $newDdl, array $carryColumns): void {
    $cols = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
    if (in_array('group_id', $cols, true)) {
        return;
    }

    $legacyGroupId = todoer_migrate_legacy_group_id($pdo);
    $carry = implode(', ', $carryColumns);

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE ' . $table . ' RENAME TO ' . $table . '_pre_groups');
        $pdo->exec($newDdl);
        if ($legacyGroupId !== null) {
            $pdo->exec(
                "INSERT INTO $table (group_id, $carry)
                 SELECT $legacyGroupId, $carry FROM {$table}_pre_groups"
            );
        }
        $pdo->exec('DROP TABLE ' . $table . '_pre_groups');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

/**
 * The group that pre-groups data belongs to: one group holding every existing user, created on
 * demand the first time it's needed. Returns null when there are no users at all (a fresh
 * install), in which case there is nothing to migrate.
 */
function todoer_migrate_legacy_group_id(PDO $pdo): ?int {
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        return null;
    }

    // Reuse the group an earlier partial run already created, so a migration interrupted
    // half-way doesn't scatter the same install across two groups on the next boot.
    $existing = $pdo->query('SELECT id FROM groups ORDER BY id LIMIT 1')->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $firstUserId = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO groups (name, invite_code, created_by) VALUES (?, ?, ?)');
    $stmt->execute(['Our group', todoer_generate_invite_code($pdo), $firstUserId !== false ? (int) $firstUserId : null]);
    return (int) $pdo->lastInsertId();
}

/**
 * Puts every user without a group into the legacy group (the first user becomes its admin, the
 * rest members) and stamps that group onto every task row that predates the column.
 */
function todoer_migrate_backfill_legacy_group(PDO $pdo): void {
    $orphanUsers = (int) $pdo->query(
        'SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id)'
    )->fetchColumn();
    $orphanTasks = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE group_id IS NULL')->fetchColumn();
    if ($orphanUsers === 0 && $orphanTasks === 0) {
        return;
    }

    $legacyGroupId = todoer_migrate_legacy_group_id($pdo);
    if ($legacyGroupId === null) {
        return;
    }

    $hasAdmin = (int) $pdo->query(
        "SELECT COUNT(*) FROM group_members WHERE group_id = " . $legacyGroupId . " AND role = 'admin'"
    )->fetchColumn() > 0;

    $users = $pdo->query(
        'SELECT u.id FROM users u WHERE NOT EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id) ORDER BY u.id'
    )->fetchAll();
    $insert = $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    foreach ($users as $i => $row) {
        $role = (!$hasAdmin && $i === 0) ? 'admin' : 'member';
        $insert->execute([$legacyGroupId, (int) $row['id'], $role]);
    }

    $pdo->exec('UPDATE tasks SET group_id = ' . $legacyGroupId . ' WHERE group_id IS NULL');
}

/** A short, human-typeable invite code that isn't already in use. */
function todoer_generate_invite_code(PDO $pdo): string {
    // No 0/O/1/I/L -- these get misread when someone reads a code out loud or off a screen.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $check = $pdo->prepare('SELECT 1 FROM groups WHERE invite_code = ?');
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $check->execute([$code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique invite code.');
}
