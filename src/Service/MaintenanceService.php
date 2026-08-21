<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ListType;
use App\Repository\GroupRepository;

/**
 * The background bookkeeping, in the order it has to happen.
 *
 * Order matters: a reassignment or a completion can be exactly what makes a period newly eligible
 * to close, so expiries run first, then the early-finish check, then the time-based close.
 *
 * The sweep covers every group on the installation rather than just the caller's, because timers,
 * deadlines and prize draws have to keep ticking for a group even while none of its members has the
 * app open. It only ever *writes* within a single group's own rows.
 */
final class MaintenanceService
{
    public function __construct(
        private readonly AssignmentService $assignment,
        private readonly PeriodService $periods,
        private readonly GroupRepository $groups
    ) {
    }

    public function sweep(): void
    {
        $this->assignment->processExpirations();
        $this->assignment->processDeadlineNotifications();

        foreach ($this->groups->allIds() as $groupId) {
            foreach (ListType::all() as $listType) {
                $this->periods->maybeFinishEarly($groupId, $this->periods->currentPeriod($listType));
            }
            $this->periods->closeElapsedPeriods($groupId);
        }
    }
}
