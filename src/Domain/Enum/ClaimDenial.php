<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Why a task is not up for grabs by this viewer.
 *
 * The codes are part of the API contract: the dashboard turns them into a tooltip, and the
 * complete endpoint turns them into the 403 message, so the checkbox the client draws and the
 * rule the server enforces cannot drift apart.
 */
enum ClaimDenial: string
{
    case Own = 'own';
    case NotOpen = 'not_open';
    case Locked = 'locked';
    case NotOpenYet = 'not_open_yet';
    case WindowClosed = 'window_closed';
    case NotRunning = 'not_running';

    public function message(): string
    {
        return match ($this) {
            self::Locked => 'That task is locked to one person, so only they can do it.',
            self::NotOpenYet => "That task's time window hasn't opened yet.",
            self::WindowClosed => "That task's time window has closed.",
            self::NotOpen => 'That task is already settled.',
            self::NotRunning => "That list isn't running -- only the person it's assigned to can tick it off.",
            self::Own => "You can't do that task right now.",
        };
    }
}
