<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Period\Period;
use App\Support\Clock;
use PDO;

/**
 * The Start/Stop state of a group's list for one period (`game_starts`).
 *
 * The row existing means "distribution has run at least once for this period", which is why
 * pressing Start again only sweeps up tasks added since rather than reshuffling everything.
 * `running` is the live toggle: while it is on, tasks can't be added, edited or deleted and the
 * list is locked down to ticking things off.
 */
final class GameStateRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    public function isRunning(int $groupId, Period $period): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT running FROM game_starts WHERE group_id = ? AND list_type = ? AND period_key = ?'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);
        $running = $statement->fetchColumn();

        return $running !== false && (int) $running === 1;
    }

    public function hasStarted(int $groupId, Period $period): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM game_starts WHERE group_id = ? AND list_type = ? AND period_key = ?'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);

        return $statement->fetchColumn() !== false;
    }

    public function markRunning(int $groupId, Period $period): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO game_starts (group_id, list_type, period_key, running, started_at) VALUES (?, ?, ?, 1, ?)
             ON CONFLICT(group_id, list_type, period_key) DO UPDATE SET running = 1'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key, $this->clock->sqlNow()]);
    }

    /** @return bool False when the period was never started -- there is nothing to stop. */
    public function markStopped(int $groupId, Period $period): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE game_starts SET running = 0 WHERE group_id = ? AND list_type = ? AND period_key = ?'
        );
        $statement->execute([$groupId, $period->listType->value, $period->key]);

        return $statement->rowCount() > 0;
    }
}
