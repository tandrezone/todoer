<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ClaimDenial;
use App\Domain\Enum\ListType;
use App\Domain\Enum\Priority;
use App\Domain\Enum\TaskStatus;
use App\Domain\Period\Period;

/**
 * A task, with the rules that only depend on the task itself.
 *
 * Deadlines, "is this up for grabs", and the list ordering used to be free functions taking an
 * associative array, which meant every caller had to remember that `time_limit_minutes` is
 * ignored for HIGH priority and that `window_end` and the timer both bite. Those rules live here
 * now: one place, typed, and exercised by the tests.
 *
 * The object is immutable. Changes go through TaskRepository, which is also the only thing that
 * knows the table layout.
 */
final class Task
{
    public const MAX_TITLE_LENGTH = 200;

    public function __construct(
        public readonly int $id,
        public readonly int $groupId,
        public readonly ?int $userId,
        public readonly int $createdBy,
        public readonly ListType $listType,
        public readonly string $periodKey,
        public readonly string $title,
        public readonly int $points,
        public readonly TaskStatus $status,
        public readonly ?string $windowStart,
        public readonly ?string $windowEnd,
        public readonly AssignmentType $assignmentType,
        public readonly ?int $assignedUserId,
        public readonly Priority $priority,
        public readonly ?int $timeLimitMinutes,
        public readonly ?string $assignedAt,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $holderUsername = null,
        public readonly ?string $holderColor = null,
        /** Which 1/occurrenceCount slice of the period this row is. 1-based; 1 when times-per-period is just 1. */
        public readonly int $occurrenceIndex = 1,
        /** How many equal slices this task's periodicity was divided into when it was created/edited. */
        public readonly int $occurrenceCount = 1
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['group_id'],
            isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            (int) $row['created_by'],
            ListType::from((string) $row['list_type']),
            (string) $row['period_key'],
            (string) $row['title'],
            (int) $row['points'],
            TaskStatus::from((string) $row['status']),
            isset($row['window_start']) ? self::nullableString($row['window_start']) : null,
            isset($row['window_end']) ? self::nullableString($row['window_end']) : null,
            AssignmentType::from((string) $row['assigned_type']),
            isset($row['assigned_user_id']) && $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
            Priority::from((string) $row['priority']),
            isset($row['time_limit_minutes']) && $row['time_limit_minutes'] !== null ? (int) $row['time_limit_minutes'] : null,
            isset($row['assigned_at']) ? self::nullableString($row['assigned_at']) : null,
            isset($row['created_at']) ? self::nullableString($row['created_at']) : null,
            isset($row['completed_at']) ? self::nullableString($row['completed_at']) : null,
            isset($row['holder_username']) ? self::nullableString($row['holder_username']) : null,
            isset($row['holder_color']) ? self::nullableString($row['holder_color']) : null,
            isset($row['occurrence_index']) ? (int) $row['occurrence_index'] : 1,
            isset($row['occurrence_count']) ? (int) $row['occurrence_count'] : 1
        );
    }

    public function period(): Period
    {
        return new Period($this->listType, $this->periodKey);
    }

    /**
     * The completion timer that actually applies: the HIGH-priority dynamic limit for HIGH tasks
     * (ignoring whatever was configured), otherwise the task's own limit, which may be null
     * meaning "no timer, only the window applies".
     */
    public function effectiveTimeLimitMinutes(): ?int
    {
        if ($this->priority->usesDynamicTimeLimit()) {
            return Priority::HIGH_PRIORITY_TIME_LIMIT_MINUTES;
        }

        return $this->timeLimitMinutes;
    }

    /**
     * When this task becomes overdue for its *current* holder: the earlier of window_end and
     * assigned_at + the effective time limit, or null if neither constraint applies.
     */
    public function deadline(): ?string
    {
        $candidates = [];
        if ($this->windowEnd !== null && $this->windowEnd !== '') {
            $candidates[] = $this->windowEnd;
        }
        if ($this->assignedAt !== null && $this->assignedAt !== '') {
            $limit = $this->effectiveTimeLimitMinutes();
            $assignedAt = strtotime($this->assignedAt);
            if ($limit !== null && $assignedAt !== false) {
                $candidates[] = date('Y-m-d H:i:s', $assignedAt + $limit * 60);
            }
        }
        if ($candidates === []) {
            return null;
        }
        sort($candidates);

        return $candidates[0];
    }

    public function isOverdueAt(int $now): bool
    {
        $deadline = $this->deadline();
        if ($deadline === null) {
            return false;
        }
        $timestamp = strtotime($deadline);

        return $timestamp !== false && $timestamp <= $now;
    }

    public function isHeldBy(int $userId): bool
    {
        return $this->userId === $userId;
    }

    /** The holder may edit, and so may whoever added it -- the same rule the delete path uses. */
    public function isEditableBy(int $userId): bool
    {
        return $this->isHeldBy($userId) || $this->createdBy === $userId;
    }

    /**
     * The "steal" rule of game mode: an open task in the shared pool is up for grabs while its
     * window is live, so if the holder hasn't ticked it off, anyone else in the group can do it
     * instead and bank the points.
     */
    public function claimStateFor(int $viewerId, int $now): ClaimDecision
    {
        if ($this->isHeldBy($viewerId)) {
            return ClaimDecision::denied(ClaimDenial::Own);
        }
        if ($this->status !== TaskStatus::Open || $this->userId === null) {
            return ClaimDecision::denied(ClaimDenial::NotOpen);
        }
        // A locked task was deliberately pinned to one person; taking it would defeat the point
        // of locking it, however overdue it gets.
        if ($this->assignmentType->isLocked()) {
            return ClaimDecision::denied(ClaimDenial::Locked);
        }
        if ($this->windowStart !== null && $this->windowStart !== '') {
            $start = strtotime($this->windowStart);
            if ($start !== false && $start > $now) {
                return ClaimDecision::denied(ClaimDenial::NotOpenYet);
            }
        }
        if ($this->isOverdueAt($now)) {
            return ClaimDecision::denied(ClaimDenial::WindowClosed);
        }

        return ClaimDecision::allowed();
    }

    /** Adds the per-viewer flags the dashboard needs on top of the task itself. */
    public function forViewer(int $viewerId, bool $gameRunning, int $now): TaskView
    {
        return TaskView::build($this, $viewerId, $gameRunning, $now);
    }

    /**
     * Sorting for a task list: window_start ascending with "no window" last, then
     * HIGH > MODERATE > LOW.
     *
     * @param  list<self> $tasks
     * @return list<self>
     */
    public static function sortForView(array $tasks): array
    {
        usort($tasks, static function (self $a, self $b): int {
            if ($a->windowStart !== $b->windowStart) {
                if ($a->windowStart === null) {
                    return 1;
                }
                if ($b->windowStart === null) {
                    return -1;
                }
                $comparison = strcmp($a->windowStart, $b->windowStart);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $a->priority->rank() <=> $b->priority->rank();
        });

        return array_values($tasks);
    }

    /**
     * The row shape the front-end reads. Deliberately the same key names the original API
     * returned, so this refactor is invisible to assets/js/app.js.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->groupId,
            'user_id' => $this->userId,
            'created_by' => $this->createdBy,
            'list_type' => $this->listType->value,
            'period_key' => $this->periodKey,
            'title' => $this->title,
            'points' => $this->points,
            'status' => $this->status->value,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'assigned_type' => $this->assignmentType->value,
            'assigned_user_id' => $this->assignedUserId,
            'priority' => $this->priority->value,
            'time_limit_minutes' => $this->timeLimitMinutes,
            'assigned_at' => $this->assignedAt,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
            'holder_username' => $this->holderUsername,
            'holder_color' => $this->holderColor,
            'occurrence_index' => $this->occurrenceIndex,
            'occurrence_count' => $this->occurrenceCount,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
