<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ListType;
use App\Domain\Enum\Priority;
use App\Domain\Period\Period;

/**
 * Validated, typed input for creating or editing a task.
 *
 * Everything the client is allowed to influence about a task ends up in one of these, and nothing
 * else is ever written to a task row -- so "what can a request change?" has a single, readable
 * answer. Building one from request data (and rejecting a bad list type, a whitespace title, a
 * window that ends before it starts, a per-task timer on a HIGH priority task) is
 * App\Service\TaskDraftFactory's job: the domain object itself knows nothing about HTTP.
 */
final class TaskDraft
{
    public function __construct(
        public readonly ListType $listType,
        public readonly Period $period,
        public readonly string $title,
        public readonly AssignmentType $assignmentType,
        public readonly ?int $assignedUserId,
        public readonly Priority $priority,
        public readonly ?int $timeLimitMinutes,
        public readonly ?string $windowStart,
        public readonly ?string $windowEnd,
        /**
         * How many equal slices this task's periodicity is divided into ("times per period"). 1
         * means the ordinary single-window task; N > 1 means N occurrences, each live only in its
         * own 1/N slice of the period (see App\Domain\Period\Period::divideIntoWindows()).
         */
        public readonly int $occurrenceCount = 1,
        /** Which of those $occurrenceCount slices this particular draft/row is. 1-based. */
        public readonly int $occurrenceIndex = 1
    ) {
    }

    /** True when this draft points the task at somebody different than the given task does. */
    public function changesAssignment(Task $task): bool
    {
        return $this->assignmentType !== $task->assignmentType
            || $this->assignedUserId !== $task->assignedUserId;
    }

    /**
     * An immutable copy carrying one resolved occurrence's window and slot number -- what
     * App\Service\TaskService builds, one per slice from Period::divideIntoWindows(), before
     * handing each off to the repository.
     */
    public function withWindow(?string $windowStart, ?string $windowEnd, int $occurrenceIndex): self
    {
        return new self(
            $this->listType,
            $this->period,
            $this->title,
            $this->assignmentType,
            $this->assignedUserId,
            $this->priority,
            $this->timeLimitMinutes,
            $windowStart,
            $windowEnd,
            $this->occurrenceCount,
            $occurrenceIndex
        );
    }
}
