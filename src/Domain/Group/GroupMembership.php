<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Enum\GroupRole;
use App\Exception\AuthorizationException;

/** A user's place in a group: which group, and with what role. */
final class GroupMembership
{
    public function __construct(
        public readonly Group $group,
        public readonly GroupRole $role
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new AuthorizationException('Only a group admin can do that.');
        }
    }
}
