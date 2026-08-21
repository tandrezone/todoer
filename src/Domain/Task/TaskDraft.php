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
        public readonly ?string $windowEnd
    ) {
    }

    /** True when this draft points the task at somebody different than the given task does. */
    public function changesAssignment(Task $task): bool
    {
        return $this->assignmentType !== $task->assignmentType
            || $this->assignedUserId !== $task->assignedUserId;
    }
}
