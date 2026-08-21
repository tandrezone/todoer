<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * A task's lifecycle:
 *   Unassigned -> Open -> Done
 *                    \-> Expired   (missed its window/timer with nobody left to hand it to)
 */
enum TaskStatus: string
{
    case Unassigned = 'unassigned';
    case Open = 'open';
    case Done = 'done';
    case Expired = 'expired';

    /** Still in play: waiting to be handed out, or held by somebody right now. */
    public function isLive(): bool
    {
        return $this === self::Unassigned || $this === self::Open;
    }

    /** Finished one way or the other -- nothing more will happen to it this period. */
    public function isSettled(): bool
    {
        return $this === self::Done || $this === self::Expired;
    }
}
