<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use App\Exception\ValidationException;
use App\Http\Input\InputBag;
use App\Http\RequestAttribute;
use App\Http\Responder;
use App\Service\GroupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Group management: who is in the group, and (for admins) who gets added or removed.
 *
 * The group acted on is always the caller's own, resolved from their session membership -- no group
 * id is ever accepted from the client, so there is no request that can read or reshape somebody
 * else's group. Mutating actions additionally require admin, which the service enforces by throwing;
 * the error middleware turns that into a 403.
 */
final class GroupApiController implements RequestHandlerInterface
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly Responder $responder
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequestAttribute::user($request);
        $membership = RequestAttribute::membership($request);

        if ($request->getMethod() !== 'POST') {
            return $this->responder->json($this->payload($user, $membership));
        }

        $input = InputBag::fromBody($request);
        $message = match ($input->string('action')) {
            'add_member' => $this->groups
                ->addMemberByUsername($membership, $user->id, $input->string('username'))['username']
                . ' is now in the group.',
            'create_member' => 'Created an account for '
                . $this->groups->createMemberAccount(
                    $membership,
                    $input->string('username'),
                    $input->string('password')
                )['username']
                . '. Share the password with them.',
            'remove_member' => $this->run(
                fn() => $this->groups->removeMember($membership, $user->id, $input->int('user_id')),
                'Removed from the group. They keep their account and a private list of their own.'
            ),
            'set_role' => $this->run(
                fn() => $this->groups->setMemberRole(
                    $membership,
                    $user->id,
                    $input->int('user_id'),
                    $input->string('role', 'member')
                ),
                'Role updated.'
            ),
            'rename' => 'Renamed to "' . $this->groups->rename($membership, $input->string('name')) . '".',
            'regenerate_code' => $this->run(
                fn() => $this->groups->regenerateInviteCode($membership),
                'New invite code generated. The old one no longer works.'
            ),
            'join' => 'You joined "'
                . $this->groups->joinByInviteCode($user->id, $input->string('invite_code'))['name'] . '".',
            'leave' => $this->run(
                fn() => $this->groups->leaveGroup($membership, $user->id),
                'You left the group and now have a private list of your own.'
            ),
            default => throw new ValidationException('Unknown action.'),
        };

        // Re-resolve after the change: joining or leaving means the caller's group is now a
        // different one, so the response has to describe where they actually ended up.
        $current = $this->groups->requireGroupFor($user);

        return $this->responder->json($this->payload($user, $current) + ['message' => $message]);
    }

    /**
     * The whole group view the page renders from.
     *
     * @return array<string, mixed>
     */
    private function payload(User $user, GroupMembership $membership): array
    {
        $members = $this->groups->members($membership->group->id);

        return [
            'ok' => true,
            'group' => [
                'id' => $membership->group->id,
                'name' => $membership->group->name,
                'role' => $membership->role->value,
                'is_admin' => $membership->isAdmin(),
                // Only an admin needs the invite code, and only an admin should be able to hand out
                // access to the group's tasks -- so members never receive it.
                'invite_code' => $membership->isAdmin() ? $membership->group->inviteCode : null,
                'created_at' => $membership->group->createdAt,
            ],
            'members' => array_map(
                static fn($member): array => $member->toArray($user->id),
                $members
            ),
        ];
    }

    /** @param callable(): void $action */
    private function run(callable $action, string $message): string
    {
        $action();

        return $message;
    }
}
