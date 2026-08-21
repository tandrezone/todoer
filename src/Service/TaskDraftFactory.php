<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ListType;
use App\Domain\Enum\Priority;
use App\Domain\Period\Period;
use App\Domain\Task\Task;
use App\Domain\Task\TaskDraft;
use App\Exception\ValidationException;
use App\Http\Input\InputBag;

/**
 * Turns request input into a validated TaskDraft.
 *
 * This is the only place a request field becomes part of a task, which is what makes the rules
 * auditable: the title is trimmed and clamped, an unknown priority falls back to MODERATE, a HIGH
 * priority task's submitted timer is dropped (rather than stored and silently overridden later),
 * and a window that ends before it starts is refused.
 *
 * It sits in the service layer on purpose. The draft itself is a domain object and knows nothing
 * about HTTP; the conversion does.
 */
final class TaskDraftFactory
{
    /** A sane ceiling on "how many times per period" -- enough for any real chore, not enough to flood a list. */
    public const MAX_TIMES_PER_PERIOD = 24;

    /**
     * Builds a draft for a given list period.
     *
     * The window fields are named per list type because each list captures its window in the grain
     * that fits its cadence -- a time of day for a daily task, a weekday for a weekly one, a day of
     * the month for a monthly one -- rather than a full date that would just repeat the period.
     *
     * `times_per_period` is how many equal occurrences that window (or, if left blank, the whole
     * period) is divided into -- see App\Domain\Period\Period::divideIntoWindows(). It defaults to
     * 1, the ordinary single-window task, so this is opt-in and never changes existing behaviour.
     * `occurrence_index` only matters when *editing* one row out of an existing N: it says which
     * slice this particular row should become; on add it is ignored, since App\Service\TaskService
     * generates one row per slice itself.
     */
    public function fromInput(InputBag $input, Period $period): TaskDraft
    {
        $title = $input->requiredString('title', 'Task title cannot be empty.');
        if (mb_strlen($title) > Task::MAX_TITLE_LENGTH) {
            $title = mb_substr($title, 0, Task::MAX_TITLE_LENGTH);
        }

        $assignmentType = $input->string('assigned_type') === AssignmentType::SpecificUser->value
            ? AssignmentType::SpecificUser
            : AssignmentType::AnyUser;

        $assignedUserId = null;
        if ($assignmentType->isLocked()) {
            $assignedUserId = $input->int('assigned_user_id');
            if ($assignedUserId <= 0) {
                throw new ValidationException('Choose someone from your group to lock this task to.');
            }
        }

        $priority = Priority::tryFrom($input->string('priority')) ?? Priority::Moderate;

        $timeLimitMinutes = null;
        if (!$priority->usesDynamicTimeLimit() && $input->filled('time_limit_minutes')) {
            $timeLimitMinutes = max(1, $input->int('time_limit_minutes'));
        }

        [$startField, $endField] = match ($period->listType) {
            ListType::Daily => ['window_start_time', 'window_end_time'],
            ListType::Weekly => ['window_start_day', 'window_end_day'],
            ListType::Monthly => ['window_start_dom', 'window_end_dom'],
        };

        $windowStart = $period->resolveWindow($input->string($startField), false);
        $windowEnd = $period->resolveWindow($input->string($endField), true);
        if ($windowStart !== null && $windowEnd !== null && $windowStart > $windowEnd) {
            throw new ValidationException('Window start must be before window end.');
        }

        $occurrenceCount = max(1, min(self::MAX_TIMES_PER_PERIOD, $input->int('times_per_period', 1)));
        $occurrenceIndex = max(1, min($occurrenceCount, $input->int('occurrence_index', 1)));

        return new TaskDraft(
            $period->listType,
            $period,
            $title,
            $assignmentType,
            $assignedUserId,
            $priority,
            $timeLimitMinutes,
            $windowStart,
            $windowEnd,
            $occurrenceCount,
            $occurrenceIndex
        );
    }
}
