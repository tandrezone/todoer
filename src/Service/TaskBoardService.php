<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ListType;
use App\Domain\Enum\TaskStatus;
use App\Domain\Group\GroupMembership;
use App\Domain\Task\Task;
use App\Domain\Task\TaskView;
use App\Domain\User\User;
use App\Repository\TaskRepository;
use App\Support\Clock;

/**
 * Assembles the dashboard payload: the three lists, the shared board, the group header and any
 * pending notifications, all as one response so the front-end renders from a single fetch.
 *
 * The ordering rules here are the game's, not decoration:
 *   * while a list is running, the whole board is the playing field -- your own live tasks first
 *     (so "what am I meant to be doing" stays at the top), then everyone else's live tasks (which
 *     are up for grabs), then whatever is already settled
 *   * while it is stopped, the list is just your own plate, and the full picture lives in the team
 *     board panel that game mode hides
 *   * weekly and monthly tasks have no visible list of their own: they fold into Daily, gated by
 *     Daily's Start/Stop, because a household reads one list a day
 */
final class TaskBoardService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly AssignmentService $assignment,
        private readonly PeriodService $periods,
        private readonly GroupService $groups,
        private readonly NotificationService $notifications,
        private readonly Clock $clock
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user, GroupMembership $membership): array
    {
        $groupId = $membership->group->id;
        $now = $this->clock->timestamp();
        $members = $this->groups->members($groupId);

        $lists = [];
        $viewsByList = [];
        foreach (ListType::all() as $listType) {
            [$payload, $views] = $this->listPayload($groupId, $listType, $user->id, $now);
            $lists[$listType->value] = $payload;
            $viewsByList[$listType->value] = $views;
        }

        $lists['daily']['items'] = $this->foldIntoDaily($lists, $viewsByList, $user->id, $now);

        return [
            'ok' => true,
            'tasks' => $lists,
            // Only fellow group members are offered as assignees, and only they appear on the board.
            'users' => array_map(
                static fn($member): array => $member->toArray($user->id),
                $members
            ),
            'group' => [
                'id' => $groupId,
                'name' => $membership->group->name,
                'role' => $membership->role->value,
                'member_count' => count($members),
            ],
            'notifications' => array_map(
                static fn($notification): array => $notification->toArray(),
                $this->notifications->unreadFor($user->id)
            ),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<TaskView>} The payload, and the task views it
     *                                                           was built from (reused for folding).
     */
    private function listPayload(int $groupId, ListType $listType, int $viewerId, int $now): array
    {
        $period = $this->periods->currentPeriod($listType);
        $running = $this->assignment->isRunning($groupId, $period);

        // One query feeds both the personal list and the shared board below.
        $views = array_map(
            static fn($task): TaskView => $task->forViewer($viewerId, $running, $now),
            $this->tasks->forPeriod($groupId, $period)
        );

        return [
            [
                'period_key' => $period->key,
                'label' => $period->label(),
                'running' => $running,
                'unassigned_count' => $this->tasks->countWithStatus($groupId, $period, TaskStatus::Unassigned),
                'items' => $running ? $this->gameModeItems($views) : $this->planningModeItems($views),
                'board' => array_map(static fn(TaskView $view): array => $view->toArray(), $this->sortBoard($views)),
            ],
            $views,
        ];
    }

    /**
     * Game mode: mine first, then everyone else's live tasks, then the settled ones in the order
     * they were finished.
     *
     * @param  list<TaskView> $views
     * @return list<array<string, mixed>>
     */
    private function gameModeItems(array $views): array
    {
        $mineOpen = array_values(array_filter(
            $views,
            static fn(TaskView $view): bool => $view->isMine && $view->status() === TaskStatus::Open
        ));
        $othersLive = array_values(array_filter(
            $views,
            static fn(TaskView $view): bool => !$view->isMine && $view->status()->isLive()
        ));
        $settled = array_values(array_filter(
            $views,
            static fn(TaskView $view): bool => $view->status()->isSettled()
        ));
        usort(
            $settled,
            static fn(TaskView $a, TaskView $b): int => strcmp((string) $a->task->completedAt, (string) $b->task->completedAt)
        );

        return array_map(
            static fn(TaskView $view): array => $view->toArray(),
            array_merge(TaskView::sortForView($mineOpen), TaskView::sortForView($othersLive), $settled)
        );
    }

    /**
     * Stopped: your own open tasks in window/priority order, with your completed ones appended.
     *
     * @param  list<TaskView> $views
     * @return list<array<string, mixed>>
     */
    private function planningModeItems(array $views): array
    {
        $mine = array_values(array_filter(
            $views,
            static fn(TaskView $view): bool => $view->isMine
                && in_array($view->status(), [TaskStatus::Open, TaskStatus::Done], true)
        ));
        $open = array_values(array_filter($mine, static fn(TaskView $view): bool => $view->status() === TaskStatus::Open));
        $done = array_values(array_filter($mine, static fn(TaskView $view): bool => $view->status() === TaskStatus::Done));

        return array_map(
            static fn(TaskView $view): array => $view->toArray(),
            array_merge(TaskView::sortForView($open), $done)
        );
    }

    /**
     * The shared board: every task in this period belonging to this group -- locked ones, other
     * members' ones, expired ones -- with the done ones pushed to the bottom.
     *
     * @param  list<TaskView> $views
     * @return list<TaskView>
     */
    private function sortBoard(array $views): array
    {
        $board = $views;
        usort($board, static function (TaskView $a, TaskView $b): int {
            $aDone = $a->status() === TaskStatus::Done ? 1 : 0;
            $bDone = $b->status() === TaskStatus::Done ? 1 : 0;

            return $aDone === $bDone
                ? strcmp((string) $a->task->createdAt, (string) $b->task->createdAt)
                : $aDone <=> $bDone;
        });

        return array_values($board);
    }

    /**
     * Folds the weekly and monthly boards into Daily's list.
     *
     * While Daily is running, a weekly/monthly task shows only inside its own time window and only
     * while it is not done. While Daily is stopped, every not-done weekly/monthly task in the group
     * shows regardless of window or holder -- that is the planning view.
     *
     * Whether such a task can actually be ticked still follows *its own* list's Start/Stop (the
     * complete endpoint enforces that), so the viewer flags are recomputed against that list rather
     * than against Daily's state.
     *
     * @param  array<string, array<string, mixed>> $lists
     * @param  array<string, list<TaskView>>       $viewsByList
     * @return list<array<string, mixed>>
     */
    private function foldIntoDaily(array $lists, array $viewsByList, int $viewerId, int $now): array
    {
        $dailyRunning = (bool) $lists['daily']['running'];

        /** @var list<TaskView> $folded */
        $folded = [];
        foreach ([ListType::Weekly, ListType::Monthly] as $listType) {
            $listRunning = (bool) $lists[$listType->value]['running'];

            foreach ($viewsByList[$listType->value] as $view) {
                if ($view->status() === TaskStatus::Done) {
                    continue;
                }
                if ($dailyRunning && !$this->isWithinWindow($view->task, $now)) {
                    continue;
                }
                $folded[] = $view->task->forViewer($viewerId, $listRunning, $now);
            }
        }

        $dailyItems = $lists['daily']['items'];
        if ($folded === []) {
            return $dailyItems;
        }

        $foldedRows = array_map(
            static fn(TaskView $view): array => $view->toArray(),
            TaskView::sortForView($folded)
        );

        if (!$dailyRunning) {
            return array_merge($dailyItems, $foldedRows);
        }

        // Keep the settled tail at the bottom of the running list.
        $settledValues = [TaskStatus::Done->value, TaskStatus::Expired->value];
        $live = array_values(array_filter(
            $dailyItems,
            static fn(array $item): bool => !in_array($item['status'], $settledValues, true)
        ));
        $settled = array_values(array_filter(
            $dailyItems,
            static fn(array $item): bool => in_array($item['status'], $settledValues, true)
        ));

        return array_merge($live, $foldedRows, $settled);
    }

    /** Whether "now" falls inside a task's own window, treating a missing bound as open-ended. */
    private function isWithinWindow(Task $task, int $now): bool
    {
        $start = $task->windowStart;
        $end = $task->windowEnd;

        $afterStart = $start === null || $start === '' || (int) strtotime($start) <= $now;
        $beforeEnd = $end === null || $end === '' || (int) strtotime($end) >= $now;

        return $afterStart && $beforeEnd;
    }
}
