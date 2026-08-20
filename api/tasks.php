<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$method = $_SERVER['REQUEST_METHOD'];

// Every read and write below is scoped to this one group. It is resolved once, from the session
// user's membership -- never from anything the client sends -- so there is no request shape that
// can point this endpoint at another group's tasks.
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
$groupId = (int) $group['id'];

if ($method === 'GET') {
    // Only fellow group members are offered as assignees, and only they appear on the board.
    $allUsers = todoer_group_members($pdo, $groupId);

    $tasks = [];
    foreach (TODOER_LIST_TYPES as $listType) {
        $periodKey = todoer_period_key($listType);

        $running = todoer_is_game_running($pdo, $groupId, $listType, $periodKey);

        // Everything in this period, with its current holder -- one query feeding both the
        // in-game list and the (stopped-mode) team board below.
        $allStmt = $pdo->prepare(
            "SELECT t.*, u.username AS holder_username, u.color AS holder_color
             FROM tasks t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.group_id = ? AND t.list_type = ? AND t.period_key = ?
             ORDER BY t.created_at ASC"
        );
        $allStmt->execute([$groupId, $listType, $periodKey]);
        $allRows = array_map(
            fn(array $task): array => todoer_annotate_task_for_user($task, (int) $user['id'], $running),
            $allStmt->fetchAll()
        );

        if ($running) {
            // Game mode: the whole board is the playing field, because an open task the holder
            // hasn't ticked yet is up for grabs (see todoer_task_claim_state()). Mine come first
            // so "what am I meant to be doing" is still the top of the list, then everyone
            // else's live tasks, then whatever is already settled.
            $mineOpen = array_values(array_filter($allRows, fn($t) => $t['is_mine'] && $t['status'] === 'open'));
            $othersLive = array_values(array_filter(
                $allRows,
                fn($t) => !$t['is_mine'] && in_array($t['status'], ['open', 'unassigned'], true)
            ));
            $settled = array_values(array_filter($allRows, fn($t) => in_array($t['status'], ['done', 'expired'], true)));
            usort($settled, fn($a, $b) => strcmp((string) $a['completed_at'], (string) $b['completed_at']));
            $items = array_merge(
                todoer_sort_tasks_for_view($mineOpen),
                todoer_sort_tasks_for_view($othersLive),
                $settled
            );
        } else {
            // Stopped: the list is just your own plate, and the full picture lives in the
            // "Team board" panel that game mode hides. Open tasks are ordered per the spec:
            // window_start, then priority; completed tasks stay appended underneath.
            $mineRows = array_values(array_filter(
                $allRows,
                fn($t) => $t['is_mine'] && in_array($t['status'], ['open', 'done'], true)
            ));
            $openMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'open'));
            $doneMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'done'));
            $items = array_merge(todoer_sort_tasks_for_view($openMine), $doneMine);
        }

        $unassignedCount = $pdo->prepare(
            "SELECT COUNT(*) FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = 'unassigned'"
        );
        $unassignedCount->execute([$groupId, $listType, $periodKey]);

        // Shared board (shown while stopped): every task in this period *belonging to this
        // group*, so locked/other members'/expired tasks are still visible to the whole group
        // even though they won't show up in the personal list above -- and tasks from any other
        // group never appear at all.
        $board = $allRows;
        usort($board, function (array $a, array $b): int {
            $aDone = $a['status'] === 'done' ? 1 : 0;
            $bDone = $b['status'] === 'done' ? 1 : 0;
            return $aDone === $bDone ? strcmp((string) $a['created_at'], (string) $b['created_at']) : $aDone <=> $bDone;
        });

        $tasks[$listType] = [
            'period_key' => $periodKey,
            'label' => todoer_period_label($listType, $periodKey),
            // While running: no adding/editing/deleting -- just the shared list, ticked off as
            // tasks get done, whoever they were handed to.
            'running' => $running,
            'unassigned_count' => (int) $unassignedCount->fetchColumn(),
            'items' => $items,
            'board' => $board,
        ];
    }

    todoer_respond([
        'ok' => true,
        'tasks' => $tasks,
        'users' => $allUsers,
        'group' => [
            'id' => $groupId,
            'name' => $group['name'],
            'role' => $group['role'],
            'member_count' => count($allUsers),
        ],
        'notifications' => todoer_user_notifications($pdo, (int) $user['id']),
    ]);
}

