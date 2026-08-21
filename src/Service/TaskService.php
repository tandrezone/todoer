<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\ClaimDenial;
use App\Domain\Enum\ListType;
use App\Domain\Enum\TaskEvent;
use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use App\Exception\AuthorizationException;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Repository\TaskHistoryRepository;
use App\Repository\TaskRepository;
use App\Support\Clock;

/**
 * Everything that changes a task: adding, editing, deleting, ticking off and un-ticking.
 *
 * Every method takes the caller's group membership and every lookup is scoped by it, so a
 * hand-crafted request cannot reach a task in someone else's group -- not even to read whether it
 * exists. Within the group, who may do what is decided here rather than in the client: the
 * dashboard's checkbox is a rendering of these rules, not a second implementation of them.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskHistoryRepository $history,
        private readonly AssignmentService $assignment,
        private readonly PeriodService $periods,
        private readonly GroupService $groups,
        private readonly TaskDraftFactory $drafts,
        private readonly NotificationService $notifications,
        private readonly Clock $clock
    ) {
    }

    public function add(User $user, GroupMembership $membership, InputBag $input): int
    {
        $listType = ListType::fromRequest($input->string('list_type'));
        $period = $this->periods->currentPeriod($listType);
        $groupId = $membership->group->id;

        if ($this->assignment->isRunning($groupId, $period)) {
            throw new ValidationException('This list is running -- stop it before adding tasks.');
        }

        $draft = $this->drafts->fromInput($input, $period);
        if ($draft->assignedUserId !== null) {
            // Must be a member of *this* group -- otherwise a crafted request could park a task on
            // an outsider, who would then see it in their own list.
            $this->groups->requireMember(
                $groupId,
                $draft->assignedUserId,
                'Choose someone from your group to lock this task to.'
            );
        }

        // "Times per period" splits the task's window (or, left blank, the whole period) into that
        // many equal, back-to-back slices and creates one row per slice -- each occurrence is then
        // live, claimable and scored on its own, exactly like any other task, just for its own
        // 1/N of the period. A plain count of 1 (the default) is a single slice: today's behaviour,
        // unchanged, including a task with no window at all staying window-less.
        $firstTaskId = null;
        foreach ($period->divideIntoWindows($draft->occurrenceCount, $draft->windowStart, $draft->windowEnd) as $index => $window) {
            $occurrenceDraft = $draft->withWindow($window['start'], $window['end'], $index + 1);
            $taskId = $this->tasks->insert($occurrenceDraft, $groupId, $user->id);
            $firstTaskId ??= $taskId;

            // If this period's game is already under way, don't strand the new task as unassigned
            // until the next manual Start.
            $this->assignment->assignNewTaskIfRunning($taskId, $groupId);
        }

        return $firstTaskId;
    }

    public function edit(User $user, GroupMembership $membership, InputBag $input): void
    {
        $groupId = $membership->group->id;
        $task = $this->tasks->findEditable($input->int('task_id'), $groupId, $user->id);
        if ($task === null) {
            throw new NotFoundException('Task not found.');
        }
        if ($this->assignment->isRunning($groupId, $task->period())) {
            throw new ValidationException('This list is running -- stop it before editing tasks.');
        }

        // The task keeps its own period: editing a task never moves it to today's list.
        $period = $task->period();
        $draft = $this->drafts->fromInput($input, $period);
        if ($draft->assignedUserId !== null) {
            $this->groups->requireMember(
                $groupId,
                $draft->assignedUserId,
                'Choose someone from your group to lock this task to.'
            );
        }

        // This row is *one* occurrence out of $draft->occurrenceCount -- recompute its own slice
        // of the window rather than regenerating the whole family, since editing never creates or
        // removes sibling rows (see App\Service\TaskDraftFactory).
        $windows = $period->divideIntoWindows($draft->occurrenceCount, $draft->windowStart, $draft->windowEnd);
        $occurrenceIndex = max(1, min(count($windows), $draft->occurrenceIndex));
        $window = $windows[$occurrenceIndex - 1];
        $draft = $draft->withWindow($window['start'], $window['end'], $occurrenceIndex);

        if ($draft->changesAssignment($task)) {
            $this->tasks->updateWithAssignmentReset($task->id, $draft);

            return;
        }

        $this->tasks->updateDetails($task->id, $draft);
    }

    public function delete(User $user, GroupMembership $membership, InputBag $input): void
    {
        $groupId = $membership->group->id;
        $task = $this->tasks->findEditable($input->int('task_id'), $groupId, $user->id);
        if ($task === null) {
            // Nothing to delete (already gone, or never theirs): a no-op rather than a 404, so a
            // double-click on the delete button doesn't produce an error the user can't act on.
            return;
        }
        if ($this->assignment->isRunning($groupId, $task->period())) {
            throw new ValidationException('This list is running -- stop it before deleting tasks.');
        }

        $this->tasks->delete($task->id, $groupId, $user->id);
    }

    /**
     * Ticking a task off -- including taking over somebody else's while their list is running.
     *
     * Taking it over and finishing it are one atomic step: there is no "claimed but not done" state
     * to sit on, so nobody can hoard other people's tasks. The take-over is a conditional UPDATE, so
     * two people racing for the same task cannot both win it.
     *
     * @return array{claimed_from: ?int}
     */
    public function complete(User $user, GroupMembership $membership, InputBag $input): array
    {
        $groupId = $membership->group->id;
        $task = $this->tasks->find($input->int('task_id'), $groupId);
        if ($task === null) {
            throw new NotFoundException('Task not found.');
        }

        $claimedFrom = null;
        if (!$task->isHeldBy($user->id)) {
            if (!$this->assignment->isRunning($groupId, $task->period())) {
                throw new AuthorizationException(ClaimDenial::NotRunning->message());
            }

            $decision = $task->claimStateFor($user->id, $this->clock->timestamp());
            if (!$decision->claimable) {
                throw new AuthorizationException($decision->message());
            }

            $claimedFrom = $task->userId;
            if ($claimedFrom === null || !$this->tasks->takeOver($task->id, $user->id, $claimedFrom)) {
                throw new ConflictException('Too slow -- somebody else just took that one.');
            }
            $this->history->log(
                $task->id,
                TaskEvent::Reassigned,
                $claimedFrom,
                $user->id,
                TaskHistoryRepository::NOTE_CLAIMED
            );
        }

        $this->tasks->markDone($task->id);
        $this->history->log($task->id, TaskEvent::Completed, null, $user->id);

        // Losing points to someone else is the whole tension of the game, so it shouldn't happen
        // silently.
        if ($claimedFrom !== null) {
            $this->notifications->announceTaskClaimed($claimedFrom, $user->id, $user->username, $task);
        }

        // A completion can be exactly what finishes the period early -- check now rather than
        // waiting for the next request's sweep.
        $this->periods->maybeFinishEarly($groupId, $task->period());

        return ['claimed_from' => $claimedFrom];
    }

    /**
     * Un-ticking, which is only ever something you can do to a task you hold. If you had taken it
     * off someone else, un-ticking gives it back rather than leaving you sitting on their task.
     *
     * @return array{returned_to: ?int}
     */
    public function reopen(User $user, GroupMembership $membership, InputBag $input): array
    {
        $groupId = $membership->group->id;
        $task = $this->tasks->find($input->int('task_id'), $groupId);
        if ($task === null) {
            throw new NotFoundException('Task not found.');
        }
        if (!$task->isHeldBy($user->id)) {
            throw new AuthorizationException("That task isn't yours to un-tick.");
        }

        $returnedTo = $this->assignment->undoClaim($task->id, $user->id, $groupId);
        if ($returnedTo === null) {
            $this->tasks->reopen($task->id);
            $this->history->log($task->id, TaskEvent::Reopened, null, $user->id);
        }

        return ['returned_to' => $returnedTo];
    }
}
