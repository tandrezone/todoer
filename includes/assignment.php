<?php
// Task assignment & workflow engine: distribution on "Start", view ordering, and the
// execution/expiration/reassignment lifecycle described in the game's task-assignment spec.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period.php';
require_once __DIR__ . '/groups.php';

const TODOER_PRIORITIES = ['HIGH', 'MODERATE', 'LOW'];

// HIGH priority tasks always get this shorter completion timer once assigned, regardless of
// whatever time_limit_minutes was set on the task -- see todoer_effective_time_limit_minutes().
// "Dynamic" here means it's measured from the moment of (re)assignment (assigned_at), not a
// fixed wall-clock time -- change this single constant to retune it app-wide.
const TODOER_HIGH_PRIORITY_TIME_LIMIT_MINUTES = 30;

const TODOER_PRIORITY_RANK = ['HIGH' => 0, 'MODERATE' => 1, 'LOW' => 2];

/**
 * Active (currently playing) members of one group, in a stable order used for tie-breaking.
 * Distribution and reassignment only ever consider this list, so a task can never land on
 * somebody outside the group it belongs to.
 */
function todoer_active_users(PDO $pdo, int $groupId): array {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.color FROM group_members gm JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ? AND u.active = 1 ORDER BY u.username'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
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

/** Current open-task load per active member for a group's period, to keep distribution balanced. */
function todoer_open_task_load(PDO $pdo, int $groupId, string $listType, string $periodKey, array $activeUsers): array {
    $load = array_fill_keys(array_column($activeUsers, 'id'), 0);
    $stmt = $pdo->prepare(
        "SELECT user_id, COUNT(*) AS c FROM tasks
         WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = 'open' AND user_id IS NOT NULL
         GROUP BY user_id"
    );
    $stmt->execute([$groupId, $listType, $periodKey]);
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
function todoer_start_game(PDO $pdo, int $groupId, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $activeUsers = todoer_active_users($pdo, $groupId);
    if (!$activeUsers) {
        return ['started' => false, 'reason' => 'No active players in this group to assign tasks to.'];
    }

    $pdo->beginTransaction();
    try {
        // 1. Locked tasks first.
        $stmt = $pdo->prepare(
            "SELECT * FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ?
             AND assigned_type = 'SPECIFIC_USER' AND status = 'unassigned'"
        );
        $stmt->execute([$groupId, $listType, $periodKey]);
        $locked = $stmt->fetchAll();
        foreach ($locked as $task) {
            if (empty($task['assigned_user_id'])) {
                continue; // malformed row (locked with nobody designated) -- leave for manual fixup
            }
            todoer_assign_task_to_user($pdo, (int) $task['id'], (int) $task['assigned_user_id'], null, 'assigned', 'locked to designated user on start');
        }

        // 2. Open (ANY_USER) tasks, balanced across active users.
        $stmt = $pdo->prepare(
            "SELECT * FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ?
             AND assigned_type = 'ANY_USER' AND status = 'unassigned'"
        );
        $stmt->execute([$groupId, $listType, $periodKey]);
        $open = todoer_sort_tasks_for_view($stmt->fetchAll());

        $load = todoer_open_task_load($pdo, $groupId, $listType, $periodKey, $activeUsers);
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
            'INSERT INTO game_starts (group_id, list_type, period_key, running) VALUES (?, ?, ?, 1)
             ON CONFLICT(group_id, list_type, period_key) DO UPDATE SET running = 1'
        );
        $mark->execute([$groupId, $listType, $periodKey]);

        todoer_notify_group(
            $pdo,
            $groupId,
            'game-started:' . $groupId . ':' . $listType . ':' . $periodKey,
            ucfirst($listType) . ' game started',
            'Tasks have been assigned. Good luck!'
        );

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
function todoer_stop_game(PDO $pdo, int $groupId, string $listType, ?string $periodKey = null): array {
    $periodKey = $periodKey ?? todoer_period_key($listType);
    $stmt = $pdo->prepare(
        "UPDATE game_starts SET running = 0 WHERE group_id = ? AND list_type = ? AND period_key = ?"
    );
    $stmt->execute([$groupId, $listType, $periodKey]);
    if ($stmt->rowCount() === 0) {
        return ['stopped' => false, 'reason' => 'This list has not been started yet.'];
    }
    return ['stopped' => true, 'running' => false];
}

/** Whether a period's game is currently in the "running" (started, not stopped) state. */
function todoer_is_game_running(PDO $pdo, int $groupId, string $listType, string $periodKey): bool {
    $stmt = $pdo->prepare('SELECT running FROM game_starts WHERE group_id = ? AND list_type = ? AND period_key = ?');
    $stmt->execute([$groupId, $listType, $periodKey]);
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

    $groupId = (int) $task['group_id'];
    if (!todoer_is_game_running($pdo, $groupId, $task['list_type'], $task['period_key'])) {
        return; // wait for the next explicit Start
    }

    if ($task['assigned_type'] === 'SPECIFIC_USER') {
        // Only if the designated person is still in this group -- if they were removed, the task
        // stays unassigned for the group's admin to re-point rather than following them out.
        if (!empty($task['assigned_user_id']) && todoer_is_group_member($pdo, $groupId, (int) $task['assigned_user_id'])) {
            todoer_assign_task_to_user($pdo, $taskId, (int) $task['assigned_user_id'], null, 'assigned', 'locked to designated user');
        }
        return;
    }

    $activeUsers = todoer_active_users($pdo, $groupId);
    if (!$activeUsers) {
        return;
    }
    $load = todoer_open_task_load($pdo, $groupId, $task['list_type'], $task['period_key'], $activeUsers);
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

        // Reassignment candidates come from the task's own group only: a missed task moves
        // sideways within the group or expires, it never escapes into another group.
        $groupId = (int) $task['group_id'];
        $activeUsers = todoer_active_users($pdo, $groupId);
        $candidates = array_values(array_filter(
            $activeUsers,
            fn(array $u): bool => (int) $u['id'] !== (int) $task['user_id']
        ));
        if (!$candidates) {
            todoer_mark_expired($pdo, $task, 'timed out, no other active player in this group');
            continue;
        }

        $load = todoer_open_task_load($pdo, $groupId, $task['list_type'], $task['period_key'], $candidates);
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

/** Notify the current holder once, when 90% of the task's effective time window has elapsed. */
function todoer_process_deadline_notifications(PDO $pdo): void {
    $stmt = $pdo->query(
        "SELECT * FROM tasks
         WHERE status = 'open' AND user_id IS NOT NULL AND (window_end IS NOT NULL OR assigned_at IS NOT NULL)"
    );
    $now = time();
    foreach ($stmt->fetchAll() as $task) {
        $start = !empty($task['window_start'])
            ? strtotime($task['window_start'])
            : strtotime($task['assigned_at']);
        $deadline = todoer_task_deadline($task);
        $end = $deadline !== null ? strtotime($deadline) : false;
        if ($start === false || $end === false || $end <= $start) {
            continue;
        }
        $warningAt = $start + (($end - $start) * 0.9);
        if ($now < $warningAt || $now >= $end) {
            continue;
        }
        todoer_notify_user(
            $pdo,
            (int) $task['user_id'],
            'task-window-warning:' . $task['id'] . ':' . $task['assigned_at'] . ':' . $deadline,
            'Task deadline approaching',
            'Only 10% of the time window remains for "' . $task['title'] . '".'
        );
    }
}

/**
 * Game completion, "ends early" case: if a period has been started and every task in it has
 * left the 'unassigned'/'open' states (all done or expired), close it immediately instead of
 * waiting for the period to elapse on the clock. No-op if the period was never started, has no
 * tasks at all, or is already closed.
 */
function todoer_maybe_finish_period_early(PDO $pdo, int $groupId, string $listType, ?string $periodKey = null): void {
    $periodKey = $periodKey ?? todoer_period_key($listType);

    $started = $pdo->prepare('SELECT 1 FROM game_starts WHERE group_id = ? AND list_type = ? AND period_key = ?');
    $started->execute([$groupId, $listType, $periodKey]);
    if (!$started->fetchColumn()) {
        return;
    }

    $already = $pdo->prepare('SELECT 1 FROM periods_closed WHERE group_id = ? AND list_type = ? AND period_key = ?');
    $already->execute([$groupId, $listType, $periodKey]);
    if ($already->fetchColumn()) {
        return;
    }

    $pending = $pdo->prepare(
        "SELECT COUNT(*) FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ? AND status IN ('unassigned', 'open')"
    );
    $pending->execute([$groupId, $listType, $periodKey]);
    if ((int) $pending->fetchColumn() > 0) {
        return;
    }

    $total = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ?');
    $total->execute([$groupId, $listType, $periodKey]);
    if ((int) $total->fetchColumn() === 0) {
        return; // nothing was ever on the board -- don't crown a winner for an empty game
    }

    todoer_close_one_period($pdo, $groupId, $listType, $periodKey);
}

/**
 * Whether $userId is allowed to do (and take credit for) a task that is currently somebody
 * else's. This is the "steal" rule of game mode: an open task in the shared pool is up for
 * grabs while its window is live, so if the person holding it hasn't checked it off, anyone in
 * the group can do it instead and bank the points.
 *
 * Returns [claimable, reason]. `reason` is a short machine code the UI turns into a tooltip:
 *   own            -- already yours, nothing to claim
 *   not_open       -- done, expired, or still unassigned; only live tasks can be taken
 *   locked         -- assigned to a specific person on purpose, so it is theirs alone
 *   not_open_yet   -- its window hasn't started (Time1 is in the future)
 *   window_closed  -- its deadline has passed; the sweep will expire or reassign it
 */
function todoer_task_claim_state(array $task, int $userId, ?int $now = null): array {
    $now = $now ?? time();

    if ((int) $task['user_id'] === $userId) {
        return [false, 'own'];
    }
    if ($task['status'] !== 'open' || $task['user_id'] === null) {
        return [false, 'not_open'];
    }
    // A SPECIFIC_USER task was deliberately pinned to one person -- taking it would defeat the
    // point of locking it, so these are never claimable no matter how overdue they get.
    if ($task['assigned_type'] === 'SPECIFIC_USER') {
        return [false, 'locked'];
    }
    if (!empty($task['window_start']) && strtotime($task['window_start']) > $now) {
        return [false, 'not_open_yet'];
    }
    $deadline = todoer_task_deadline($task);
    if ($deadline !== null && strtotime($deadline) <= $now) {
        return [false, 'window_closed'];
    }
    return [true, ''];
}

/**
 * Adds the per-viewer flags the dashboard needs on top of a raw task row: whose it is, whether
 * this viewer may tick it off, and -- when they may not -- why. Computed server-side so the
 * checkbox the client renders and the rule the API enforces can't drift apart.
 */
function todoer_annotate_task_for_user(array $task, int $userId, bool $gameRunning = true): array {
    $isMine = (int) $task['user_id'] === $userId;
    // Taking over someone else's task is a game-mode move. While a list is stopped it's being
    // planned and reshuffled, not played, so nothing is up for grabs.
    [$claimable, $reason] = $gameRunning
        ? todoer_task_claim_state($task, $userId)
        : [false, $isMine ? 'own' : 'not_running'];

    $task['is_mine'] = $isMine;
    $task['claimable'] = $claimable;
    $task['claim_reason'] = $reason;
    // Mine (open or already done -- so it can be un-ticked), or someone else's and up for grabs.
    $task['can_complete'] = ($isMine && in_array($task['status'], ['open', 'done'], true)) || $claimable;
    $task['can_edit'] = $isMine || (int) $task['created_by'] === $userId;
    return $task;
}

/**
 * Hands a claimed task back to whoever it was taken from, when the claimer un-ticks it.
 *
 * There's no "previous holder" column: the append-only task_history *is* the record, so the
 * original holder is read back from the most recent 'claimed' event on this task. Returns the
 * user id the task was returned to, or null if this task was never claimed (in which case the
 * caller just reopens it normally and it stays put).
 */
function todoer_undo_claim(PDO $pdo, int $taskId, int $claimerId): ?int {
    $stmt = $pdo->prepare(
        "SELECT from_user_id, to_user_id FROM task_history
         WHERE task_id = ? AND event = 'reassigned' AND note = 'claimed by another player'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$taskId]);
    $claim = $stmt->fetch();
    if (!$claim || (int) $claim['to_user_id'] !== $claimerId || $claim['from_user_id'] === null) {
        return null;
    }

    $originalHolder = (int) $claim['from_user_id'];
    // Only give it back if they're still in the group and playing; otherwise leave it with the
    // claimer rather than parking it on someone who can't act on it.
    $check = $pdo->prepare(
        'SELECT 1 FROM group_members gm JOIN users u ON u.id = gm.user_id
         WHERE gm.user_id = ? AND gm.group_id = (SELECT group_id FROM tasks WHERE id = ?) AND u.active = 1'
    );
    $check->execute([$originalHolder, $taskId]);
    if (!$check->fetchColumn()) {
        return null;
    }

    $upd = $pdo->prepare("UPDATE tasks SET user_id = ?, status = 'open', completed_at = NULL WHERE id = ?");
    $upd->execute([$originalHolder, $taskId]);
    todoer_log_task_event($pdo, $taskId, 'reassigned', $claimerId, $originalHolder, 'claim undone');
    return $originalHolder;
}

/** Turns a todoer_task_claim_state() reason code into something worth reading. */
function todoer_claim_error_message(string $reason): string {
    switch ($reason) {
        case 'locked':
            return 'That task is locked to one person, so only they can do it.';
        case 'not_open_yet':
            return "That task's time window hasn't opened yet.";
        case 'window_closed':
            return "That task's time window has closed.";
        case 'not_open':
            return 'That task is already settled.';
        case 'not_running':
            return "That list isn't running -- only the person it's assigned to can tick it off.";
        default:
            return "You can't do that task right now.";
    }
}
