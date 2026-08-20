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

        // "My" view: whatever is currently assigned to me (never 'unassigned' -- those have no
        // user_id yet -- and never 'expired', which only applies to locked tasks nobody can
        // pick back up). Open tasks are ordered per the spec: window_start, then priority;
        // completed tasks stay appended in original done order underneath.
        $mine = $pdo->prepare("SELECT * FROM tasks WHERE group_id = ? AND user_id = ? AND list_type = ? AND period_key = ?
                                AND status IN ('open', 'done') ORDER BY created_at ASC");
        $mine->execute([$groupId, $user['id'], $listType, $periodKey]);
        $mineRows = $mine->fetchAll();
        $openMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'open'));
        $doneMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'done'));
        $items = array_merge(todoer_sort_tasks_for_view($openMine), $doneMine);

        $running = todoer_is_game_running($pdo, $groupId, $listType, $periodKey);

        $unassignedCount = $pdo->prepare(
            "SELECT COUNT(*) FROM tasks WHERE group_id = ? AND list_type = ? AND period_key = ? AND status = 'unassigned'"
        );
        $unassignedCount->execute([$groupId, $listType, $periodKey]);

        // Shared board: every task in this period *belonging to this group*, so locked/other
        // members'/expired tasks are still visible to the whole group even though they won't show
        // up in "My" list above -- and tasks from any other group never appear at all.
        $boardStmt = $pdo->prepare(
            "SELECT t.*, u.username AS holder_username, u.color AS holder_color
             FROM tasks t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.group_id = ? AND t.list_type = ? AND t.period_key = ?
             ORDER BY (t.status = 'done'), t.created_at ASC"
        );
        $boardStmt->execute([$groupId, $listType, $periodKey]);

        $tasks[$listType] = [
            'period_key' => $periodKey,
            'label' => todoer_period_label($listType, $periodKey),
            // While running: no adding/editing/deleting -- just this list, checked off as done.
            'running' => $running,
            'unassigned_count' => (int) $unassignedCount->fetchColumn(),
            'items' => $items,
            'board' => array_map(function (array $task) use ($user): array {
                $task['can_edit'] = (int) $task['user_id'] === (int) $user['id']
                    || (int) $task['created_by'] === (int) $user['id'];
                return $task;
            }, $boardStmt->fetchAll()),
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
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND group_id = ? AND user_id = ?');
        $stmt->execute([$taskId, $groupId, $user['id']]);
        $task = $stmt->fetch();
        if (!$task) {
            todoer_fail('Task not found.', 404);
        }
        if ($action === 'complete') {
            $upd = $pdo->prepare("UPDATE tasks SET status = 'done', completed_at = datetime('now') WHERE id = ?");
            $upd->execute([$taskId]);
            todoer_log_task_event($pdo, $taskId, 'completed', null, (int) $user['id']);
            // A completion can be exactly what finishes the period early -- check right away
            // instead of waiting for the next page load's bootstrap sweep.
            todoer_maybe_finish_period_early($pdo, $groupId, $task['list_type'], $task['period_key']);
        } else {
            $upd = $pdo->prepare("UPDATE tasks SET status = 'open', completed_at = NULL WHERE id = ?");
            $upd->execute([$taskId]);
            todoer_log_task_event($pdo, $taskId, 'reopened', null, (int) $user['id']);
        }
        todoer_respond(['ok' => true]);
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
