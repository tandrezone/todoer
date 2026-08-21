<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\InviteCodeGenerator;
use App\Database\TransactionManager;
use App\Domain\Enum\GroupRole;
use App\Domain\Group\Group;
use App\Domain\Group\GroupMember;
use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use App\Exception\AuthorizationException;
use App\Exception\ValidationException;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use PDO;

/**
 * Groups: the privacy and competition boundary.
 *
 * A group answers "whose tasks can I see, and who am I competing against". The rules it enforces:
 *   * exactly one group per user -- there is no group-less state for the rest of the app to handle
 *   * registering creates a personal group with that user as its admin
 *   * an admin can add people (an existing account by username, or a new account they create) and
 *     remove them again; anyone removed or leaving lands in a fresh personal group, taking no
 *     tasks, points or prizes with them, because those belong to the competition they were earned in
 *   * anyone can join a group they have the invite code for
 *   * a group always keeps at least one admin
 */
final class GroupService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GroupRepository $groups,
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * The caller's group, creating a personal one if they somehow have none (an account made
     * before groups existed that missed the migration, or a group deleted underneath them).
     *
     * Every request path goes through this, so "logged in but group-less" can never reach a query.
     */
    public function requireGroupFor(User $user): GroupMembership
    {
        $membership = $this->groups->membershipFor($user->id);
        if ($membership !== null) {
            return $membership;
        }

        $this->createGroupFor($user->id, $this->defaultGroupName($user->id, $user->username));
        $membership = $this->groups->membershipFor($user->id);
        if ($membership === null) {
            throw new ValidationException('Could not set up a group for this account.');
        }

        return $membership;
    }

    public function membershipFor(int $userId): ?GroupMembership
    {
        return $this->groups->membershipFor($userId);
    }

    /** Creates a group with this user as its admin, moving them out of whatever group they were in. */
    public function createGroupFor(int $userId, string $name): int
    {
        return $this->transactions->transactional(function () use ($userId, $name): int {
            $groupId = $this->groups->create(
                Group::cleanName($name),
                InviteCodeGenerator::generate($this->pdo),
                $userId
            );
            $this->groups->placeUserInGroup($userId, $groupId, GroupRole::Admin);

            return $groupId;
        });
    }

    public function defaultGroupName(int $userId, ?string $username = null): string
    {
        $username ??= $this->groups->username($userId) ?? 'My';

        return mb_substr($username . "'s group", 0, Group::MAX_NAME_LENGTH);
    }

    public function findByInviteCode(string $code): ?Group
    {
        return $this->groups->findByInviteCode($code);
    }

    /** @return list<GroupMember> */
    public function members(int $groupId): array
    {
        return $this->groups->members($groupId);
    }

    /** @return list<User> */
    public function activeMembers(int $groupId): array
    {
        return $this->groups->activeMembers($groupId);
    }

    public function isMember(int $groupId, int $userId): bool
    {
        return $this->groups->isMember($groupId, $userId);
    }

    /** The check that gates every cross-user reference, e.g. locking a task to a person. */
    public function requireMember(int $groupId, int $userId, string $message): void
    {
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new ValidationException($message);
        }
    }

    public function placeUserInGroup(int $userId, int $groupId, GroupRole $role = GroupRole::Member): void
    {
        $this->groups->placeUserInGroup($userId, $groupId, $role);
    }

    /**
     * Admin action: pull an existing account into this group by username.
     *
     * They leave their previous group; if that leaves it without an admin, the longest-standing
     * remaining member is promoted so nobody is stranded in an unmanageable group.
     *
     * @return array{user_id: int, username: string}
     */
    public function addMemberByUsername(GroupMembership $membership, string $username): array
    {
        $membership->requireAdmin();
        $username = trim($username);
        if ($username === '') {
            throw new ValidationException('Enter the username of the person to add.');
        }

        $target = $this->users->findByUsername($username);
        if ($target === null) {
            throw new ValidationException('No account with that username. Create one for them instead.');
        }
        $groupId = $membership->group->id;
        if ($this->groups->isMember($groupId, $target->id)) {
            throw new ValidationException($target->username . ' is already in this group.');
        }

        $previous = $this->groups->membershipFor($target->id);
        $this->transactions->transactional(function () use ($target, $groupId, $previous): void {
            $this->groups->placeUserInGroup($target->id, $groupId, GroupRole::Member);
            if ($previous !== null) {
                $this->ensureGroupHasAdmin($previous->group->id);
            }
        });

        return ['user_id' => $target->id, 'username' => $target->username];
    }

    /**
     * Admin action: create a brand-new account already inside this group, for someone who does
     * not have one.
     *
     * @return array{user_id: int, username: string}
     */
    public function createMemberAccount(
        GroupMembership $membership,
        string $username,
        string $password
    ): array {
        $membership->requireAdmin();
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > User::MAX_USERNAME_LENGTH) {
            throw new ValidationException('Pick a username of 1-' . User::MAX_USERNAME_LENGTH . ' characters.');
        }
        if (strlen($password) < User::MIN_PASSWORD_LENGTH) {
            throw new ValidationException('Their password needs at least ' . User::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if ($this->users->usernameExists($username)) {
            throw new ValidationException('That username is already taken.');
        }

        $groupId = $membership->group->id;
        $userId = $this->transactions->transactional(function () use ($username, $password, $groupId): int {
            $userId = $this->users->create(
                $username,
                $this->passwords->hash($password),
                User::colorForIndex($this->users->count())
            );
            $this->groups->placeUserInGroup($userId, $groupId, GroupRole::Member);

            return $userId;
        });

        return ['user_id' => $userId, 'username' => $username];
    }

    /**
     * Admin action: remove someone from the group. They get a fresh personal group as its admin,
     * so they keep an account and a private list of their own but take no tasks, points or prize
     * history out of this group. An admin cannot remove themselves this way -- that is "leave".
     */
    public function removeMember(GroupMembership $membership, int $actingUserId, int $targetUserId): void
    {
        $membership->requireAdmin();
        if ($targetUserId === $actingUserId) {
            throw new ValidationException('Use "leave group" to remove yourself.');
        }
        $groupId = $membership->group->id;
        if (!$this->groups->isMember($groupId, $targetUserId)) {
            throw new ValidationException('That person is not in this group.');
        }

        $this->createGroupFor($targetUserId, $this->defaultGroupName($targetUserId));
        $this->ensureGroupHasAdmin($groupId);
    }

    /**
     * Leaving under your own steam: the same as being removed, but chosen. The last remaining
     * member cannot leave -- there would be nobody to own the group's history, and they would just
     * be swapping one solo group for another.
     */
    public function leaveGroup(GroupMembership $membership, int $userId): void
    {
        $groupId = $membership->group->id;
        if ($this->groups->memberCount($groupId) <= 1) {
            throw new ValidationException("You're the only one here -- there's nothing to leave.");
        }

        $this->createGroupFor($userId, $this->defaultGroupName($userId));
        $this->ensureGroupHasAdmin($groupId);
    }

    /**
     * Anyone can join a group they have the invite code for. Membership is one group at a time, so
     * joining moves them out of their current one.
     *
     * @return array{group_id: int, name: string}
     */
    public function joinByInviteCode(int $userId, string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new ValidationException('Enter an invite code.');
        }
        $group = $this->groups->findByInviteCode($code);
        if ($group === null) {
            throw new ValidationException('That invite code does not match any group.');
        }
        if ($this->groups->isMember($group->id, $userId)) {
            throw new ValidationException("You're already in that group.");
        }

        $previous = $this->groups->membershipFor($userId);
        $this->transactions->transactional(function () use ($userId, $group, $previous): void {
            $this->groups->placeUserInGroup($userId, $group->id, GroupRole::Member);
            if ($previous !== null) {
                $this->ensureGroupHasAdmin($previous->group->id);
            }
        });

        return ['group_id' => $group->id, 'name' => $group->name];
    }

    public function rename(GroupMembership $membership, string $name): string
    {
        $membership->requireAdmin();
        $clean = Group::cleanName($name);
        $this->groups->rename($membership->group->id, $clean);

        return $clean;
    }

    /** Admin action: roll the invite code, invalidating any code already handed out. */
    public function regenerateInviteCode(GroupMembership $membership): string
    {
        $membership->requireAdmin();
        $code = InviteCodeGenerator::generate($this->pdo);
        $this->groups->setInviteCode($membership->group->id, $code);

        return $code;
    }

    /** Admin action: promote a member to admin, so admin duty can be shared or handed over. */
    public function setMemberRole(GroupMembership $membership, int $actingUserId, int $targetUserId, string $role): void
    {
        $membership->requireAdmin();
        $groupId = $membership->group->id;
        $newRole = GroupRole::fromString($role);

        if (!$this->groups->isMember($groupId, $targetUserId)) {
            throw new ValidationException('That person is not in this group.');
        }
        if (!$newRole->isAdmin() && $targetUserId === $actingUserId) {
            throw new AuthorizationException('Promote someone else to admin before stepping down.');
        }

        $this->groups->setRole($groupId, $targetUserId, $newRole);
        $this->ensureGroupHasAdmin($groupId);
    }

    /**
     * Keeps the "a group always has an admin" invariant: if the last admin left or was demoted, the
     * longest-standing remaining member is promoted. A group with no members left is fine -- it
     * holds its history until someone joins with the invite code.
     */
    public function ensureGroupHasAdmin(int $groupId): void
    {
        if ($this->groups->adminCount($groupId) > 0) {
            return;
        }
        $nextAdminId = $this->groups->longestStandingMemberId($groupId);
        if ($nextAdminId === null) {
            return;
        }
        $this->groups->setRole($groupId, $nextAdminId, GroupRole::Admin);
    }
}
