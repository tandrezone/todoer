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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    todoer_fail('Method not allowed.', 405);
}

// Usernames rather than raw ids, so the export is portable across installs/groups -- an id that
// only means something in this database would be useless (or actively misleading) on import
// elsewhere. NULL holder/assignee stays NULL.
$stmt = $pdo->prepare(
    "SELECT t.*, creator.username AS created_by_username,
            holder.username AS user_username,
            assignee.username AS assigned_user_username
     FROM tasks t
     JOIN users creator ON creator.id = t.created_by
     LEFT JOIN users holder ON holder.id = t.user_id
     LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
     WHERE t.group_id = ?
     ORDER BY t.list_type ASC, t.period_key ASC, t.created_at ASC"
);
$stmt->execute([$groupId]);

$tasks = array_map(static function (array $row): array {
    return [
        'list_type' => $row['list_type'],
        'period_key' => $row['period_key'],
        'title' => $row['title'],
        'points' => (int) $row['points'],
        'status' => $row['status'],
        'window_start' => $row['window_start'],
        'window_end' => $row['window_end'],
        'assigned_type' => $row['assigned_type'],
        'priority' => $row['priority'],
        'time_limit_minutes' => $row['time_limit_minutes'] !== null ? (int) $row['time_limit_minutes'] : null,
        'assigned_at' => $row['assigned_at'],
        'created_at' => $row['created_at'],
        'completed_at' => $row['completed_at'],
        'created_by' => $row['created_by_username'],
        'user' => $row['user_username'],
        'assigned_user' => $row['assigned_user_username'],
    ];
}, $stmt->fetchAll());

$export = [
    'format' => 'todoer-tasks',
    'version' => 1,
    'exported_at' => gmdate('c'),
    'group' => $group['name'],
    'tasks' => $tasks,
];

$filename = 'todoer-tasks-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $group['name']) . '-' . gmdate('Ymd-His') . '.json';

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
