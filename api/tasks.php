<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $allUsers = $pdo->query('SELECT id, username, color, active FROM users ORDER BY username')->fetchAll();

    $tasks = [];
    foreach (TODOER_LIST_TYPES as $listType) {
        $periodKey = todoer_period_key($listType);

        // "My" view: whatever is currently assigned to me (never 'unassigned' -- those have no
        // user_id yet -- and never 'expired', which only applies to locked tasks nobody can
        // pick back up). Open tasks are ordered per the spec: window_start, then priority;
        // completed tasks stay appended in original done order underneath.
        $mine = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND list_type = ? AND period_key = ?
                                AND status IN ('open', 'done') ORDER BY created_at ASC");
        $mine->execute([$user['id'], $listType, $periodKey]);
        $mineRows = $mine->fetchAll();
        $openMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'open'));
        $doneMine = array_values(array_filter($mineRows, fn($t) => $t['status'] === 'done'));
        $items = array_merge(todoer_sort_tasks_for_view($openMine), $doneMine);

        $running = todoer_is_game_running($pdo, $listType, $periodKey);

        $unassignedCount = $pdo->prepare(
            "SELECT COUNT(*) FROM tasks WHERE list_type = ? AND period_key = ? AND status = 'unassigned'"
        );
        $unassignedCount->execute([$listType, $periodKey]);

        // Shared board: every task in this period, so locked/other-people's/expired tasks are
        // still visible to the whole group even though they won't show up in "My" list above.
        $boardStmt = $pdo->prepare(
            "SELECT t.*, u.username AS holder_username, u.color AS holder_color
             FROM tasks t LEFT JOIN users u ON u.id = t.user_id
             WHERE t.list_type = ? AND t.period_key = ?
             ORDER BY (t.status = 'done'), t.created_at ASC"
        );
        $boardStmt->execute([$listType, $periodKey]);

        $tasks[$listType] = [
            'period_key' => $periodKey,
            'label' => todoer_period_label($listType, $periodKey),
            // While running: no adding/editing/deleting -- just this list, checked off as done.
            'running' => $running,
            'unassigned_count' => (int) $unassignedCount->fetchColumn(),
            'items' => $items,
            'board' => $boardStmt->fetchAll(),
        ];
    }

    todoer_respond([
        'ok' => true,
        'tasks' => $tasks,
        'users' => $allUsers,
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
        if (todoer_is_game_running($pdo, $listType, todoer_period_key($listType))) {
            todoer_fail('This list is running -- stop it before adding tasks.');
        }

        $assignedType = ($body['assigned_type'] ?? 'ANY_USER') === 'SPECIFIC_USER' ? 'SPECIFIC_USER' : 'ANY_USER';
        $assignedUserId = null;
        if ($assignedType === 'SPECIFIC_USER') {
            $assignedUserId = (int) ($body['assigned_user_id'] ?? 0);
            $check = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            $check->execute([$assignedUserId]);
            if (!$check->fetchColumn()) {
                todoer_fail('Choose a valid person to lock this task to.');
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
                (user_id, created_by, list_type, period_key, title, points, status,
                 window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes)
             VALUES (NULL, ?, ?, ?, ?, ?, 'unassigned', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user['id'], $listType, $periodKey, $title, $points,
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
        $result = todoer_start_game($pdo, $listType);
        todoer_respond(array_merge(['ok' => true], $result));
    }

    if ($action === 'stop') {
        $listType = $body['list_type'] ?? '';
        if (!in_array($listType, TODOER_LIST_TYPES, true)) {
            todoer_fail('Invalid list type.');
        }
        $result = todoer_stop_game($pdo, $listType);
        todoer_respond(array_merge(['ok' => true], $result));
    }

    if ($action === 'complete' || $action === 'reopen') {
        $taskId = (int) ($body['task_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$taskId, $user['id']]);
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
            todoer_maybe_finish_period_early($pdo, $task['list_type'], $task['period_key']);
        } else {
            $upd = $pdo->prepare("UPDATE tasks SET status = 'open', completed_at = NULL WHERE id = ?");
            $upd->execute([$taskId]);
            todoer_log_task_event($pdo, $taskId, 'reopened', null, (int) $user['id']);
        }
        todoer_respond(['ok' => true]);
    }

    if ($action === 'delete') {
        $taskId = (int) ($body['task_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT list_type, period_key FROM tasks WHERE id = ? AND (user_id = ? OR created_by = ?)');
        $stmt->execute([$taskId, $user['id'], $user['id']]);
        $task = $stmt->fetch();
        if ($task && todoer_is_game_running($pdo, $task['list_type'], $task['period_key'])) {
            todoer_fail('This list is running -- stop it before deleting tasks.');
        }
        $del = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND (user_id = ? OR created_by = ?)');
        $del->execute([$taskId, $user['id'], $user['id']]);
        todoer_respond(['ok' => true]);
    }

    todoer_fail('Unknown action.');
}

todoer_fail('Method not allowed.', 405);
