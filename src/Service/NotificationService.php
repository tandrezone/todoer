<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Notification\Notification;
use App\Domain\Period\Period;
use App\Domain\Task\Task;
use App\Repository\GroupRepository;
use App\Repository\NotificationRepository;
use App\Support\Clock;

/**
 * In-app notifications, with a matching push where the recipient has enabled it.
 *
 * Delivery is exactly-once per event, enforced by the UNIQUE(user_id, event_key) index rather than
 * by remembering to check: insertOnce() reports whether a row was actually created and only then is
 * a push sent, so a sweep that runs on every page load does not re-ping anybody's phone.
 *
 * Every announcement is addressed to one group's members or to one person -- never installation
 * wide. A stray notification would leak both another group's existence and its activity.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly GroupRepository $groups,
        private readonly PushService $push,
        private readonly Clock $clock
    ) {
    }

    public function notifyUser(int $userId, string $eventKey, string $title, string $body): void
    {
        if ($this->notifications->insertOnce($userId, $eventKey, $title, $body)) {
            $this->push->send($userId, $title, $body);
        }
    }

    public function notifyGroup(int $groupId, string $eventKey, string $title, string $body): void
    {
        foreach ($this->groups->members($groupId) as $member) {
            $this->notifyUser($member->user->id, $eventKey, $title, $body);
        }
    }

    /** @return list<Notification> */
    public function unreadFor(int $userId): array
    {
        return $this->notifications->unreadFor($userId);
    }

    /** @param list<int> $ids */
    public function markRead(int $userId, array $ids): void
    {
        $this->notifications->markRead($userId, $ids);
    }

    public function announceGameStarted(int $groupId, Period $period): void
    {
        $this->notifyGroup(
            $groupId,
            sprintf('game-started:%d:%s:%s', $groupId, $period->listType->value, $period->key),
            $period->listType->title() . ' game started',
            'Tasks have been assigned. Good luck!'
        );
    }

    /**
     * "Someone else did your task."
     *
     * Fires when a player takes over an open task and completes it, moving its points to them -- so
     * the person who lost the task hears it from the app rather than noticing the scoreboard shift
     * later. The event key includes the task, the claimer and a timestamp, so the same task changing
     * hands twice notifies twice: the uniqueness guard exists to stop repeats of one event, not to
     * collapse genuinely separate ones.
     */
    public function announceTaskClaimed(int $holderId, int $claimerId, string $claimerName, Task $task): void
    {
        if ($holderId === $claimerId) {
            return;
        }

        $points = $task->points;
        $this->notifyUser(
            $holderId,
            sprintf('task-claimed:%d:%d:%s', $task->id, $claimerId, $this->clock->now()->format('YmdHis')),
            $claimerName . ' did your task',
            sprintf(
                '%s got to "%s" before you did and took the %d point%s.',
                $claimerName,
                $task->title,
                $points,
                $points === 1 ? '' : 's'
            )
        );
    }

    /** Warns the current holder once, when 90% of a task's effective time window has elapsed. */
    public function announceDeadlineApproaching(Task $task, string $deadline): void
    {
        if ($task->userId === null) {
            return;
        }

        $this->notifyUser(
            $task->userId,
            sprintf('task-window-warning:%d:%s:%s', $task->id, (string) $task->assignedAt, $deadline),
            'Task deadline approaching',
            'Only 10% of the time window remains for "' . $task->title . '".'
        );
    }
}
