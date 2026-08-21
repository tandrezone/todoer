<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * A stored in-app notification.
 *
 * `eventKey` is what makes delivery exactly-once: it is UNIQUE per user, so the same event can be
 * announced from any number of request paths and still only arrive once. Keys therefore have to
 * identify the *event*, not the kind of event -- a task changing hands twice is two events.
 */
final class Notification
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $createdAt
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'created_at' => $this->createdAt,
        ];
    }
}
