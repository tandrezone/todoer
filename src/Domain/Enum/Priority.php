<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum Priority: string
{
    case High = 'HIGH';
    case Moderate = 'MODERATE';
    case Low = 'LOW';

    /**
     * HIGH priority tasks always use this shorter completion timer once assigned, whatever
     * per-task limit was submitted. "Dynamic" because it is measured from the moment of
     * (re)assignment rather than a fixed wall-clock time -- retune the game by changing this
     * one constant.
     */
    public const HIGH_PRIORITY_TIME_LIMIT_MINUTES = 30;

    /** Sort weight for the task list: HIGH first, then MODERATE, then LOW. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Moderate => 1,
            self::Low => 2,
        };
    }

    public function usesDynamicTimeLimit(): bool
    {
        return $this === self::High;
    }
}
