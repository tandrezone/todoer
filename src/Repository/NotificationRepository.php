<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Notification\Notification;
use App\Support\Clock;
use PDO;

/** Stored in-app notifications (`notifications`). */
final class NotificationRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    /**
     * Stores a notification unless this user has already had this exact event.
     *
     * @return bool Whether a row was actually inserted -- the caller uses this to decide whether
     *              to also send a push, so a repeated event never re-pings anyone's phone.
     */
    public function insertOnce(int $userId, string $eventKey, string $title, string $body): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO notifications (user_id, event_key, title, body, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $eventKey, $title, $body, $this->clock->sqlNow()]);

        return $statement->rowCount() > 0;
    }

    /** @return list<Notification> */
    public function unreadFor(int $userId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, title, body, created_at FROM notifications
             WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $statement->execute([$userId, $limit]);

        return array_map(static fn(array $row): Notification => Notification::fromRow($row), $statement->fetchAll());
    }

    /** @param list<int> $ids */
    public function markRead(int $userId, array $ids): void
    {
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE notifications SET read_at = ? WHERE user_id = ? AND id IN ($placeholders)"
        );
        $statement->execute(array_merge([$this->clock->sqlNow(), $userId], $ids));
    }
}
