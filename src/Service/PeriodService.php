<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ListType;
use App\Domain\Enum\TaskStatus;
use App\Database\TransactionManager;
use App\Domain\Period\Period;
use App\Repository\AwardRepository;
use App\Repository\ClosedPeriodRepository;
use App\Repository\GameStateRepository;
use App\Repository\TaskRepository;
use App\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Closing periods and crowning winners.
 *
 * A period closes either because the clock moved past it or because every task in it is settled
 * ("ends early"). Closing tallies the group's points for that period, crowns the top scorer (ties
 * broken randomly) and awards them a random prize the group has not won before.
 *
 * All of it is per group: each group closes its own copy of the same day, week or month and crowns
 * its own winner, so one group's scores never decide another group's prize. `periods_closed` is
 * what makes closing idempotent, which matters because this is attempted from several places on
 * every request.
 */
final class PeriodService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ClosedPeriodRepository $closedPeriods,
        private readonly AwardRepository $awards,
        private readonly GameStateRepository $gameState,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly LoggerInterface $logger
    ) {
    }

    public function currentPeriod(ListType $listType): Period
    {
        return Period::forTimestamp($listType, $this->clock->timestamp(), $this->clock->timezone());
    }

    /** Closes every elapsed, not-yet-tallied period of one group's lists. */
    public function closeElapsedPeriods(int $groupId): void
    {
        foreach (ListType::all() as $listType) {
            $currentKey = $this->currentPeriod($listType)->key;
            foreach ($this->tasks->unclosedPeriodKeysBefore($groupId, $listType, $currentKey) as $periodKey) {
                $this->closePeriod($groupId, new Period($listType, $periodKey));
            }
        }
    }

    /**
     * Tallies one period, awards its prize and marks it closed -- in a single transaction, so a
     * crash cannot leave a period marked closed with nobody crowned.
     */
    public function closePeriod(int $groupId, Period $period): void
    {
        $this->transactions->transactional(function () use ($groupId, $period): void {
            $totals = $this->tasks->pointsByUser($groupId, $period);
            $topScore = $totals === [] ? 0 : $totals[0]['total'];

            if ($topScore > 0) {
                $winners = array_values(array_filter(
                    $totals,
                    static fn(array $row): bool => $row['total'] === $topScore
                ));
                $winner = $winners[random_int(0, count($winners) - 1)];

                $prizeId = $this->awards->drawPrizeId($groupId);
                if ($prizeId === null) {
                    // No prize pool at all (a hand-emptied `prizes` table): close the period rather
                    // than failing the request, and say so in the log.
                    $this->logger->warning('Closing a period with no prize pool to draw from', [
                        'group_id' => $groupId,
                        'list_type' => $period->listType->value,
                        'period_key' => $period->key,
                    ]);
                } else {
                    $this->awards->create($groupId, $winner['user_id'], $period, $topScore, $prizeId);
                }
            }

            $this->closedPeriods->markClosed($groupId, $period);
        });
    }

    /**
     * The "ends early" case: a started period whose every task is settled closes immediately rather
     * than waiting for the clock. A period that was never started, has no tasks at all, or is
     * already closed is left alone -- an empty game does not crown a winner.
     */
    public function maybeFinishEarly(int $groupId, Period $period): void
    {
        if (!$this->gameState->hasStarted($groupId, $period)) {
            return;
        }
        if ($this->closedPeriods->isClosed($groupId, $period)) {
            return;
        }
        if ($this->tasks->countWithStatus($groupId, $period, TaskStatus::Unassigned, TaskStatus::Open) > 0) {
            return;
        }
        if ($this->tasks->countForPeriod($groupId, $period) === 0) {
            return;
        }

        $this->closePeriod($groupId, $period);
    }
}
