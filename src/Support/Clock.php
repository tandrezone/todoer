<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "What time is it" as an injected dependency.
 *
 * The game is made of deadlines -- period keys, task windows, expiry timers -- and every one of
 * them used to be computed from a bare time()/date() call, which is exactly the code you cannot
 * test without waiting for tomorrow. Everything now asks a Clock, and tests hand the services a
 * FrozenClock instead.
 */
class Clock
{
    private readonly DateTimeZone $timezone;

    public function __construct(?DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }

    /** The "YYYY-MM-DD HH:MM:SS" shape SQLite's datetime() produces, used everywhere in storage. */
    public function sqlNow(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }
}
