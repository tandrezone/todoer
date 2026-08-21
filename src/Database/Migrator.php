<?php

declare(strict_types=1);

namespace App\Database;

use App\Domain\Prize\PrizePool;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Creates the schema on first run and brings an older database up to the current shape.
 *
 * Every step is guarded by an "is this already applied?" check, so running it on every boot is a
 * no-op after the first, and a brand-new installation (which gets the current schema.sql straight
 * away) skips all of it. The awkward parts are SQLite's: a CHECK constraint or a UNIQUE(...) can't
 * be altered in place, so those tables are rebuilt and copied instead of altered.
 *
 * Compared with the original includes/db.php this is the same sequence of migrations with the
 * string-interpolated ids removed -- the legacy group id used to be concatenated straight into
 * INSERT/UPDATE statements, which is now bound.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $schemaFile
    ) {
    }

    public function migrate(): void
    {
        $this->applySchema();
        $this->ensureNotificationTables();
        $this->ensureUsersActiveColumn();
        $this->ensureTaskAssignmentColumns();
        $this->ensureGroupScoping();
        $this->ensureGameStartsRunningColumn();
        $this->ensureTaskOccurrenceColumns();
        $this->seedPrizePool();
    }

    private function applySchema(): void
    {
        $schema = @file_get_contents($this->schemaFile);
        if ($schema === false) {
            throw new RuntimeException('Could not read the database schema at ' . $this->schemaFile);
        }
        $this->pdo->exec($schema);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $rows = $this->pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');

        return $rows === false ? [] : array_map('strval', array_column($rows->fetchAll(), 'name'));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function ensureNotificationTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                event_key TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                read_at TEXT,
                UNIQUE(user_id, event_key)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                endpoint TEXT NOT NULL UNIQUE,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }

    /** No CHECK constraint rides on `active`, so a plain ADD COLUMN is safe. */
    private function ensureUsersActiveColumn(): void
    {
        if (!$this->hasColumn('users', 'active')) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
        }
    }

    /**
     * Rebuilds `tasks` onto the assignment-era shape (new columns plus the relaxed status CHECK
     * that allows 'unassigned'/'expired').
     *
     * Every pre-existing task already had an owner, which under the old model was also its creator
     * and its only possible assignee -- so those rows map onto "already assigned, ANY_USER,
     * MODERATE, no window or timer" and keep whatever status, points and history they had.
     */
    private function ensureTaskAssignmentColumns(): void
    {
        if ($this->hasColumn('tasks', 'assigned_type')) {
            return;
        }

        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('ALTER TABLE tasks RENAME TO tasks_pre_assignment');
            $this->pdo->exec(self::tasksTableDdl());
            // group_id may or may not exist on the old table (it does if this install already went
            // through the groups migration); carry it over when present, otherwise the groups
            // migration below backfills it.
            $groupColumn = $this->hasColumn('tasks_pre_assignment', 'group_id') ? 'group_id' : 'NULL';
            $this->pdo->exec(
                "INSERT INTO tasks (id, group_id, user_id, created_by, list_type, period_key, title, points, status,
                                    window_start, window_end, assigned_type, assigned_user_id, priority,
                                    time_limit_minutes, assigned_at, created_at, completed_at)
                 SELECT id, $groupColumn, user_id, user_id, list_type, period_key, title, points, status,
                        NULL, NULL, 'ANY_USER', NULL, 'MODERATE',
                        NULL, CASE WHEN status = 'open' THEN created_at ELSE NULL END, created_at, completed_at
                 FROM tasks_pre_assignment"
            );
            $this->pdo->exec('DROP TABLE tasks_pre_assignment');
            $this->createTaskIndexes();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private function createTaskIndexes(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user_period ON tasks(user_id, list_type, period_key)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_period_status ON tasks(list_type, period_key, status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_group_period ON tasks(group_id, list_type, period_key, status)');
    }

    /** Keep in sync with the `tasks` table in database/schema.sql -- see the note there. */
    public static function tasksTableDdl(): string
    {
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
            occurrence_index INTEGER NOT NULL DEFAULT 1,
            occurrence_count INTEGER NOT NULL DEFAULT 1,
            assigned_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            completed_at TEXT
        )";
    }

    /**
     * Adds group scoping to an installation that predates groups: the group_id column on tasks,
     * group_id plus group-scoped UNIQUE constraints on game_starts/periods_closed/awards, and one
     * "legacy" group that every existing user, task, award and period-close row is attached to --
     * because before groups existed, one installation *was* one group, so folding it into a single
     * group is the only migration that preserves the scores and prize history people already have.
     */
    private function ensureGroupScoping(): void
    {
        if (!$this->hasColumn('tasks', 'group_id')) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN group_id INTEGER REFERENCES groups(id) ON DELETE CASCADE');
        }
        // Created here rather than in schema.sql, which cannot index a column an existing
        // installation's tasks table does not have yet.
        $this->createTaskIndexes();

        $this->rebuildWithGroupColumn(
            'game_starts',
            "CREATE TABLE game_starts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
                list_type TEXT NOT NULL,
                period_key TEXT NOT NULL,
                running INTEGER NOT NULL DEFAULT 1,
                started_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(group_id, list_type, period_key)
            )",
            ['id', 'list_type', 'period_key', 'running', 'started_at']
        );
        $this->rebuildWithGroupColumn(
            'periods_closed',
            "CREATE TABLE periods_closed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
                list_type TEXT NOT NULL,
                period_key TEXT NOT NULL,
                closed_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(group_id, list_type, period_key)
            )",
            ['id', 'list_type', 'period_key', 'closed_at']
        );
        $this->rebuildWithGroupColumn(
            'awards',
            "CREATE TABLE awards (
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
            )",
            ['id', 'user_id', 'list_type', 'period_key', 'points', 'prize_id', 'claimed', 'awarded_at']
        );

        $this->backfillLegacyGroup();
    }

    /**
     * Rebuilds $table onto $newDdl (which adds a NOT NULL group_id and a group-scoped UNIQUE),
     * carrying $carryColumns across and stamping the legacy group id onto every copied row.
     *
     * @param list<string> $carryColumns
     */
    private function rebuildWithGroupColumn(string $table, string $newDdl, array $carryColumns): void
    {
        if ($this->hasColumn($table, 'group_id')) {
            return;
        }

        $legacyGroupId = $this->legacyGroupId();
        $carry = implode(', ', array_map([$this, 'quoteIdentifier'], $carryColumns));
        $quoted = $this->quoteIdentifier($table);

        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('ALTER TABLE ' . $quoted . ' RENAME TO ' . $this->quoteIdentifier($table . '_pre_groups'));
            $this->pdo->exec($newDdl);
            if ($legacyGroupId !== null) {
                // group_id is NOT NULL, so the copy has to supply it inline -- bound, not
                // interpolated. With no legacy group (a fresh install that somehow has rows here)
                // there is no group these rows could belong to, so the rebuilt table stays empty.
                $insert = $this->pdo->prepare(
                    "INSERT INTO $quoted (group_id, $carry) SELECT ?, $carry FROM " . $this->quoteIdentifier($table . '_pre_groups')
                );
                $insert->execute([$legacyGroupId]);
            }
            $this->pdo->exec('DROP TABLE ' . $this->quoteIdentifier($table . '_pre_groups'));
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * The group that pre-groups data belongs to: one group holding every existing user, created on
     * demand the first time it is needed. Null when there are no users at all (a fresh install),
     * in which case there is nothing to migrate.
     */
    private function legacyGroupId(): ?int
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            return null;
        }

        // Reuse the group an earlier partial run already created, so a migration interrupted
        // half-way doesn't scatter one installation across two groups on the next boot.
        $existing = $this->pdo->query('SELECT id FROM groups ORDER BY id LIMIT 1')->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $firstUserId = $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO groups (name, invite_code, created_by) VALUES (?, ?, ?)');
        $insert->execute(['Our group', InviteCodeGenerator::generate($this->pdo), $firstUserId !== false ? (int) $firstUserId : null]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Puts every group-less user into the legacy group (the first becomes its admin, the rest
     * members) and stamps that group onto every task row that predates the column.
     */
    private function backfillLegacyGroup(): void
    {
        $orphanUsers = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM users u WHERE NOT EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id)'
        )->fetchColumn();
        $orphanTasks = (int) $this->pdo->query('SELECT COUNT(*) FROM tasks WHERE group_id IS NULL')->fetchColumn();
        if ($orphanUsers === 0 && $orphanTasks === 0) {
            return;
        }

        $legacyGroupId = $this->legacyGroupId();
        if ($legacyGroupId === null) {
            return;
        }

        $adminCheck = $this->pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND role = 'admin'");
        $adminCheck->execute([$legacyGroupId]);
        $hasAdmin = (int) $adminCheck->fetchColumn() > 0;

        $users = $this->pdo->query(
            'SELECT u.id FROM users u WHERE NOT EXISTS (SELECT 1 FROM group_members gm WHERE gm.user_id = u.id) ORDER BY u.id'
        )->fetchAll();
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
        foreach ($users as $index => $row) {
            $insert->execute([$legacyGroupId, (int) $row['id'], (!$hasAdmin && $index === 0) ? 'admin' : 'member']);
        }

        $stamp = $this->pdo->prepare('UPDATE tasks SET group_id = ? WHERE group_id IS NULL');
        $stamp->execute([$legacyGroupId]);
    }

    /**
     * `running` has no CHECK constraint, so a plain ADD COLUMN works; existing rows (periods
     * started under the old one-way "Start" behaviour) default to running.
     */
    private function ensureGameStartsRunningColumn(): void
    {
        if (!$this->hasColumn('game_starts', 'running')) {
            $this->pdo->exec('ALTER TABLE game_starts ADD COLUMN running INTEGER NOT NULL DEFAULT 1');
        }
    }

    /**
     * The "times per period" columns: neither carries a CHECK constraint, so a plain ADD COLUMN
     * works. Every existing task defaults to occurrence_index = occurrence_count = 1, i.e. "one
     * slice covering the whole task" -- exactly what it already was.
     */
    private function ensureTaskOccurrenceColumns(): void
    {
        if (!$this->hasColumn('tasks', 'occurrence_index')) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN occurrence_index INTEGER NOT NULL DEFAULT 1');
        }
        if (!$this->hasColumn('tasks', 'occurrence_count')) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN occurrence_count INTEGER NOT NULL DEFAULT 1');
        }
    }

    private function seedPrizePool(): void
    {
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM prizes')->fetchColumn() > 0) {
            return;
        }

        $insert = $this->pdo->prepare('INSERT INTO prizes (description) VALUES (?)');
        foreach (PrizePool::defaults() as $description) {
            $insert->execute([$description]);
        }
    }

    /**
     * Table and column names cannot be bound as parameters, so the few places that need one
     * interpolated go through this: anything outside [A-Za-z0-9_] is refused outright rather
     * than escaped, because every caller here passes a literal from this file.
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException('Refusing to use "' . $identifier . '" as a SQL identifier.');
        }

        return '"' . $identifier . '"';
    }
}
