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
    $tasks = [];
    foreach (TODOER_LIST_TYPES as $listType) {
        $periodKey = todoer_period_key($listType);
        $stmt = $pdo->prepare(
            'SELECT id, title, points, status, completed_at, created_at
             FROM tasks WHERE user_id = ? AND list_type = ? AND period_key = ?
             ORDER BY status ASC, created_at ASC'
        );
        $stmt->execute([$user['id'], $listType, $periodKey]);
        $tasks[$listType] = [
            'period_key' => $periodKey,
            'label' => todoer_period_label($listType, $periodKey),
            'items' => $stmt->fetchAll(),
        ];
    }
    todoer_respond(['ok' => true, 'tasks' => $tasks]);
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
        $periodKey = todoer_period_key($listType);
        $points = TODOER_POINTS[$listType];
        $stmt = $pdo->prepare(
            'INSERT INTO tasks (user_id, list_type, period_key, title, points) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user['id'], $listType, $periodKey, $title, $points]);
        todoer_respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
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
        } else {
            $upd = $pdo->prepare("UPDATE tasks SET status = 'open', completed_at = NULL WHERE id = ?");
        }
        $upd->execute([$taskId]);
        todoer_respond(['ok' => true]);
    }

    if ($action === 'delete') {
        $taskId = (int) ($body['task_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$taskId, $user['id']]);
        todoer_respond(['ok' => true]);
    }

    todoer_fail('Unknown action.');
}

todoer_fail('Method not allowed.', 405);
