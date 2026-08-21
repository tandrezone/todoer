<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionManager;
use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ListType;
use App\Domain\Enum\TaskEvent;
use App\Domain\Enum\TaskStatus;
use App\Domain\Period\Period;
use App\Domain\Task\Task;
use App\Repository\GameStateRepository;
use App\Repository\GroupRepository;
use App\Repository\TaskHistoryRepository;
use App\Repository\TaskRepository;
use App\Support\Clock;

/**
 * The assignment engine: who gets which task, and what happens when they miss it.
 *
 * Distribution on Start is workload-balancing rather than round-robin: each task goes to whichever
 * active member currently holds the fewest open tasks this period, and the running count is
 * re-checked after every assignment so a run of tasks spreads out evenly instead of piling onto
 * whoever was least loaded at the start. Locked (SPECIFIC_USER) tasks are handed out first, to
 * their designated person, and are excluded from balancing.
 *
 * Reassignment candidates always come from the task's own group, so a missed task moves sideways
 * within the group or expires -- it can never escape into another group.
 */
final class AssignmentService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskHistoryRepository $history,
        private readonly GameStateRepository $gameState,
        private readonly GroupRepository $groups,
        private readonly NotificationService $notifications,
        private readonly PeriodService $periods,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock
    ) {
    }

    public function isRunning(int $groupId, Period $period): bool
    {
        return $this->gameState->isRunning($groupId, $period);
    }

    /**
     * The "On Start" distribution. Idempotent: it only touches tasks still unassigned, so pressing
     * Start again (because a task was added while the list was stopped) sweeps up what is new
     * rather than reshuffling everything.
     *
     * @return array<string, mixed>
     */
    public function startGame(int $groupId, ListType $listType): array
    {
        $period = $this->periods->currentPeriod($listType);
        $activeUsers = $this->groups->activeMembers($groupId);
        if ($activeUsers === []) {
            return ['started' => false, 'reason' => 'No active players in this group to assign tasks to.'];
        }

        $result = $this->transactions->transactional(function () use ($groupId, $period, $activeUsers): array {
            // 1. Locked tasks go straight to the person they were pinned to.
            $locked = $this->tasks->unassignedForPeriod($groupId, $period, AssignmentType::SpecificUser);
            foreach ($locked as $task) {
                if ($task->assignedUserId === null) {
                    continue; // malformed row (locked with nobody designated) -- leave for manual fixup
                }
                $this->assign($task->id, $task->assignedUserId, null, TaskEvent::Assigned, 'locked to designated user on start');
            }

            // 2. Shared-pool tasks, balanced across the active members.
            $open = Task::sortForView($this->tasks->unassignedForPeriod($groupId, $period, AssignmentType::AnyUser));
            $load = $this->tasks->openTaskLoad($groupId, $period, array_map(
                static fn($user): int => $user->id,
                $activeUsers
            ));

            foreach ($open as $task) {
                $targetUserId = $this->leastLoadedUserId($load);
                if ($targetUserId === null) {
                    break;
                }
                $this->assign($task->id, $targetUserId, null, TaskEvent::Assigned, 'distributed on start');
                $load[$targetUserId]++;
            }

            // Marks the period started *and* running: pressing Start after a Stop re-runs this
            // whole distribution and flips running back on.
            $this->gameState->markRunning($groupId, $period);
            $this->notifications->announceGameStarted($groupId, $period);

            return [
                'started' => true,
                'running' => true,
                'locked_assigned' => count($locked),
                'open_assigned' => count($open),
                'active_users' => count($activeUsers),
            ];
        });

        return $result;
    }

    /**
     * The Stop half of the toggle: flips a started period back to not-running without touching any
     * assignment. While stopped, tasks can be added and edited again.
     *
     * @return array<string, mixed>
     */
    public function stopGame(int $groupId, ListType $listType): array
    {
        $period = $this->periods->currentPeriod($listType);
        if (!$this->gameState->markStopped($groupId, $period)) {
            return ['stopped' => false, 'reason' => 'This list has not been started yet.'];
        }

        return ['stopped' => true, 'running' => false];
    }

    /**
     * Assigns a freshly created task immediately, when its period's game is already under way, so
     * it is not stranded as unassigned until the next manual Start. A no-op otherwise.
     */
    public function assignNewTaskIfRunning(int $taskId, int $groupId): void
    {
        $task = $this->tasks->find($taskId, $groupId);
        if ($task === null || $task->status !== TaskStatus::Unassigned) {
            return;
        }
        if (!$this->gameState->isRunning($groupId, $task->period())) {
            return; // wait for the next explicit Start
        }

        if ($task->assignmentType->isLocked()) {
            // Only if the designated person is still in this group. If they were removed, the task
            // stays unassigned for an admin to re-point rather than following them out.
            if ($task->assignedUserId !== null && $this->groups->isMember($groupId, $task->assignedUserId)) {
                $this->assign($task->id, $task->assignedUserId, null, TaskEvent::Assigned, 'locked to designated user');
            }

            return;
        }

        $activeUsers = $this->groups->activeMembers($groupId);
        if ($activeUsers === []) {
            return;
        }

        $load = $this->tasks->openTaskLoad($groupId, $task->period(), array_map(
            static fn($user): int => $user->id,
            $activeUsers
        ));
        $targetUserId = $this->leastLoadedUserId($load);
        if ($targetUserId !== null) {
            $this->assign($task->id, $targetUserId, null, TaskEvent::Assigned, 'distributed on add (period already started)');
        }
    }

    /**
     * The execution/expiration/reassignment sweep. Every open task whose deadline has passed is:
     *   - shared-pool: unassigned from its holder and handed to a *different* active member (least
     *     loaded of the remaining candidates), so nobody simply gets the same task back. A HIGH
     *     priority task's new holder automatically gets the short dynamic timer, because assigned_at
     *     resets and the HIGH limit always applies regardless of holder.
     *   - locked: marked expired, because there is no other person it is allowed to go to.
     *   - shared-pool with nobody else available (a single-player game): also expired rather than
     *     looped back to the same person, so "missed" keeps meaning something.
     */
    public function processExpirations(): void
    {
        $now = $this->clock->timestamp();

        foreach ($this->tasks->allOpen() as $task) {
            if (!$task->isOverdueAt($now)) {
                continue;
            }

            if ($task->assignmentType->isLocked()) {
                $this->expire($task, 'missed by locked user, no reassignment target');
                continue;
            }

            $candidates = array_values(array_filter(
                $this->groups->activeMembers($task->groupId),
                static fn($user): bool => $user->id !== $task->userId
            ));
            if ($candidates === []) {
                $this->expire($task, 'timed out, no other active player in this group');
                continue;
            }

            $load = $this->tasks->openTaskLoad($task->groupId, $task->period(), array_map(
                static fn($user): int => $user->id,
                $candidates
            ));
            $newUserId = $this->leastLoadedUserId($load);
            if ($newUserId === null) {
                continue;
            }
            $this->assign($task->id, $newUserId, $task->userId, TaskEvent::Reassigned, 'timed out');
        }
    }

    /** Warns each current holder once, when 90% of their task's effective window has elapsed. */
    public function processDeadlineNotifications(): void
    {
        $now = $this->clock->timestamp();

        foreach ($this->tasks->openWithTiming() as $task) {
            $deadline = $task->deadline();
            if ($deadline === null) {
                continue;
            }

            $startSource = $task->windowStart !== null && $task->windowStart !== ''
                ? $task->windowStart
                : (string) $task->assignedAt;
            $start = strtotime($startSource);
            $end = strtotime($deadline);
            if ($start === false || $end === false || $end <= $start) {
                continue;
            }

            $warningAt = $start + (int) round(($end - $start) * 0.9);
            if ($now < $warningAt || $now >= $end) {
                continue;
            }

            $this->notifications->announceDeadlineApproaching($task, $deadline);
        }
    }

    /**
     * Hands a claimed task back to whoever it was taken from, when the claimer un-ticks it.
     *
     * There is no "previous holder" column: the append-only history *is* the record, so the original
     * holder is read back from the most recent claim event. Returns the user the task went back to,
     * or null if this task was never claimed (in which case the caller just reopens it normally and
     * it stays put).
     */
    public function undoClaim(int $taskId, int $claimerId, int $groupId): ?int
    {
        $claim = $this->history->lastClaim($taskId);
        if ($claim === null || $claim['to_user_id'] !== $claimerId || $claim['from_user_id'] === null) {
            return null;
        }

        $originalHolder = $claim['from_user_id'];
        // Only give it back if they are still in the group and playing; otherwise leave it with the
        // claimer rather than parking it on somebody who cannot act on it.
        if (!$this->groups->isActiveMember($groupId, $originalHolder)) {
            return null;
        }

        $this->tasks->returnTo($taskId, $originalHolder);
        $this->history->log($taskId, TaskEvent::Reassigned, $claimerId, $originalHolder, 'claim undone');

        return $originalHolder;
    }

    private function assign(int $taskId, int $userId, ?int $fromUserId, TaskEvent $event, string $note): void
    {
        $this->tasks->assignTo($taskId, $userId);
        $this->history->log($taskId, $event, $fromUserId, $userId, $note);
    }

    private function expire(Task $task, string $note): void
    {
        $this->tasks->markExpired($task->id);
        $this->history->log($task->id, TaskEvent::Expired, $task->userId, null, $note);
    }

    /**
     * The least loaded candidate; ties go to whoever comes first in the candidate list, which is
     * alphabetical by username. Deterministic, so the same board distributes the same way twice --
     * and the same order the original implementation produced.
     *
     * @param array<int, int> $load user id => open task count
     */
    private function leastLoadedUserId(array $load): ?int
    {
        $bestUserId = null;
        $bestLoad = PHP_INT_MAX;

        foreach ($load as $userId => $openTasks) {
            if ($openTasks < $bestLoad) {
                $bestUserId = $userId;
                $bestLoad = $openTasks;
            }
        }

        return $bestUserId;
    }
}
