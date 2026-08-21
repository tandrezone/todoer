<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Group\GroupMembership;
use App\Repository\TaskRepository;
use App\Support\Clock;

/**
 * Exports a group's tasks as a portable JSON file.
 *
 * Holders and assignees are written as usernames rather than ids: an id only means something in the
 * database it came from, so it would be useless -- or actively misleading -- when the file is
 * imported into another installation. A NULL holder stays NULL.
 */
final class TaskExportService
{
    public const FORMAT = 'todoer-tasks';

    public const VERSION = 1;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly Clock $clock
    ) {
    }

    /** @return array{filename: string, payload: array<string, mixed>} */
    public function export(GroupMembership $membership): array
    {
        $rows = $this->tasks->exportRows($membership->group->id);

        $tasks = array_map(static fn(array $row): array => [
            'list_type' => $row['list_type'],
            'period_key' => $row['period_key'],
            'title' => $row['title'],
            'points' => (int) $row['points'],
            'status' => $row['status'],
            'window_start' => $row['window_start'],
            'window_end' => $row['window_end'],
            'assigned_type' => $row['assigned_type'],
            'priority' => $row['priority'],
            'time_limit_minutes' => $row['time_limit_minutes'] !== null ? (int) $row['time_limit_minutes'] : null,
            'occurrence_index' => isset($row['occurrence_index']) ? (int) $row['occurrence_index'] : 1,
            'occurrence_count' => isset($row['occurrence_count']) ? (int) $row['occurrence_count'] : 1,
            'assigned_at' => $row['assigned_at'],
            'created_at' => $row['created_at'],
            'completed_at' => $row['completed_at'],
            'created_by' => $row['created_by_username'],
            'user' => $row['user_username'],
            'assigned_user' => $row['assigned_user_username'],
        ], $rows);

        $now = $this->clock->now();
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $membership->group->name) ?? 'group';

        return [
            'filename' => 'todoer-tasks-' . $slug . '-' . $now->format('Ymd-His') . '.json',
            'payload' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'exported_at' => $now->format('c'),
                'group' => $membership->group->name,
                'tasks' => $tasks,
            ],
        ];
    }
}
