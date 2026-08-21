<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Whether a task belongs to the shared pool or is pinned to one person.
 *
 * A SPECIFIC_USER task is theirs alone: it is never handed to anyone else on Start, never up for
 * grabs while it is live, and if it is missed it expires rather than being quietly unlocked.
 */
enum AssignmentType: string
{
    case AnyUser = 'ANY_USER';
    case SpecificUser = 'SPECIFIC_USER';

    public function isLocked(): bool
    {
        return $this === self::SpecificUser;
    }
}
