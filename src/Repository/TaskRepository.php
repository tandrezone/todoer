<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ListType;
use App\Domain\Enum\TaskStatus;
use App\Domain\Period\Period;
use App\Domain\Task\Task;
use App\Domain\Task\TaskDraft;
use App\Support\Clock;
use PDO;

/**
 * Every SQL statement that touches `tasks`.
 *
 * Two things worth knowing about this class. First: `group_id` is a parameter of nearly every
 * method, because a task read without its group is how one household ends up seeing another's
 * chores -- keeping the filter in the query (rather than in a caller's `if`) is what makes that
 * impossible to forget. Second: timestamps are written from the injected Clock rather than
 * SQLite's `datetime('now')`. SQLite's is UTC while PHP's date maths runs in the server's
 * timezone, and the original code compared one against the other -- which silently skewed every
 * task timer by the UTC offset on any machine not set to UTC.
 */
final class TaskRepository
{
    private const HOLDER_JOIN = 'LEFT JOIN users u ON u.id = t.user_id';

    private const COLUMNS = 't.*, u.username AS holder_username, u.color AS holder_color';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    public function find(int $taskId, int $groupId): ?Task
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . ' WHERE t.id = ? AND t.group_id = ?'
        );
        $statement->execute([$taskId, $groupId]);
        $row = $statement->fetch();

        return $row === false ? null : Task::fromRow($row);
    }

    /** A task the caller is allowed to change: they hold it, or they added it. */
    public function findEditable(int $taskId, int $groupId, int $userId): ?Task
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . '
             WHERE t.id = ? AND t.group_id = ? AND (t.user_id = ? OR t.created_by = ?)'
        );
        $statement->execute([$taskId, $groupId, $userId, $userId]);
        $row = $statement->fetch();

        return $row === false ? null : Task::fromRow($row);
    }

    /**
     * Everything in one period of one group's list, oldest first, with its current holder.
     *
     * @return list<Task>
     */
    public function forPeriod(int $groupId, Period $period): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . '
             WHERE t.group_id = ? AND t.list_type = ? AND t.period_key = ?
             ORDER BY t.created_at ASC, t.id ASC'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);

        return array_map(static fn(array $row): Task => Task::fromRow($row), $statement->fetchAll());
    }

    /** @return list<Task> */
    public function unassignedForPeriod(int $groupId, Period $period, AssignmentType $assignmentType): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . '
             WHERE t.group_id = ? AND t.list_type = ? AND t.period_key = ?
               AND t.assigned_type = ? AND t.status = ?'
        );
        $statement->execute([
            $groupId,
            $period->listType->value,
            $period->key,
            $assignmentType->value,
            TaskStatus::Unassigned->value,
        ]);

        return array_map(static fn(array $row): Task => Task::fromRow($row), $statement->fetchAll());
    }

    /**
     * Every open task in the installation, for the maintenance sweep.
     *
     * Not group-scoped by design: timers and deadlines have to keep ticking for a group even
     * while nobody in it has the app open. The sweep only ever *writes* within a single task's own
     * group, so this is not a read path that can leak anything between groups.
     *
     * @return list<Task>
     */
    public function allOpen(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . ' WHERE t.status = ?'
        );
        $statement->execute([TaskStatus::Open->value]);

        return array_map(static fn(array $row): Task => Task::fromRow($row), $statement->fetchAll());
    }

    /**
     * Open tasks that have something to be late for -- a window end or an assignment timer.
     *
     * @return list<Task>
     */
    public function openWithTiming(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM tasks t ' . self::HOLDER_JOIN . '
             WHERE t.status = ? AND t.user_id IS NOT NULL AND (t.window_end IS NOT NULL OR t.assigned_at IS NOT NULL)'
        );
        $statement->execute([TaskStatus::Open->value]);

        return array_map(static fn(array $row): Task => Task::fromRow($row), $statement->fetchAll());
    }

    public function countWithStatus(int $groupId, Period $period, TaskStatus ...$statuses): int
    {
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key = ? AND status IN ($placeholders)"
        );
        $statement->execute(array_merge(
            [$groupId, $period->listType->value, $period->key],
            array_map(static fn(TaskStatus $status): string => $status->value, $statuses)
        ));

        return (int) $statement->fetchColumn();
    }

    public function countForPeriod(int $groupId, Period $period): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ?'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Open-task counts per candidate user, used to keep distribution balanced.
     *
     * @param  list<int> $userIds
     * @return array<int, int> user id => open tasks held this period (every candidate present)
     */
    public function openTaskLoad(int $groupId, Period $period, array $userIds): array
    {
        $load = array_fill_keys($userIds, 0);
        if ($userIds === []) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT user_id, COUNT(*) AS open_tasks FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = ? AND user_id IS NOT NULL
             GROUP BY user_id'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key, TaskStatus::Open->value]);

        foreach ($statement->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            if (array_key_exists($userId, $load)) {
                $load[$userId] = (int) $row['open_tasks'];
            }
        }

        return $load;
    }

    /** Period keys of this group's list that have tasks but have not been tallied yet. @return list<string> */
    public function unclosedPeriodKeysBefore(int $groupId, ListType $listType, string $currentKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT period_key FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key < ?
               AND period_key NOT IN (SELECT period_key FROM periods_closed WHERE group_id = ? AND list_type = ?)
             ORDER BY period_key ASC'
        );
        $statement->execute([$groupId, $listType->value, $currentKey, $groupId, $listType->value]);

        return array_map(static fn(array $row): string => (string) $row['period_key'], $statement->fetchAll());
    }

    /**
     * Points scored per user in one period, highest first.
     *
     * @return list<array{user_id: int, total: int}>
     */
    public function pointsByUser(int $groupId, Period $period): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, SUM(points) AS total FROM tasks
             WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = ? AND user_id IS NOT NULL
             GROUP BY user_id
             ORDER BY total DESC'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key, TaskStatus::Done->value]);

        return array_map(
            static fn(array $row): array => ['user_id' => (int) $row['user_id'], 'total' => (int) $row['total']],
            $statement->fetchAll()
        );
    }

    public function insert(TaskDraft $draft, int $groupId, int $createdBy): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tasks
                (group_id, user_id, created_by, list_type, period_key, title, points, status,
                 window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes,
                 occurrence_index, occurrence_count, created_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $groupId,
            $createdBy,
            $draft->listType->value,
            $draft->period->key,
            $draft->title,
            $draft->listType->points(),
            TaskStatus::Unassigned->value,
            $draft->windowStart,
            $draft->windowEnd,
            $draft->assignmentType->value,
            $draft->assignedUserId,
            $draft->priority->value,
            $draft->timeLimitMinutes,
            $draft->occurrenceIndex,
            $draft->occurrenceCount,
            $this->clock->sqlNow(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inserts a task with every field supplied -- the restore path, which has to reproduce status,
     * timing and history rather than create a fresh task.
     *
     * @param array<string, mixed> $fields
     */
    public function insertRestored(array $fields): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tasks
                (group_id, user_id, created_by, list_type, period_key, title, points, status,
                 window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes,
                 occurrence_index, occurrence_count, assigned_at, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $fields['group_id'],
            $fields['user_id'],
            $fields['created_by'],
            $fields['list_type'],
            $fields['period_key'],
            $fields['title'],
            $fields['points'],
            $fields['status'],
            $fields['window_start'],
            $fields['window_end'],
            $fields['assigned_type'],
            $fields['assigned_user_id'],
            $fields['priority'],
            $fields['time_limit_minutes'],
            $fields['occurrence_index'] ?? 1,
            $fields['occurrence_count'] ?? 1,
            $fields['assigned_at'],
            $fields['created_at'],
            $fields['completed_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** An edit that leaves the assignment alone: title, priority, timer, window and occurrence slot only. */
    public function updateDetails(int $taskId, TaskDraft $draft): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET title = ?, priority = ?, time_limit_minutes = ?, window_start = ?, window_end = ?,
                 occurrence_index = ?, occurrence_count = ?
             WHERE id = ?'
        );
        $statement->execute([
            $draft->title,
            $draft->priority->value,
            $draft->timeLimitMinutes,
            $draft->windowStart,
            $draft->windowEnd,
            $draft->occurrenceIndex,
            $draft->occurrenceCount,
            $taskId,
        ]);
    }

    /**
     * An edit that re-points the task at somebody else: it goes back to unassigned so the next
     * Start (or the immediate assignment backstop) hands it out under the new rule, rather than
     * staying on whoever happened to hold it.
     */
    public function updateWithAssignmentReset(int $taskId, TaskDraft $draft): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET title = ?, assigned_type = ?, assigned_user_id = ?, priority = ?,
                 time_limit_minutes = ?, window_start = ?, window_end = ?, occurrence_index = ?, occurrence_count = ?,
                 user_id = NULL, status = ?, assigned_at = NULL, completed_at = NULL
             WHERE id = ?'
        );
        $statement->execute([
            $draft->title,
            $draft->assignmentType->value,
            $draft->assignedUserId,
            $draft->priority->value,
            $draft->timeLimitMinutes,
            $draft->windowStart,
            $draft->windowEnd,
            $draft->occurrenceIndex,
            $draft->occurrenceCount,
            TaskStatus::Unassigned->value,
            $taskId,
        ]);
    }

    public function delete(int $taskId, int $groupId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM tasks WHERE id = ? AND group_id = ? AND (user_id = ? OR created_by = ?)'
        );
        $statement->execute([$taskId, $groupId, $userId, $userId]);
    }

    /** Hands a task to a user: sets the holder, opens it, and stamps assigned_at to now. */
    public function assignTo(int $taskId, int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET user_id = ?, status = ?, assigned_at = ? WHERE id = ?');
        $statement->execute([$userId, TaskStatus::Open->value, $this->clock->sqlNow(), $taskId]);
    }

    public function markExpired(int $taskId): void
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET status = ? WHERE id = ? AND status = ?');
        $statement->execute([TaskStatus::Expired->value, $taskId, TaskStatus::Open->value]);
    }

    public function markDone(int $taskId): void
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET status = ?, completed_at = ? WHERE id = ?');
        $statement->execute([TaskStatus::Done->value, $this->clock->sqlNow(), $taskId]);
    }

    public function reopen(int $taskId): void
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?');
        $statement->execute([TaskStatus::Open->value, $taskId]);
    }

    /**
     * Takes an open task off its current holder, conditionally.
     *
     * The WHERE clause carries the holder and the status, so two people racing for the same task
     * cannot both win it: the second UPDATE matches no rows and the caller turns that into a 409.
     *
     * @return bool Whether the take-over actually happened.
     */
    public function takeOver(int $taskId, int $newUserId, int $fromUserId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET user_id = ? WHERE id = ? AND user_id = ? AND status = ?'
        );
        $statement->execute([$newUserId, $taskId, $fromUserId, TaskStatus::Open->value]);

        return $statement->rowCount() > 0;
    }

    /** Gives a claimed task back to its original holder when the claimer un-ticks it. */
    public function returnTo(int $taskId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET user_id = ?, status = ?, completed_at = NULL WHERE id = ?'
        );
        $statement->execute([$userId, TaskStatus::Open->value, $taskId]);
    }

    /**
     * Every task in a group, with usernames instead of ids, for the export.
     *
     * Usernames rather than raw ids so the file is portable: an id only means something in the
     * database it came from, and would be actively misleading on import elsewhere.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.*, creator.username AS created_by_username,
                    holder.username AS user_username,
                    assignee.username AS assigned_user_username
             FROM tasks t
             JOIN users creator ON creator.id = t.created_by
             LEFT JOIN users holder ON holder.id = t.user_id
             LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
             WHERE t.group_id = ?
             ORDER BY t.list_type ASC, t.period_key ASC, t.created_at ASC'
        );
        $statement->execute([$groupId]);

        return $statement->fetchAll();
    }
}
