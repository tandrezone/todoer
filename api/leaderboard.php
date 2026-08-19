<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];

$boards = [];
foreach (TODOER_LIST_TYPES as $listType) {
    $periodKey = todoer_period_key($listType);
    $boards[$listType] = [
        'label' => TODOER_LABELS[$listType],
        'period_label' => todoer_period_label($listType, $periodKey),
        'rows' => todoer_leaderboard($pdo, $listType, $periodKey),
    ];
}

todoer_respond([
    'ok' => true,
    'boards' => $boards,
    'all_time' => todoer_all_time_leaderboard($pdo),
]);
