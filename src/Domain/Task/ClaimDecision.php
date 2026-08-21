<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Domain\Enum\ClaimDenial;

/** Whether a viewer may take over (and take the points for) a task, and if not, why not. */
final class ClaimDecision
{
    private function __construct(
        public readonly bool $claimable,
        public readonly ?ClaimDenial $denial
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function denied(ClaimDenial $reason): self
    {
        return new self(false, $reason);
    }

    /** The machine code the API exposes ('' when the task is claimable). */
    public function reasonCode(): string
    {
        return $this->denial?->value ?? '';
    }

    public function message(): string
    {
        return $this->denial?->message() ?? "You can't do that task right now.";
    }
}
