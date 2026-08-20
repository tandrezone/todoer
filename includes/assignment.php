<?php
// Task assignment & workflow engine: distribution on "Start", view ordering, and the
// execution/expiration/reassignment lifecycle described in the game's task-assignment spec.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period.php';

const TODOER_PRIORITIES = ['HIGH', 'MODERATE', 'LOW'];

// HIGH priority tasks always get this shorter completion timer once assigned, regardless of
// whatever time_limit_minutes was set on the task -- see todoer_effective_time_limit_minutes().
// "Dynamic" here means it's measured from the moment of (re)assignment (assigned_at), not a
// fixed wall-clock time -- change this single constant to retune it app-wide.
const TODOER_HIGH_PRIORITY_TIME_LIMIT_MINUTES = 30;

const TODOER_PRIORITY_RANK = ['HIGH' => 0, 'MODERATE' => 1, 'LOW' => 2];

/** Active (currently playing) users, in a stable order used for tie-breaking. */
function todoer_active_users(PDO $pdo): array {
    return $pdo->query('SELECT id, username, color FROM users WHERE active = 1 ORDER BY username')->fetchAll();
}

/**
 * The completion timer that actually applies to this task: the HIGH-priority dynamic limit for
 * HIGH tasks (ignoring whatever was configured), otherwise the task's own time_limit_minutes
 * (which may be null, meaning "no per-task timer -- only window_end applies").
 */
function todoer_effective_time_limit_minutes(array $task): ?int {
    if (($task['priority'] ?? null) === 'HIGH') {
        return TODOER_HIGH_PRIORITY_TIME_LIMIT_MINUTES;
    }
    return $task['time_limit_minutes'] !== null ? (int) $task['time_limit_minutes'] : null;
}

/**
 * The moment this task becomes overdue for its *current* holder, or null if neither a window
 * nor a timer applies. This is the earlier of window_end (Time2) and assigned_at + the
 * effective time limit -- whichever constraint bites first.
 */
function todoer_task_deadline(array $task): ?string {
    $candidates = [];
    if (!empty($task['window_end'])) {
        $candidates[] = $task['window_end'];
    }
    if (!empty($task['assigned_at'])) {
        $limit = todoer_effective_time_limit_minutes($task);
        if ($limit !== null) {
            $candidates[] = date('Y-m-d H:i:s', strtotime($task['assigned_at']) + $limit * 60);
        }
    }
    if (!$candidates) {
        return null;
    }
    sort($candidates);
    return $candidates[0];
}

/**
 * Sort order for a user's task list view:
 *   1. window_start ascending (tasks with no window sort last)
 *   2. priority: HIGH > MODERATE > LOW
 */
function todoer_sort_tasks_for_view(array $tasks): array {
    usort($tasks, function (array $a, array $b): int {
        $aw = $a['window_start'] ?? null;
        $bw = $b['window_start'] ?? null;
        if ($aw !== $bw) {
            if ($aw === null) return 1;
            if ($bw === null) return -1;
            $cmp = strcmp($aw, $bw);
            if ($cmp !== 0) return $cmp;
        }
        $ar = TODOER_PRIORITY_RANK[$a['priority']] ?? 1;
        $br = TODOER_PRIORITY_RANK[$b['priority']] ?? 1;
        return $ar <=> $br;
    });
    return $tasks;
}

/** Current open-task load per active user for a period, used to keep distribution balanced. */
function todoer_open_task_load(PDO $pdo, string $listType, string $periodKey, array $activeUsers): array {
    $load = array_fill_keys(array_column($activeUsers, 'id'), 0);
    $stmt = $pdo->prepare(
        "SELECT user_id, COUNT(*) AS c FROM tasks
         WHERE list_type = ? AND period_key = ? AND status = 'open' AND user_id IS NOT NULL
         GROUP BY user_id"
    );
    $stmt->execute([$listType, $periodKey]);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists((int) $row['user_id'], $load)) {
            $load[(int) $row['user_id']] = (int) $row['c'];
        }
    }
    return $load;
}

/** Assigns one task to one user: sets the holder, opens it, and stamps assigned_at to now. */
function todoer_assign_task_to_user(PDO $pdo, int $taskId, int $userId, ?int $fromUserId, string $event = 'assigned', ?string $note = null): void {
    $stmt = $pdo->prepare("UPDATE tasks SET user_id = ?, status = 'open', assigned_at = datetime('now') WHERE id = ?");
    $stmt->execute([$userId, $taskId]);
    todoer_log_task_event($pdo, $taskId, $event, $fromUserId, $userId, $note);
}

function todoer_log_task_event(PDO $pdo, int $taskId, string $event, ?int $fromUserId, ?int $toUserId, ?string $note = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO task_history (task_id, event, from_user_id, to_user_id, note) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$taskId, $event, $fromUserId, $toUserId, $note]);
}

