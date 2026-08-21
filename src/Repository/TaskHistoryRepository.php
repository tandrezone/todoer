<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\TaskEvent;
use App\Support\Clock;
use PDO;

/**
 * The append-only assignment trail.
 *
 * It is not just an audit log: undoing a claim reads the previous holder back out of the most
 * recent "claimed by another player" event, because the tasks table has no previous-holder column.
 * That is why the note strings here are matched exactly rather than treated as free text.
 */
final class TaskHistoryRepository
{
    public const NOTE_CLAIMED = 'claimed by another player';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock
    ) {
    }

    public function log(
        int $taskId,
        TaskEvent $event,
        ?int $fromUserId = null,
        ?int $toUserId = null,
        ?string $note = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO task_history (task_id, event, from_user_id, to_user_id, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$taskId, $event->value, $fromUserId, $toUserId, $note, $this->clock->sqlNow()]);
    }

    /**
     * The most recent take-over of this task.
     *
     * @return array{from_user_id: ?int, to_user_id: ?int}|null
     */
    public function lastClaim(int $taskId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT from_user_id, to_user_id FROM task_history
             WHERE task_id = ? AND event = ? AND note = ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$taskId, TaskEvent::Reassigned->value, self::NOTE_CLAIMED]);
        $row = $statement->fetch();

        return $row === false ? null : [
            'from_user_id' => $row['from_user_id'] === null ? null : (int) $row['from_user_id'],
            'to_user_id' => $row['to_user_id'] === null ? null : (int) $row['to_user_id'],
        ];
    }
}
