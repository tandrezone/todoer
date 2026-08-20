<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$method = $_SERVER['REQUEST_METHOD'];

/** Normalizes a <input type=datetime-local> value ("YYYY-MM-DDTHH:MM") to "YYYY-MM-DD HH:MM:00". */
function todoer_normalize_datetime_input(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime(str_replace('T', ' ', $value));
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

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

        $started = $pdo->prepare('SELECT 1 FROM game_starts WHERE list_type = ? AND period_key = ?');
        $started->execute([$listType, $periodKey]);

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
            'started' => (bool) $started->fetchColumn(),
            'unassigned_count' => (int) $unassignedCount->fetchColumn(),
            'items' => $items,
            'board' => $boardStmt->fetchAll(),
        ];
    }

    todoer_respond(['ok' => true, 'tasks' => $tasks, 'users' => $allUsers]);
}

if ($method === 'POST') {
    $body = todoer_json_body();
    $action = $body['action'] ?? '';

    if ($action === 'add') {
        $listType = $body['list_type'] ?? '';
        $title = trim($body['title'] ?? '');
        if (!in_array($listType, TODOER_LIST_TYPES, true)) {
            todoer_fail('Invalid list type.');
        }
        if ($title === '') {
            todoer_fail('Task title cannot be empty.');
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

        $windowStart = todoer_normalize_datetime_input($body['window_start'] ?? null);
        $windowEnd = todoer_normalize_datetime_input($body['window_end'] ?? null);
        if ($windowStart !== null && $windowEnd !== null && $windowStart > $windowEnd) {
            todoer_fail('Window start must be before window end.');
        }

        $periodKey = todoer_period_key($listType);
        $points = TODOER_POINTS[$listType];

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
        $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND (user_id = ? OR created_by = ?)');
        $stmt->execute([$taskId, $user['id'], $user['id']]);
        todoer_respond(['ok' => true]);
    }

    todoer_fail('Unknown action.');
}

todoer_fail('Method not allowed.', 405);
