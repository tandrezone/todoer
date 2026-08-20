<?php
require_once __DIR__ . '/db.php';

function todoer_notify_all(PDO $pdo, string $eventKey, string $title, string $body): void {
    $users = $pdo->query('SELECT id FROM users')->fetchAll();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (user_id, event_key, title, body) VALUES (?, ?, ?, ?)'
    );
    foreach ($users as $user) {
        $stmt->execute([(int) $user['id'], $eventKey, $title, $body]);
    }
}

function todoer_notify_user(PDO $pdo, int $userId, string $eventKey, string $title, string $body): void {
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (user_id, event_key, title, body) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $eventKey, $title, $body]);
}

function todoer_user_notifications(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, title, body, created_at FROM notifications
         WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT 20'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function todoer_mark_notifications_read(PDO $pdo, int $userId, array $ids): void {
    $ids = array_values(array_filter(array_map('intval', $ids), fn(int $id): bool => $id > 0));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "UPDATE notifications SET read_at = datetime('now') WHERE user_id = ? AND id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$userId], $ids));
}