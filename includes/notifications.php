<?php
require_once __DIR__ . '/db.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

/**
 * The VAPID keypair used to sign push messages, or null if push can't work here.
 *
 * Resolution order:
 *   1. TODOER_VAPID_PUBLIC_KEY / TODOER_VAPID_PRIVATE_KEY environment variables, if both are set
 *      -- so a real deployment can keep keys out of the webroot and rotate them centrally.
 *   2. data/vapid.json, generated automatically the first time push is used.
 *
 * The auto-generation is the point: previously push needed two environment variables nobody had
 * set, so todoer_push_enabled() was false on every ordinary install (`php -S`, shared hosting,
 * a LAN box) and notifications silently never left the browser tab. Keys are a per-install
 * secret, not a configuration decision, so the app now mints its own and remembers them.
 *
 * A keypair is stable once written -- rotating it invalidates every existing browser
 * subscription (the front-end notices and re-subscribes, see assets/js/app.js).
 */
function todoer_vapid_keys(): ?array {
    static $keys = null;
    static $resolved = false;
    if ($resolved) {
        return $keys;
    }
    $resolved = true;

    $envPublic = getenv('TODOER_VAPID_PUBLIC_KEY');
    $envPrivate = getenv('TODOER_VAPID_PRIVATE_KEY');
    if (is_string($envPublic) && $envPublic !== '' && is_string($envPrivate) && $envPrivate !== '') {
        $keys = ['publicKey' => $envPublic, 'privateKey' => $envPrivate];
        return $keys;
    }

    $file = __DIR__ . '/../data/vapid.json';
    if (is_readable($file)) {
        $stored = json_decode((string) file_get_contents($file), true);
        if (is_array($stored) && !empty($stored['publicKey']) && !empty($stored['privateKey'])) {
            $keys = ['publicKey' => $stored['publicKey'], 'privateKey' => $stored['privateKey']];
            return $keys;
        }
    }

    $keys = todoer_generate_vapid_keys($file);
    return $keys;
}

/**
 * Mints a VAPID keypair and stores it at $file. Returns null (having logged why) if the library
 * or the crypto extensions it needs aren't available -- push then stays off and the rest of the
 * app carries on unaffected.
 */
function todoer_generate_vapid_keys(string $file): ?array {
    if (!class_exists('Minishlink\\WebPush\\VAPID')) {
        error_log('Todoer: push disabled -- minishlink/web-push is not installed (run `composer install`).');
        return null;
    }
    try {
        $generated = \Minishlink\WebPush\VAPID::createVapidKeys();
    } catch (Throwable $e) {
        error_log('Todoer: could not generate VAPID keys: ' . $e->getMessage());
        return null;
    }

    $keys = ['publicKey' => $generated['publicKey'], 'privateKey' => $generated['privateKey']];
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    // Written with an exclusive lock and tight permissions: the private key is what proves pushes
    // come from this install, and data/ is inside the webroot on a plain `php -S` setup.
    if (@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        error_log('Todoer: generated VAPID keys but could not write ' . $file . ' -- push will stay off.');
        return null;
    }
    @chmod($file, 0600);
    return $keys;
}

function todoer_push_enabled(): bool {
    return class_exists('Minishlink\\WebPush\\WebPush') && todoer_vapid_keys() !== null;
}

function todoer_push_public_key(): ?string {
    $keys = todoer_vapid_keys();
    return $keys['publicKey'] ?? null;
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

    $keys = todoer_vapid_keys();
    $auth = [
        'VAPID' => [
            'subject' => getenv('TODOER_VAPID_SUBJECT') ?: 'mailto:admin@example.com',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ],
    ];

    // Delivery is best-effort and must never take the request down with it: a push is a side
    // effect of finishing a task or starting a game, and an unreachable push service (offline
    // box, expired endpoint, TLS hiccup) should not turn "task done" into a 500.
    try {
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
            $status = $report->getResponse()?->getStatusCode();
            // 404/410 mean the browser threw the subscription away (uninstalled PWA, cleared
            // site data); 403 means it was signed with a key this endpoint no longer accepts,
            // i.e. our VAPID keypair changed. Either way the row is dead weight -- drop it so we
            // stop retrying, and the client re-subscribes on its next visit.
            if (in_array($status, [403, 404, 410], true)) {
                $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
                $delete->execute([$endpoint]);
            }
            error_log('Web Push delivery failed for ' . $endpoint . ': ' . $report->getReason());
        }
    } catch (Throwable $e) {
        error_log('Web Push send failed: ' . $e->getMessage());
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

/**
 * "Someone else did your task." Fires when a player takes over an open task that was assigned to
 * $holderId and completes it, which moves its points to them -- so the person who lost the task
 * hears about it from the app rather than noticing the scoreboard shift later.
 *
 * The event key includes the task and the claimer plus a timestamp, so the same task changing
 * hands twice notifies twice (the UNIQUE(user_id, event_key) guard is there to stop *repeats of
 * one event*, not to collapse genuinely separate ones).
 */
function todoer_notify_task_claimed(PDO $pdo, int $holderId, int $claimerId, string $claimerName, array $task): void {
    if ($holderId === $claimerId) {
        return;
    }
    todoer_notify_user(
        $pdo,
        $holderId,
        'task-claimed:' . $task['id'] . ':' . $claimerId . ':' . date('YmdHis'),
        $claimerName . ' did your task',
        $claimerName . ' got to "' . $task['title'] . '" before you did and took the '
            . (int) $task['points'] . ' point' . ((int) $task['points'] === 1 ? '' : 's') . '.'
    );
}
