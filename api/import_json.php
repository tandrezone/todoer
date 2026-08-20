<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
$groupId = (int) $group['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todoer_fail('Method not allowed.', 405);
}

todoer_require_csrf();

// Accept either a direct upload (the export file, unmodified) or a JSON body -- same two shapes
// api/import.php already supports for the Keep flow.
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
if ($isMultipart) {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        todoer_fail('No file uploaded, or the upload failed.');
    }
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $data = $raw !== false ? json_decode($raw, true) : null;
} else {
    $data = todoer_json_body();
}

if (!is_array($data) || !isset($data['tasks']) || !is_array($data['tasks'])) {
    todoer_fail('That file does not look like a Todoer tasks export.');
}

// Usernames -> ids, scoped to the current group only -- a name from the exported file can never
// reach outside the group being imported into, even if the export came from a different group.
$memberIdsByUsername = [];
foreach (todoer_group_members($pdo, $groupId) as $member) {
    $memberIdsByUsername[$member['username']] = (int) $member['id'];
}

$insert = $pdo->prepare(
    "INSERT INTO tasks
        (group_id, user_id, created_by, list_type, period_key, title, points, status,
         window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes,
         assigned_at, created_at, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$created = 0;
$createdIds = [];
$skipped = 0;

$pdo->beginTransaction();
try {
    foreach ($data['tasks'] as $item) {
        if (!is_array($item)) {
            $skipped++;
            continue;
        }

        $listType = $item['list_type'] ?? '';
        $title = trim((string) ($item['title'] ?? ''));
        $status = $item['status'] ?? 'unassigned';
        $assignedType = $item['assigned_type'] ?? 'ANY_USER';
        $priority = $item['priority'] ?? 'MODERATE';

        if (!in_array($listType, TODOER_LIST_TYPES, true)
            || $title === ''
            || !in_array($status, ['unassigned', 'open', 'done', 'expired'], true)
            || !in_array($assignedType, ['ANY_USER', 'SPECIFIC_USER'], true)
            || !in_array($priority, ['HIGH', 'MODERATE', 'LOW'], true)
        ) {
            $skipped++;
            continue;
        }

        $periodKey = trim((string) ($item['period_key'] ?? '')) ?: todoer_period_key($listType);
        $title = mb_substr($title, 0, 200);
        $points = isset($item['points']) ? (int) $item['points'] : TODOER_POINTS[$listType];

        // Falls back to the person doing the import if the original creator isn't in this
        // group; the holder/designated-assignee simply go unset rather than guessing.
        $createdBy = $memberIdsByUsername[$item['created_by'] ?? ''] ?? (int) $user['id'];
        $holderId = $memberIdsByUsername[$item['user'] ?? ''] ?? null;
        $assignedUserId = $memberIdsByUsername[$item['assigned_user'] ?? ''] ?? null;

        $timeLimit = isset($item['time_limit_minutes']) && $item['time_limit_minutes'] !== null
            ? (int) $item['time_limit_minutes']
            : null;

        $insert->execute([
            $groupId,
            $holderId,
            $createdBy,
            $listType,
            $periodKey,
            $title,
            $points,
            $status,
            $item['window_start'] ?? null,
            $item['window_end'] ?? null,
            $assignedType,
            $assignedUserId,
            $priority,
            $timeLimit,
            $item['assigned_at'] ?? null,
            $item['created_at'] ?? gmdate('Y-m-d H:i:s'),
            $item['completed_at'] ?? null,
        ]);
        $createdIds[] = (int) $pdo->lastInsertId();
        $created++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// Same backstop as api/import.php: pick up any period whose game is already running rather than
// stranding freshly-imported 'unassigned' tasks until the next manual Start.
foreach ($createdIds as $taskId) {
    todoer_maybe_assign_new_task($pdo, $taskId);
}

todoer_respond(['ok' => true, 'created' => $created, 'skipped' => $skipped]);
