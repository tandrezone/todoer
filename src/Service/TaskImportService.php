<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionManager;
use App\Domain\Enum\AssignmentType;
use App\Domain\Enum\ListType;
use App\Domain\Enum\Priority;
use App\Domain\Enum\TaskStatus;
use App\Domain\Group\GroupMembership;
use App\Domain\Task\Task;
use App\Domain\User\User;
use App\Exception\ValidationException;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Support\Clock;

/**
 * Bringing tasks in from outside: picked Google Keep candidates, and a previously exported file.
 *
 * Both paths land tasks in the *caller's* group and resolve every name against that group's members
 * only, so a file from another installation cannot introduce, or point a task at, somebody who is
 * not here. Rows that do not validate are skipped and counted rather than aborting the import: a
 * restore that drops three unrecognised rows out of two hundred is far more useful than one that
 * refuses the file.
 */
final class TaskImportService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly UserRepository $users,
        private readonly AssignmentService $assignment,
        private readonly PeriodService $periods,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock
    ) {
    }

    /**
     * Commits picked Keep candidates as real tasks.
     *
     * They land in the shared pool at MODERATE priority with no window or timer -- the same defaults
     * a task typed into the add form gets.
     *
     * @param  list<mixed> $items
     * @return int Number of tasks created.
     */
    public function commitCandidates(User $user, GroupMembership $membership, array $items): int
    {
        if ($items === []) {
            throw new ValidationException('Nothing selected to import.');
        }

        $groupId = $membership->group->id;
        $createdIds = $this->transactions->transactional(function () use ($items, $groupId, $user): array {
            $createdIds = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $listType = ListType::tryFrom((string) ($item['list_type'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                if ($listType === null || $title === '') {
                    continue;
                }

                $createdIds[] = $this->tasks->insertRestored([
                    'group_id' => $groupId,
                    'user_id' => null,
                    'created_by' => $user->id,
                    'list_type' => $listType->value,
                    'period_key' => $this->periods->currentPeriod($listType)->key,
                    'title' => mb_substr($title, 0, Task::MAX_TITLE_LENGTH),
                    'points' => $listType->points(),
                    'status' => TaskStatus::Unassigned->value,
                    'window_start' => null,
                    'window_end' => null,
                    'assigned_type' => AssignmentType::AnyUser->value,
                    'assigned_user_id' => null,
                    'priority' => Priority::Moderate->value,
                    'time_limit_minutes' => null,
                    'occurrence_index' => 1,
                    'occurrence_count' => 1,
                    'assigned_at' => null,
                    'created_at' => $this->clock->sqlNow(),
                    'completed_at' => null,
                ]);
            }

            return $createdIds;
        });

        $this->assignFreshTasks($createdIds, $groupId);

        return count($createdIds);
    }

    /**
     * Restores tasks from a file produced by the export.
     *
     * The original creator falls back to whoever is doing the import when that account is not in
     * this group; the holder and the designated assignee simply go unset rather than being guessed,
     * because putting a task on the wrong person is worse than leaving it in the pool.
     *
     * @param  array<string, mixed> $data
     * @return array{created: int, skipped: int}
     */
    public function restore(User $user, GroupMembership $membership, array $data): array
    {
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            throw new ValidationException('That file does not look like a Todoer tasks export.');
        }

        $groupId = $membership->group->id;
        $memberIds = $this->users->idsByUsername($groupId);
        $skipped = 0;

        $createdIds = $this->transactions->transactional(function () use ($data, $groupId, $user, $memberIds, &$skipped): array {
            $createdIds = [];

            foreach ($data['tasks'] as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }

                $listType = ListType::tryFrom((string) ($item['list_type'] ?? ''));
                $title = trim((string) ($item['title'] ?? ''));
                $status = TaskStatus::tryFrom((string) ($item['status'] ?? TaskStatus::Unassigned->value));
                $assignmentType = AssignmentType::tryFrom((string) ($item['assigned_type'] ?? AssignmentType::AnyUser->value));
                $priority = Priority::tryFrom((string) ($item['priority'] ?? Priority::Moderate->value));

                if ($listType === null || $title === '' || $status === null || $assignmentType === null || $priority === null) {
                    $skipped++;
                    continue;
                }

                $periodKey = trim((string) ($item['period_key'] ?? ''));
                if ($periodKey === '') {
                    $periodKey = $this->periods->currentPeriod($listType)->key;
                }

                // Older exports predate "times per period" and simply have neither field -- those
                // rows round-trip as a single occurrence, exactly what they already were.
                $occurrenceCount = max(1, (int) ($item['occurrence_count'] ?? 1));
                $occurrenceIndex = max(1, min($occurrenceCount, (int) ($item['occurrence_index'] ?? 1)));

                $createdIds[] = $this->tasks->insertRestored([
                    'group_id' => $groupId,
                    'user_id' => $memberIds[(string) ($item['user'] ?? '')] ?? null,
                    'created_by' => $memberIds[(string) ($item['created_by'] ?? '')] ?? $user->id,
                    'list_type' => $listType->value,
                    'period_key' => $periodKey,
                    'title' => mb_substr($title, 0, Task::MAX_TITLE_LENGTH),
                    'points' => isset($item['points']) ? (int) $item['points'] : $listType->points(),
                    'status' => $status->value,
                    'window_start' => $this->nullableString($item['window_start'] ?? null),
                    'window_end' => $this->nullableString($item['window_end'] ?? null),
                    'assigned_type' => $assignmentType->value,
                    'assigned_user_id' => $memberIds[(string) ($item['assigned_user'] ?? '')] ?? null,
                    'priority' => $priority->value,
                    'time_limit_minutes' => isset($item['time_limit_minutes']) && $item['time_limit_minutes'] !== null
                        ? (int) $item['time_limit_minutes']
                        : null,
                    'occurrence_index' => $occurrenceIndex,
                    'occurrence_count' => $occurrenceCount,
                    'assigned_at' => $this->nullableString($item['assigned_at'] ?? null),
                    'created_at' => $this->nullableString($item['created_at'] ?? null) ?? $this->clock->sqlNow(),
                    'completed_at' => $this->nullableString($item['completed_at'] ?? null),
                ]);
            }

            return $createdIds;
        });

        $this->assignFreshTasks($createdIds, $groupId);

        return ['created' => count($createdIds), 'skipped' => $skipped];
    }

    /**
     * Picks up any period whose game is already running, rather than stranding freshly imported
     * unassigned tasks until the next manual Start. Done after the transaction commits so the
     * assignment sees the rows.
     *
     * @param list<int> $taskIds
     */
    private function assignFreshTasks(array $taskIds, int $groupId): void
    {
        foreach ($taskIds as $taskId) {
            $this->assignment->assignNewTaskIfRunning($taskId, $groupId);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
