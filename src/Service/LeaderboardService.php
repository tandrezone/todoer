<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ListType;
use App\Domain\Group\GroupMembership;
use App\Repository\LeaderboardRepository;

/**
 * The standings: Today / This week / This month side by side, plus all-time.
 *
 * Group-only by construction -- the rows are this group's members and the points are earned on this
 * group's tasks. Somebody outside the group has no place in these boards, and this group has no
 * place in theirs.
 */
final class LeaderboardService
{
    public function __construct(
        private readonly LeaderboardRepository $leaderboards,
        private readonly PeriodService $periods
    ) {
    }

    /** @return array<string, mixed> */
    public function boards(GroupMembership $membership): array
    {
        $groupId = $membership->group->id;

        $boards = [];
        foreach (ListType::all() as $listType) {
            $period = $this->periods->currentPeriod($listType);
            $boards[$listType->value] = [
                'label' => $listType->boardLabel(),
                'period_label' => $period->label(),
                'rows' => $this->leaderboards->forPeriod($groupId, $period),
            ];
        }

        return [
            'ok' => true,
            'group' => ['id' => $groupId, 'name' => $membership->group->name],
            'boards' => $boards,
            'all_time' => $this->leaderboards->allTime($groupId),
        ];
    }
}
