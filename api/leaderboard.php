<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];

// Standings are group-only: the rows are this group's members and the points are earned on this
// group's tasks. Someone outside the group has no place in these boards, and this group has no
// place in theirs.
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
$groupId = (int) $group['id'];

$boards = [];
foreach (TODOER_LIST_TYPES as $listType) {
    $periodKey = todoer_period_key($listType);
    $boards[$listType] = [
        'label' => TODOER_LABELS[$listType],
        'period_label' => todoer_period_label($listType, $periodKey),
        'rows' => todoer_leaderboard($pdo, $groupId, $listType, $periodKey),
    ];
}

todoer_respond([
    'ok' => true,
    'group' => ['id' => $groupId, 'name' => $group['name']],
    'boards' => $boards,
    'all_time' => todoer_all_time_leaderboard($pdo, $groupId),
]);
