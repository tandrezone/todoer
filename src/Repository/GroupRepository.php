<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\GroupRole;
use App\Domain\Group\Group;
use App\Domain\Group\GroupMember;
use App\Domain\Group\GroupMembership;
use App\Domain\User\User;
use PDO;

/** Every SQL statement that touches `groups` and `group_members`. */
final class GroupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $groupId): ?Group
    {
        $statement = $this->pdo->prepare('SELECT id, name, invite_code, created_by, created_at FROM groups WHERE id = ?');
        $statement->execute([$groupId]);
        $row = $statement->fetch();

        return $row === false ? null : Group::fromRow($row);
    }

    public function findByInviteCode(string $code): ?Group
    {
        $statement = $this->pdo->prepare('SELECT id, name, invite_code, created_by, created_at FROM groups WHERE invite_code = ?');
        $statement->execute([strtoupper(trim($code))]);
        $row = $statement->fetch();

        return $row === false ? null : Group::fromRow($row);
    }

    /** The group a user belongs to, with their role in it. */
    public function membershipFor(int $userId): ?GroupMembership
    {
        $statement = $this->pdo->prepare(
            'SELECT g.id, g.name, g.invite_code, g.created_by, g.created_at, gm.role
             FROM group_members gm JOIN groups g ON g.id = gm.group_id
             WHERE gm.user_id = ?'
        );
        $statement->execute([$userId]);
        $row = $statement->fetch();

        return $row === false
            ? null
            : new GroupMembership(Group::fromRow($row), GroupRole::fromString((string) $row['role']));
    }

    public function create(string $name, string $inviteCode, int $createdBy): int
    {
        $statement = $this->pdo->prepare('INSERT INTO groups (name, invite_code, created_by) VALUES (?, ?, ?)');
        $statement->execute([$name, $inviteCode, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Moves a user into a group with the given role.
     *
     * One group per user is a unique index, so the old membership row is deleted rather than added
     * to. Their existing tasks stay behind with the old group on purpose: scores and history belong
     * to the group they were earned in, and dragging them along would leak one group's tasks into
     * another.
     */
    public function placeUserInGroup(int $userId, int $groupId, GroupRole $role): void
    {
        $delete = $this->pdo->prepare('DELETE FROM group_members WHERE user_id = ?');
        $delete->execute([$userId]);

        $insert = $this->pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
        $insert->execute([$groupId, $userId, $role->value]);
    }

    /** @return list<GroupMember> Admins first, then alphabetical. */
    public function members(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.username, u.color, u.active, gm.role, gm.joined_at
             FROM group_members gm JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ?
             ORDER BY (gm.role = 'admin') DESC, u.username ASC"
        );
        $statement->execute([$groupId]);

        return array_map(
            static fn(array $row): GroupMember => GroupMember::fromRow($row),
            $statement->fetchAll()
        );
    }

    /**
     * Active (currently playing) members, in a stable order used for tie-breaking.
     *
     * Distribution and reassignment only ever consider this list, so a task can never land on
     * somebody outside the group it belongs to.
     *
     * @return list<User>
     */
    public function activeMembers(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.username, u.color, u.active FROM group_members gm JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ? AND u.active = 1 ORDER BY u.username'
        );
        $statement->execute([$groupId]);

        return array_map(static fn(array $row): User => User::fromRow($row), $statement->fetchAll());
    }

    public function isMember(int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $statement->execute([$groupId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    /** In the group *and* currently playing -- the check for handing a task back to someone. */
    public function isActiveMember(int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM group_members gm JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ? AND gm.user_id = ? AND u.active = 1'
        );
        $statement->execute([$groupId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function isAdmin(int $groupId, int $userId): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND role = 'admin'");
        $statement->execute([$groupId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function memberCount(int $groupId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
        $statement->execute([$groupId]);

        return (int) $statement->fetchColumn();
    }

    public function adminCount(int $groupId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND role = 'admin'");
        $statement->execute([$groupId]);

        return (int) $statement->fetchColumn();
    }

    /** The member who has been in the group longest -- promoted when a group loses its last admin. */
    public function longestStandingMemberId(int $groupId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id FROM group_members WHERE group_id = ? ORDER BY joined_at ASC, user_id ASC LIMIT 1'
        );
        $statement->execute([$groupId]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (int) $userId;
    }

    public function setRole(int $groupId, int $userId, GroupRole $role): void
    {
        $statement = $this->pdo->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?');
        $statement->execute([$role->value, $groupId, $userId]);
    }

    public function rename(int $groupId, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE groups SET name = ? WHERE id = ?');
        $statement->execute([$name, $groupId]);
    }

    public function setInviteCode(int $groupId, string $code): void
    {
        $statement = $this->pdo->prepare('UPDATE groups SET invite_code = ? WHERE id = ?');
        $statement->execute([$code, $groupId]);
    }

    /** @return list<int> Every group id, for the maintenance sweep that runs installation-wide. */
    public function allIds(): array
    {
        $rows = $this->pdo->query('SELECT id FROM groups ORDER BY id')->fetchAll();

        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    public function username(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT username FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $username = $statement->fetchColumn();

        return $username === false ? null : (string) $username;
    }
}
