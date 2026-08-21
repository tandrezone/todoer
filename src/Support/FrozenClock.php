<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/** A Clock stopped at a fixed instant, so time-dependent behaviour can be asserted. */
final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        parent::__construct($now->getTimezone() ?: null);
        $this->now = $now;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