/**
 * The "On Start" distribution:
 *   1. Locked tasks (SPECIFIC_USER) go directly to their designated user.
 *   2. Remaining open tasks (ANY_USER) are handed out to balance workload: each task goes to
 *      whichever active user currently holds the fewest open tasks this period (ties -> lowest
 *      user id), re-checking the running count after every assignment so a run of tasks spreads
 *      out evenly rather than piling onto whoever was least-loaded at the very start.
 * Idempotent: only touches tasks still in 'unassigned' status, so re-running it (e.g. because a
 * task was added mid-period) just sweeps up what's new instead of reshuffling everything.
 */
function todoer_start_game(PDO $pdo, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $activeUsers = todoer_active_users($pdo);
    if (!$activeUsers) {
        return ['started' => false, 'reason' => 'No active users to assign tasks to.'];
    }

    $pdo->beginTransaction();
    try {
        // 1. Locked tasks first.
        $stmt = $pdo->prepare(
            "SELECT * FROM tasks WHERE list_type = ? AND period_key = ?
             AND assigned_type = 'SPECIFIC_USER' AND status = 'unassigned'"
        );
        $stmt->execute([$listType, $periodKey]);
        $locked = $stmt->fetchAll();
        foreach ($locked as $task) {
            if (empty($task['assigned_user_id'])) {
                continue; // malformed row (locked with nobody designated) -- leave for manual fixup
            }
            todoer_assign_task_to_user($pdo, (int) $task['id'], (int) $task['assigned_user_id'], null, 'assigned', 'locked to designated user on start');
        }

        // 2. Open (ANY_USER) tasks, balanced across active users.
        $stmt = $pdo->prepare(
            "SELECT * FROM tasks WHERE list_type = ? AND period_key = ?
             AND assigned_type = 'ANY_USER' AND status = 'unassigned'"
        );
        $stmt->execute([$listType, $periodKey]);
        $open = todoer_sort_tasks_for_view($stmt->fetchAll());

        $load = todoer_open_task_load($pdo, $listType, $periodKey, $activeUsers);
        foreach ($open as $task) {
            asort($load);
            $targetUserId = array_key_first($load);
            todoer_assign_task_to_user($pdo, (int) $task['id'], (int) $targetUserId, null, 'assigned', 'distributed on start');
            $load[$targetUserId]++;
        }

        // Marks the period as started AND running=1 -- clicking Start again after a Stop
        // re-runs this whole distribution (sweeping up anything added while stopped) and flips
        // running back on.
        $mark = $pdo->prepare(
            'INSERT INTO game_starts (list_type, period_key, running) VALUES (?, ?, 1)
             ON CONFLICT(list_type, period_key) DO UPDATE SET running = 1'
        );
        $mark->execute([$listType, $periodKey]);

        $pdo->commit();
        return [
            'started' => true,
            'running' => true,
            'locked_assigned' => count($locked),
            'open_assigned' => count($open),
            'active_users' => count($activeUsers),
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * The Stop half of the Start/Stop toggle: flips a started period back to not-running, without
 * touching any task's assignment. While stopped, tasks can be added/edited again; while
 * running, the list view locks down to just marking assigned tasks done (enforced in
 * api/tasks.php's 'add' action and mirrored in the front-end). No-op if the period was never
 * started in the first place -- there's nothing to stop.
 */
function todoer_stop_game(PDO $pdo, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $stmt = $pdo->prepare(
        "UPDATE game_starts SET running = 0 WHERE list_type = ? AND period_key = ?"
    );
    $stmt->execute([$listType, $periodKey]);
    if ($stmt->rowCount() === 0) {
        return ['stopped' => false, 'reason' => 'This list has not been started yet.'];
    }
    return ['stopped' => true, 'running' => false];
}

/** Whether a period's game is currently in the "running" (started, not stopped) state. */
function todoer_is_game_running(PDO $pdo, string $listType, string $periodKey): bool {
    $stmt = $pdo->prepare('SELECT running FROM game_starts WHERE list_type = ? AND period_key = ?');
    $stmt->execute([$listType, $periodKey]);
    $running = $stmt->fetchColumn();
    return $running !== false && (int) $running === 1;
}

/**
 * Assigns a single freshly-created task immediately, for tasks added after the period's game
 * has already been started (rather than leaving them stranded as 'unassigned' until the next
 * manual Start). No-op if the period isn't currently running, or the task isn't unassigned.
 * In normal use, api/tasks.php's 'add' action already refuses to add a task while running, so
 * this is mostly a defensive backstop.
 */
function todoer_maybe_assign_new_task(PDO $pdo, int $taskId): void {
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if (!$task || $task['status'] !== 'unassigned') {
        return;
    }

    if (!todoer_is_game_running($pdo, $task['list_type'], $task['period_key'])) {
        return; // wait for the next explicit Start
    }

    if ($task['assigned_type'] === 'SPECIFIC_USER') {
        if (!empty($task['assigned_user_id'])) {
            todoer_assign_task_to_user($pdo, $taskId, (int) $task['assigned_user_id'], null, 'assigned', 'locked to designated user');
        }
        return;
    }

    $activeUsers = todoer_active_users($pdo);
    if (!$activeUsers) {
        return;
    }
    $load = todoer_open_task_load($pdo, $task['list_type'], $task['period_key'], $activeUsers);
    asort($load);
    $targetUserId = array_key_first($load);
    todoer_assign_task_to_user($pdo, $taskId, (int) $targetUserId, null, 'assigned', 'distributed on add (period already started)');
}

/**
 * Execution/expiration/reassignment sweep: finds every currently 'open' task whose deadline
 * (window_end, or assigned_at + effective time limit, whichever is sooner) has passed and:
 *   - ANY_USER task: unassign from the current holder and hand it to a different active user
 *     (least-loaded among the remaining candidates), so nobody just gets the same task back.
 *     If it's HIGH priority, the new holder automatically gets the shorter dynamic HIGH timer,
 *     because assigned_at resets to now and todoer_effective_time_limit_minutes() always
 *     applies the HIGH constant for HIGH tasks regardless of holder.
 *   - SPECIFIC_USER task: there is no "different available user" it's allowed to go to (it's
 *     locked), so a missed locked task is marked 'expired' rather than silently unlocked.
 *   - ANY_USER task with nobody else available (e.g. a single-player game): also marked
 *     'expired' rather than looped back to the same person, to keep the missed/overdue signal
 *     meaningful.
 * Safe to call on every page load, same pattern as todoer_close_elapsed_periods().
 */
function todoer_process_expirations(PDO $pdo): void {
    $now = time();
    $stmt = $pdo->query("SELECT * FROM tasks WHERE status = 'open'");
    foreach ($stmt->fetchAll() as $task) {
        $deadline = todoer_task_deadline($task);
        if ($deadline === null || strtotime($deadline) > $now) {
            continue;
        }

        if ($task['assigned_type'] === 'SPECIFIC_USER') {
            todoer_mark_expired($pdo, $task, 'missed by locked user, no reassignment target');
            continue;
        }

        $activeUsers = todoer_active_users($pdo);
        $candidates = array_values(array_filter(
            $activeUsers,
            fn(array $u): bool => (int) $u['id'] !== (int) $task['user_id']
        ));
        if (!$candidates) {
            todoer_mark_expired($pdo, $task, 'timed out, no other active user available');
            continue;
        }

        $load = todoer_open_task_load($pdo, $task['list_type'], $task['period_key'], $candidates);
        asort($load);
        $newUserId = (int) array_key_first($load);
        todoer_assign_task_to_user($pdo, (int) $task['id'], $newUserId, (int) $task['user_id'], 'reassigned', 'timed out');
    }
}

function todoer_mark_expired(PDO $pdo, array $task, string $note): void {
    $stmt = $pdo->prepare("UPDATE tasks SET status = 'expired' WHERE id = ? AND status = 'open'");
    $stmt->execute([$task['id']]);
    todoer_log_task_event($pdo, (int) $task['id'], 'expired', $task['user_id'] !== null ? (int) $task['user_id'] : null, null, $note);
}

/**
 * Game completion, "ends early" case: if a period has been started and every task in it has
 * left the 'unassigned'/'open' states (all done or expired), close it immediately instead of
 * waiting for the period to elapse on the clock. No-op if the period was never started, has no
 * tasks at all, or is already closed.
 */
function todoer_maybe_finish_period_early(PDO $pdo, string $listType, ?string $periodKey = null): void {
    $periodKey = $periodKey ?? todoer_period_key($listType);

    $started = $pdo->prepare('SELECT 1 FROM game_starts WHERE list_type = ? AND period_key = ?');
    $started->execute([$listType, $periodKey]);
    if (!$started->fetchColumn()) {
        return;
    }

    $already = $pdo->prepare('SELECT 1 FROM periods_closed WHERE list_type = ? AND period_key = ?');
    $already->execute([$listType, $periodKey]);
    if ($already->fetchColumn()) {
        return;
    }

    $pending = $pdo->prepare(
        "SELECT COUNT(*) FROM tasks WHERE list_type = ? AND period_key = ? AND status IN ('unassigned', 'open')"
    );
    $pending->execute([$listType, $periodKey]);
    if ((int) $pending->fetchColumn() > 0) {
        return;
    }

    $total = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE list_type = ? AND period_key = ?');
    $total->execute([$listType, $periodKey]);
    if ((int) $total->fetchColumn() === 0) {
        return; // nothing was ever on the board -- don't crown a winner for an empty game
    }

    todoer_close_one_period($pdo, $listType, $periodKey);
}
