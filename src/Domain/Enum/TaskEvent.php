<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The append-only task history events.
 *
 * This trail is what makes a surprise explainable ("why is this suddenly Sam's?") and it is also
 * load-bearing: undoing a claim reads the previous holder back out of the most recent
 * Reassigned event, because there is no "previous holder" column to consult.
 */
enum TaskEvent: string
{
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case Expired = 'expired';
    case Completed = 'completed';
    case Reopened = 'reopened';
}
