<?php

declare(strict_types=1);

namespace App\Domain\Enum;

use App\Exception\ValidationException;

/**
 * The three lists the game is played on -- and the points each one is worth.
 *
 * Scoring lived in a TODOER_POINTS array keyed by string; making it a method on the enum means a
 * list type can no longer be a typo, and "how many points is a weekly task" has exactly one
 * answer in the codebase.
 */
enum ListType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function points(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 3,
            self::Monthly => 5,
        };
    }

    /** The leaderboard heading for this list. */
    public function boardLabel(): string
    {
        return match ($this) {
            self::Daily => 'Today',
            self::Weekly => 'This week',
            self::Monthly => 'This month',
        };
    }

    public function title(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public static function fromRequest(string $value): self
    {
        return self::tryFrom($value) ?? throw new ValidationException('Invalid list type.');
    }
}