if ($method === 'POST') {
    todoer_require_csrf();
    $body = todoer_json_body();
    $action = $body['action'] ?? '';

    if ($action === 'notifications_read') {
        todoer_mark_notifications_read($pdo, (int) $user['id'], $body['ids'] ?? []);
        todoer_respond(['ok' => true]);
    }

    if ($action === 'add') {
        $listType = $body['list_type'] ?? '';
        $title = trim($body['title'] ?? '');
        if (!in_array($listType, TODOER_LIST_TYPES, true)) {
            todoer_fail('Invalid list type.');
        }
        if ($title === '') {
            todoer_fail('Task title cannot be empty.');
        }
        if (todoer_is_game_running($pdo, $groupId, $listType, todoer_period_key($listType))) {
            todoer_fail('This list is running -- stop it before adding tasks.');
        }

        $assignedType = ($body['assigned_type'] ?? 'ANY_USER') === 'SPECIFIC_USER' ? 'SPECIFIC_USER' : 'ANY_USER';
        $assignedUserId = null;
        if ($assignedType === 'SPECIFIC_USER') {
            // Must be a member of *this* group -- otherwise a hand-crafted request could park a
            // task on an outsider, who would then see it in their own list.
            $assignedUserId = (int) ($body['assigned_user_id'] ?? 0);
            if (!todoer_is_group_member($pdo, $groupId, $assignedUserId)) {
                todoer_fail('Choose someone from your group to lock this task to.');
            }
        }

        $priority = in_array($body['priority'] ?? '', TODOER_PRIORITIES, true) ? $body['priority'] : 'MODERATE';

        // HIGH tasks always use the dynamic HIGH-priority timer, so any submitted per-task
        // limit is ignored for them rather than stored and silently overridden later.
        $timeLimitMinutes = null;
        if ($priority !== 'HIGH' && isset($body['time_limit_minutes']) && $body['time_limit_minutes'] !== '') {
            $timeLimitMinutes = max(1, (int) $body['time_limit_minutes']);
        }

        $periodKey = todoer_period_key($listType);
        $points = TODOER_POINTS[$listType];

        // Window fields are captured in whatever shorthand fits the list's natural cadence
        // (a time-of-day for a daily task, a weekday for a weekly one, a day-of-month for a
        // monthly one) and combined with the period here into an ordinary datetime -- see
        // todoer_period_window_datetime() for why.
        if ($listType === 'daily') {
            $windowStart = todoer_period_window_datetime('daily', $periodKey, $body['window_start_time'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('daily', $periodKey, $body['window_end_time'] ?? null, true);
        } elseif ($listType === 'weekly') {
            $windowStart = todoer_period_window_datetime('weekly', $periodKey, $body['window_start_day'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('weekly', $periodKey, $body['window_end_day'] ?? null, true);
        } else {
            $windowStart = todoer_period_window_datetime('monthly', $periodKey, $body['window_start_dom'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('monthly', $periodKey, $body['window_end_dom'] ?? null, true);
        }
        if ($windowStart !== null && $windowEnd !== null && $windowStart > $windowEnd) {
            todoer_fail('Window start must be before window end.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO tasks
                (group_id, user_id, created_by, list_type, period_key, title, points, status,
                 window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes)
             VALUES (?, NULL, ?, ?, ?, ?, ?, 'unassigned', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $groupId, $user['id'], $listType, $periodKey, $title, $points,
            $windowStart, $windowEnd, $assignedType, $assignedUserId, $priority, $timeLimitMinutes,
        ]);
        $taskId = (int) $pdo->lastInsertId();

        // If this period's game is already under way, don't strand the new task as
        // 'unassigned' until the next manual Start -- assign it right away.
        todoer_maybe_assign_new_task($pdo, $taskId);

        todoer_respond(['ok' => true, 'id' => $taskId]);
    }

    if ($action === 'start') {
        $listType = $body['list_type'] ?? '';
        if (!in_array($listType, TODOER_LIST_TYPES, true)) {
            todoer_fail('Invalid list type.');
        }
        $result = todoer_start_game($pdo, $groupId, $listType);
        todoer_respond(array_merge(['ok' => true], $result));
    }

    if ($action === 'stop') {
        $listType = $body['list_type'] ?? '';
        if (!in_array($listType, TODOER_LIST_TYPES, true)) {
            todoer_fail('Invalid list type.');
        }
        $result = todoer_stop_game($pdo, $groupId, $listType);
        todoer_respond(array_merge(['ok' => true], $result));
    }

    if ($action === 'complete' || $action === 'reopen') {
        $taskId = (int) ($body['task_id'] ?? 0);
        // Scoped to the group, but *not* to the caller as holder: in game mode you can also do a
        // task that's currently someone else's, as long as it's still up for grabs. Whether this
        // particular task qualifies is decided below, never by the client.
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND group_id = ?');
        $stmt->execute([$taskId, $groupId]);
        $task = $stmt->fetch();
        if (!$task) {
            todoer_fail('Task not found.', 404);
        }
        $isMine = (int) $task['user_id'] === (int) $user['id'];

        if ($action === 'complete') {
            $claimedFrom = null;
            if (!$isMine) {
                if (!todoer_is_game_running($pdo, $groupId, $task['list_type'], $task['period_key'])) {
                    todoer_fail(todoer_claim_error_message('not_running'), 403);
                }
                [$claimable, $reason] = todoer_task_claim_state($task, (int) $user['id']);
                if (!$claimable) {
                    todoer_fail(todoer_claim_error_message($reason), 403);
                }
                // Taking it over and finishing it are one atomic step: there's no "claimed but
                // not done" state to sit on, so nobody can hoard other people's tasks.
                $claimedFrom = (int) $task['user_id'];
                $take = $pdo->prepare(
                    "UPDATE tasks SET user_id = ? WHERE id = ? AND user_id = ? AND status = 'open'"
                );
                $take->execute([$user['id'], $taskId, $claimedFrom]);
                if ($take->rowCount() === 0) {
                    // Someone else got there in the milliseconds since we read the row.
                    todoer_fail('Too slow -- somebody else just took that one.', 409);
                }
                todoer_log_task_event($pdo, $taskId, 'reassigned', $claimedFrom, (int) $user['id'], 'claimed by another player');
            }

            $upd = $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = datetime('now') WHERE id = ?");
            $upd->execute([$taskId]);
            todoer_log_task_event($pdo, $taskId, 'completed', null, (int) $user['id']);

            // Tell the person it was taken from -- losing points to someone else is the whole
            // tension of the game, so it shouldn't happen silently.
            if ($claimedFrom !== null) {
                todoer_notify_task_claimed($pdo, $claimedFrom, (int) $user['id'], $user['username'], $task);
            }

            // A completion can be exactly what finishes the period early -- check right away
            // instead of waiting for the next page load's bootstrap sweep.
            todoer_maybe_finish_period_early($pdo, $groupId, $task['list_type'], $task['period_key']);
            todoer_respond(['ok' => true, 'claimed_from' => $claimedFrom]);
        }

        // Un-ticking is only ever something you can do to a task you hold.
        if (!$isMine) {
            todoer_fail("That task isn't yours to un-tick.", 403);
        }
        // If you'd taken this one off someone else, un-ticking gives it back rather than leaving
        // you sitting on their task.
        $returnedTo = todoer_undo_claim($pdo, $taskId, (int) $user['id']);
        if ($returnedTo === null) {
            $upd = $pdo->prepare("UPDATE tasks SET status = 'open', completed_at = NULL WHERE id = ?");
            $upd->execute([$taskId]);
            todoer_log_task_event($pdo, $taskId, 'reopened', null, (int) $user['id']);
        }
        todoer_respond(['ok' => true, 'returned_to' => $returnedTo]);
    }

    if ($action === 'delete') {
        $taskId = (int) ($body['task_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT list_type, period_key FROM tasks WHERE id = ? AND group_id = ? AND (user_id = ? OR created_by = ?)');
        $stmt->execute([$taskId, $groupId, $user['id'], $user['id']]);
        $task = $stmt->fetch();
        if ($task && todoer_is_game_running($pdo, $groupId, $task['list_type'], $task['period_key'])) {
            todoer_fail('This list is running -- stop it before deleting tasks.');
        }
        $del = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND group_id = ? AND (user_id = ? OR created_by = ?)');
        $del->execute([$taskId, $groupId, $user['id'], $user['id']]);
        todoer_respond(['ok' => true]);
    }

    if ($action === 'edit') {
        $taskId = (int) ($body['task_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND group_id = ? AND (user_id = ? OR created_by = ?)');
        $stmt->execute([$taskId, $groupId, $user['id'], $user['id']]);
        $task = $stmt->fetch();
        if (!$task) {
            todoer_fail('Task not found.', 404);
        }
        if (todoer_is_game_running($pdo, $groupId, $task['list_type'], $task['period_key'])) {
            todoer_fail('This list is running -- stop it before editing tasks.');
        }

        $title = trim($body['title'] ?? '');
        if ($title === '') {
            todoer_fail('Task title cannot be empty.');
        }

        $assignedType = ($body['assigned_type'] ?? 'ANY_USER') === 'SPECIFIC_USER' ? 'SPECIFIC_USER' : 'ANY_USER';
        $assignedUserId = null;
        if ($assignedType === 'SPECIFIC_USER') {
            $assignedUserId = (int) ($body['assigned_user_id'] ?? 0);
            if (!todoer_is_group_member($pdo, $groupId, $assignedUserId)) {
                todoer_fail('Choose someone from your group to lock this task to.');
            }
        }

        $priority = in_array($body['priority'] ?? '', TODOER_PRIORITIES, true) ? $body['priority'] : 'MODERATE';
        $timeLimitMinutes = null;
        if ($priority !== 'HIGH' && isset($body['time_limit_minutes']) && $body['time_limit_minutes'] !== '') {
            $timeLimitMinutes = max(1, (int) $body['time_limit_minutes']);
        }

        $periodKey = $task['period_key'];
        if ($task['list_type'] === 'daily') {
            $windowStart = todoer_period_window_datetime('daily', $periodKey, $body['window_start_time'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('daily', $periodKey, $body['window_end_time'] ?? null, true);
        } elseif ($task['list_type'] === 'weekly') {
            $windowStart = todoer_period_window_datetime('weekly', $periodKey, $body['window_start_day'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('weekly', $periodKey, $body['window_end_day'] ?? null, true);
        } else {
            $windowStart = todoer_period_window_datetime('monthly', $periodKey, $body['window_start_dom'] ?? null, false);
            $windowEnd = todoer_period_window_datetime('monthly', $periodKey, $body['window_end_dom'] ?? null, true);
        }
        if ($windowStart !== null && $windowEnd !== null && $windowStart > $windowEnd) {
            todoer_fail('Window start must be before window end.');
        }

        $assignmentChanged = $assignedType !== $task['assigned_type']
            || (int) $assignedUserId !== (int) $task['assigned_user_id'];
        if ($assignmentChanged) {
            $update = $pdo->prepare(
                "UPDATE tasks SET title = ?, assigned_type = ?, assigned_user_id = ?, priority = ?,
                 time_limit_minutes = ?, window_start = ?, window_end = ?, user_id = NULL,
                 status = 'unassigned', assigned_at = NULL, completed_at = NULL WHERE id = ?"
            );
            $update->execute([$title, $assignedType, $assignedUserId, $priority, $timeLimitMinutes, $windowStart, $windowEnd, $taskId]);
        } else {
            $update = $pdo->prepare(
                'UPDATE tasks SET title = ?, priority = ?, time_limit_minutes = ?, window_start = ?, window_end = ? WHERE id = ?'
            );
            $update->execute([$title, $priority, $timeLimitMinutes, $windowStart, $windowEnd, $taskId]);
        }
        todoer_respond(['ok' => true]);
    }

    todoer_fail('Unknown action.');
}

todoer_fail('Method not allowed.', 405);
