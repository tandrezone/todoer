<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum GroupRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** Anything that is not literally "admin" is a member -- roles never arrive from the client. */
    public static function fromString(string $role): self
    {
        return self::tryFrom($role) ?? self::Member;
    }
}
