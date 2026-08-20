<?php
require_once __DIR__ . '/db.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function todoer_push_enabled(): bool {
    return class_exists('Minishlink\\WebPush\\WebPush')
        && getenv('TODOER_VAPID_PUBLIC_KEY')
        && getenv('TODOER_VAPID_PRIVATE_KEY');
}

function todoer_push_public_key(): ?string {
    $key = getenv('TODOER_VAPID_PUBLIC_KEY');
    return $key !== false && $key !== '' ? $key : null;
}

function todoer_send_push(PDO $pdo, int $userId, string $title, string $body): void {
    if (!todoer_push_enabled()) {
        return;
    }

    $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) {
        return;
    }

    $auth = [
        'VAPID' => [
            'subject' => getenv('TODOER_VAPID_SUBJECT') ?: 'mailto:admin@example.com',
            'publicKey' => getenv('TODOER_VAPID_PUBLIC_KEY'),
            'privateKey' => getenv('TODOER_VAPID_PRIVATE_KEY'),
        ],
    ];
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    foreach ($subscriptions as $subscription) {
        $webPush->queueNotification(
            \Minishlink\WebPush\Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'publicKey' => $subscription['p256dh'],
                'authToken' => $subscription['auth'],
            ]),
            json_encode(['title' => $title, 'body' => $body])
        );
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            continue;
        }
        $endpoint = (string) $report->getRequest()->getUri();
        if (in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
            $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
            $delete->execute([$endpoint]);
        }
        error_log('Web Push delivery failed for ' . $endpoint . ': ' . $report->getReason());
    }
}

function todoer_save_push_subscription(PDO $pdo, int $userId, array $subscription): void {
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '' || strlen($endpoint) > 2000) {
        throw new InvalidArgumentException('Invalid push subscription.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
         ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth'
    );
    $stmt->execute([$userId, $endpoint, $p256dh, $auth]);
}

function todoer_remove_push_subscription(PDO $pdo, int $userId, string $endpoint): void {
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
    $stmt->execute([$userId, $endpoint]);
}

/**
 * Announce something to one group's members only (never install-wide) -- a group's game
 * starting is nobody else's business, and a stray notification would leak both the group's
 * existence and its activity.
 */
function todoer_notify_group(PDO $pdo, int $groupId, string $eventKey, string $title, string $body): void {
    $stmt = $pdo->prepare('SELECT user_id AS id FROM group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $users = $stmt->fetchAll();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (user_id, event_key, title, body) VALUES (?, ?, ?, ?)'
    );
    foreach ($users as $user) {
        $inserted = $stmt->execute([(int) $user['id'], $eventKey, $title, $body]);
        if ($inserted && $stmt->rowCount() > 0) {
            todoer_send_push($pdo, (int) $user['id'], $title, $body);
        }
    }
}

function todoer_notify_user(PDO $pdo, int $userId, string $eventKey, string $title, string $body): void {
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO notifications (user_id, event_key, title, body) VALUES (?, ?, ?, ?)'
    );
    $inserted = $stmt->execute([$userId, $eventKey, $title, $body]);
    if ($inserted && $stmt->rowCount() > 0) {
        todoer_send_push($pdo, $userId, $title, $body);
    }
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