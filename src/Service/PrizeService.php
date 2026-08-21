<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use App\Exception\ValidationException;
use App\Repository\AwardRepository;

/**
 * The prize history, and claiming a prize you have won.
 *
 * The history is the group's own and nothing else: another group's winners never appear here, even
 * though the pool of prize descriptions is shared installation-wide.
 */
final class PrizeService
{
    public function __construct(private readonly AwardRepository $awards)
    {
    }

    /** @return array<string, mixed> */
    public function history(User $user, GroupMembership $membership): array
    {
        $awards = $this->awards->forGroup($membership->group->id);

        return [
            'ok' => true,
            'group' => ['id' => $membership->group->id, 'name' => $membership->group->name],
            'awards' => array_map(static fn($award): array => $award->toArray($user->id), $awards),
        ];
    }

    /** A winner marking their own prize as redeemed in real life. */
    public function claim(User $user, GroupMembership $membership, int $awardId): void
    {
        if (!$this->awards->markClaimed($awardId, $membership->group->id, $user->id)) {
            throw new ValidationException('That prize is not yours to claim.');
        }
    }
}
