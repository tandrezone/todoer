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
    $stmt = $pdo->query(
        "SELECT a.id, a.list_type, a.period_key, a.points, a.claimed, a.awarded_at,
                u.id AS user_id, u.username, u.color,
                p.description AS prize
         FROM awards a
         JOIN users u ON u.id = a.user_id
         JOIN prizes p ON p.id = a.prize_id
         ORDER BY a.awarded_at DESC"
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['period_label'] = todoer_period_label($row['list_type'], $row['period_key']);
        $row['is_mine'] = ((int) $row['user_id'] === (int) $user['id']);
    }
    todoer_respond(['ok' => true, 'awards' => $rows]);
}

if ($method === 'POST') {
    todoer_require_csrf();
    $body = todoer_json_body();
    if (($body['action'] ?? '') === 'claim') {
        $awardId = (int) ($body['award_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE awards SET claimed = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$awardId, $user['id']]);
        todoer_respond(['ok' => true]);
    }
    todoer_fail('Unknown action.');
}

todoer_fail('Method not allowed.', 405);
