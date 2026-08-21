<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Enum\GroupRole;
use App\Domain\User\User;

/** One row of the member list: who they are, their role, and when they joined. */
final class GroupMember
{
    public function __construct(
        public readonly User $user,
        public readonly GroupRole $role,
        public readonly ?string $joinedAt = null
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            User::fromRow($row),
            GroupRole::fromString((string) ($row['role'] ?? 'member')),
            isset($row['joined_at']) ? (string) $row['joined_at'] : null
        );
    }

    /**
     * The member shape the group page and the task board both render from.
     *
     * @return array<string, mixed>
     */
    public function toArray(?int $viewerId = null): array
    {
        return [
            'id' => $this->user->id,
            'username' => $this->user->username,
            'color' => $this->user->color,
            'role' => $this->role->value,
            'active' => $this->user->active ? 1 : 0,
            'joined_at' => $this->joinedAt,
            'is_me' => $viewerId !== null && $this->user->id === $viewerId,
        ];
    }
}
