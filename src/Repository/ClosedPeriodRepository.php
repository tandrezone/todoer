<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Period\Period;
use App\Support\Clock;
use PDO;

/**
 * Which of a group's periods have already been tallied and awarded (`periods_closed`).
 *
 * This table is the guard against double-awarding a prize: closing is attempted from several
 * places (the sweep, a completion that finishes a list early) and the UNIQUE constraint plus the
 * INSERT OR IGNORE make repeated attempts harmless.
 */
final class ClosedPeriodRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    public function isClosed(int $groupId, Period $period): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM periods_closed WHERE group_id = ? AND list_type = ? AND period_key = ?'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);

        return $statement->fetchColumn() !== false;
    }

    public function markClosed(int $groupId, Period $period): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO periods_closed (group_id, list_type, period_key, closed_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key, $this->clock->sqlNow()]);
    }
}
