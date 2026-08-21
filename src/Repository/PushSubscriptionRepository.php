<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Clock;
use PDO;

/** Browser push endpoints (`push_subscriptions`). */
final class PushSubscriptionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    /** @return list<array{endpoint: string, p256dh: string, auth: string}> */
    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $statement->execute([$userId]);

        return array_map(static fn(array $row): array => [
            'endpoint' => (string) $row['endpoint'],
            'p256dh' => (string) $row['p256dh'],
            'auth' => (string) $row['auth'],
        ], $statement->fetchAll());
    }

    /** An endpoint belongs to whichever account most recently subscribed with it. */
    public function save(int $userId, string $endpoint, string $p256dh, string $auth): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth'
        );
        $statement->execute([$userId, $endpoint, $p256dh, $auth, $this->clock->sqlNow()]);
    }

    public function delete(int $userId, string $endpoint): void
    {
        $statement = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        $statement->execute([$userId, $endpoint]);
    }

    /** Drops a dead endpoint the push service has rejected, whoever it belonged to. */
    public function deleteByEndpoint(string $endpoint): void
    {
        $statement = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        $statement->execute([$endpoint]);
    }
}
