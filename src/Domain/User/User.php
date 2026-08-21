<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * A player.
 *
 * The password hash is carried only when the row was read for authentication and is never part of
 * toArray(), so it cannot leak into an API response by accident -- the shape the client sees is
 * decided here, once, instead of by whichever SELECT happened to run.
 */
final class User
{
    /** The palette new accounts are coloured from, in order, so a group is visually readable. */
    public const COLORS = ['#5b8def', '#e0665a', '#3fb682', '#e0a63f', '#9b6de0', '#3fb6c9'];

    public const MAX_USERNAME_LENGTH = 40;

    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $color,
        public readonly bool $active = true,
        public readonly ?string $createdAt = null,
        private readonly ?string $passwordHash = null
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['username'],
            (string) ($row['color'] ?? self::COLORS[0]),
            (bool) ($row['active'] ?? 1),
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['password_hash']) ? (string) $row['password_hash'] : null
        );
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    /** The colour a new account gets, cycling the palette by how many accounts already exist. */
    public static function colorForIndex(int $index): string
    {
        return self::COLORS[$index % count(self::COLORS)];
    }
}
