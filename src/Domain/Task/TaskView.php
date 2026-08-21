<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Domain\Enum\ClaimDenial;
use App\Domain\Enum\TaskStatus;

/**
 * A task as one particular viewer sees it.
 *
 * The flags are computed server-side on purpose: the checkbox the client renders and the rule the
 * API enforces come from the same decision, so they cannot drift apart.
 */
final class TaskView
{
    private function __construct(
        public readonly Task $task,
        public readonly bool $isMine,
        public readonly ClaimDecision $claim,
        public readonly bool $canComplete,
        public readonly bool $canEdit
    ) {
    }

    public static function build(Task $task, int $viewerId, bool $gameRunning, int $now): self
    {
        $isMine = $task->isHeldBy($viewerId);

        // Taking over someone else's task is a game-mode move. While a list is stopped it is
        // being planned and reshuffled, not played, so nothing is up for grabs.
        $claim = $gameRunning
            ? $task->claimStateFor($viewerId, $now)
            : ClaimDecision::denied($isMine ? ClaimDenial::Own : ClaimDenial::NotRunning);

        $canComplete = ($isMine && in_array($task->status, [TaskStatus::Open, TaskStatus::Done], true))
            || $claim->claimable;

        return new self($task, $isMine, $claim, $canComplete, $task->isEditableBy($viewerId));
    }

    public function status(): TaskStatus
    {
        return $this->task->status;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->task->toArray() + [
            'is_mine' => $this->isMine,
            'claimable' => $this->claim->claimable,
            'claim_reason' => $this->claim->reasonCode(),
            'can_complete' => $this->canComplete,
            'can_edit' => $this->canEdit,
        ];
    }

    /**
     * @param  list<self> $views
     * @return list<self>
     */
    public static function sortForView(array $views): array
    {
        $byId = [];
        foreach ($views as $view) {
            $byId[$view->task->id] = $view;
        }
        $sorted = Task::sortForView(array_map(static fn(self $view): Task => $view->task, $views));

        return array_values(array_map(static fn(Task $task): self => $byId[$task->id], $sorted));
    }
}
