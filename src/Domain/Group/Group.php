<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * A group: the privacy and competition boundary.
 *
 * Everything scoreable belongs to exactly one group, and every query in the app is filtered by
 * it, so two groups sharing one installation are invisible to each other -- no shared tasks, no
 * shared leaderboard, no shared prize history.
 */
final class Group
{
    public const MAX_NAME_LENGTH = 60;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $inviteCode,
        public readonly ?int $createdBy = null,
        public readonly ?string $createdAt = null
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) ($row['invite_code'] ?? ''),
            isset($row['created_by']) ? (int) $row['created_by'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }

    /** Collapses whitespace and clamps the length; an empty name falls back rather than failing. */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            $name = 'My group';
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
