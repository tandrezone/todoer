<?php
// Groups: the privacy and competition boundary.
//
// A group is "the people whose tasks and scores you can see". Every task, start/stop state,
// closed period and award belongs to exactly one group, and every read in the app is filtered
// by the caller's group -- so two groups sharing one install are invisible to each other: no
// shared tasks, no shared leaderboard, no shared prize list.
//
// Membership rules (enforced by the unique index on group_members.user_id, see schema.sql):
//   * exactly one group per user -- there is no "groupless" state to handle
//   * registering creates a personal group with that user as its admin
//   * an admin can add people to their group (existing accounts by username, or brand-new
//     accounts they create) and remove them again; a removed/leaving member lands back in a
//     fresh personal group of their own, taking nothing with them
//   * anyone can also join a group themselves with its invite code
//   * a group always keeps at least one admin

require_once __DIR__ . '/db.php';
// For TODOER_COLORS (used when an admin creates a member's account) and the session helpers.
// auth.php requires this file back for registration -- require_once makes that cycle harmless,
// since both files only *use* each other's symbols at call time, never while loading.
require_once __DIR__ . '/auth.php';

const TODOER_GROUP_MAX_NAME = 60;

/** The group a user belongs to, including that user's role in it. */
function todoer_user_group(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare(
        'SELECT g.id, g.name, g.invite_code, g.created_by, g.created_at, gm.role
         FROM group_members gm JOIN groups g ON g.id = gm.group_id
         WHERE gm.user_id = ?'
    );
    $stmt->execute([$userId]);
    $group = $stmt->fetch();
    return $group ?: null;
}

/**
 * The caller's group, creating a personal one if they somehow have none (an account made before
 * groups existed and missed the migration, or a group deleted underneath them). Every request
 * path goes through this, so "logged in but groupless" can never reach a query.
 */
function todoer_require_group(PDO $pdo, int $userId, ?string $username = null): array {
    $group = todoer_user_group($pdo, $userId);
    if ($group) {
        return $group;
    }
    todoer_create_group($pdo, $userId, todoer_default_group_name($pdo, $userId, $username));
    return todoer_user_group($pdo, $userId);
}

function todoer_default_group_name(PDO $pdo, int $userId, ?string $username = null): string {
    if ($username === null) {
        $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $username = (string) ($stmt->fetchColumn() ?: 'My');
    }
    return mb_substr($username . "'s group", 0, TODOER_GROUP_MAX_NAME);
}

/**
 * Creates a group with $userId as its admin, moving them out of whatever group they were in.
 * Returns the new group id.
 */
function todoer_create_group(PDO $pdo, int $userId, string $name): int {
    $name = todoer_clean_group_name($name);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO groups (name, invite_code, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, todoer_generate_invite_code($pdo), $userId]);
        $groupId = (int) $pdo->lastInsertId();
        todoer_place_user_in_group($pdo, $userId, $groupId, 'admin');
        $pdo->commit();
        return $groupId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function todoer_clean_group_name(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        $name = 'My group';
    }
    return mb_substr($name, 0, TODOER_GROUP_MAX_NAME);
}

/**
 * Moves a user into a group with the given role. One-group-per-user is a unique index, so the
 * old membership row is deleted rather than added to. The user's *existing* tasks stay behind
 * with their old group on purpose -- scores and history belong to the group they were earned in,
 * and dragging them along would leak one group's tasks into another.
 */
function todoer_place_user_in_group(PDO $pdo, int $userId, int $groupId, string $role = 'member'): void {
    $role = $role === 'admin' ? 'admin' : 'member';
    $del = $pdo->prepare('DELETE FROM group_members WHERE user_id = ?');
    $del->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    $ins->execute([$groupId, $userId, $role]);
}

/** Members of a group, admins first then alphabetical. */
function todoer_group_members(PDO $pdo, int $groupId): array {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.color, u.active, gm.role, gm.joined_at
         FROM group_members gm JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?
         ORDER BY (gm.role = 'admin') DESC, u.username ASC"
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function todoer_is_group_admin(PDO $pdo, int $groupId, int $userId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND role = 'admin'");
    $stmt->execute([$groupId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Whether a user is in this group at all -- the check that gates every cross-user reference. */
function todoer_is_group_member(PDO $pdo, int $groupId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Every group id, for the periodic sweeps in bootstrap.php that run across the whole install. */
function todoer_all_group_ids(PDO $pdo): array {
    return array_map('intval', array_column($pdo->query('SELECT id FROM groups ORDER BY id')->fetchAll(), 'id'));
}

/** Requires an admin; used by every mutating action in api/group.php. */
function todoer_require_group_admin(PDO $pdo, int $groupId, int $userId): void {
    if (!todoer_is_group_admin($pdo, $groupId, $userId)) {
        throw new RuntimeException('Only a group admin can do that.');
    }
}

/**
 * Admin action: pull an existing account into this group by username. They leave their previous
 * group; if that leaves their old group with no admin, the next-longest-standing member is
 * promoted so nobody gets stranded in an unmanageable group.
 */
function todoer_add_member_by_username(PDO $pdo, int $groupId, int $actingUserId, string $username): array {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Enter the username of the person to add.');
    }
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $target = $stmt->fetch();
    if (!$target) {
        throw new RuntimeException('No account with that username. Create one for them instead.');
    }
    if (todoer_is_group_member($pdo, $groupId, (int) $target['id'])) {
        throw new RuntimeException($target['username'] . ' is already in this group.');
    }

    $previous = todoer_user_group($pdo, (int) $target['id']);
    $pdo->beginTransaction();
    try {
        todoer_place_user_in_group($pdo, (int) $target['id'], $groupId, 'member');
        if ($previous) {
            todoer_ensure_group_has_admin($pdo, (int) $previous['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['user_id' => (int) $target['id'], 'username' => $target['username']];
}

/** Admin action: create a brand-new account already inside this group. */
function todoer_create_member_account(PDO $pdo, int $groupId, int $actingUserId, string $username, string $password): array {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    $username = trim($username);
    if ($username === '' || mb_strlen($username) > 40) {
        throw new RuntimeException('Pick a username of 1-40 characters.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Their password needs at least 8 characters.');
    }
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        throw new RuntimeException('That username is already taken.');
    }

    $pdo->beginTransaction();
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $color = TODOER_COLORS[$count % count(TODOER_COLORS)];
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, color) VALUES (?, ?, ?)');
        $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT), $color]);
        $userId = (int) $pdo->lastInsertId();
        todoer_place_user_in_group($pdo, $userId, $groupId, 'member');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['user_id' => $userId, 'username' => $username];
}

/**
 * Admin action: remove someone from the group. They get a fresh personal group (as its admin),
 * so they keep an account and a private list of their own but take no tasks, points or prize
 * history out of this group. An admin can't remove themselves this way -- that's "leave".
 */
function todoer_remove_member(PDO $pdo, int $groupId, int $actingUserId, int $targetUserId): void {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    if ($targetUserId === $actingUserId) {
        throw new RuntimeException('Use "leave group" to remove yourself.');
    }
    if (!todoer_is_group_member($pdo, $groupId, $targetUserId)) {
        throw new RuntimeException('That person is not in this group.');
    }
    $name = todoer_default_group_name($pdo, $targetUserId);
    todoer_create_group($pdo, $targetUserId, $name);
    todoer_ensure_group_has_admin($pdo, $groupId);
}

/**
 * A user leaving under their own steam: same as being removed, but they choose it. The last
 * remaining member of a group can't leave -- there'd be nobody left to own the group's history,
 * and they'd just be swapping one solo group for another.
 */
function todoer_leave_group(PDO $pdo, int $groupId, int $userId): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $memberCount = (int) $stmt->fetchColumn();
    if ($memberCount <= 1) {
        throw new RuntimeException("You're the only one here -- there's nothing to leave.");
    }
    todoer_create_group($pdo, $userId, todoer_default_group_name($pdo, $userId));
    todoer_ensure_group_has_admin($pdo, $groupId);
}

/** Anyone can join a group they have the invite code for. */
function todoer_join_group_by_code(PDO $pdo, int $userId, string $code): array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        throw new RuntimeException('Enter an invite code.');
    }
    $stmt = $pdo->prepare('SELECT id, name FROM groups WHERE invite_code = ?');
    $stmt->execute([$code]);
    $group = $stmt->fetch();
    if (!$group) {
        throw new RuntimeException('That invite code does not match any group.');
    }
    if (todoer_is_group_member($pdo, (int) $group['id'], $userId)) {
        throw new RuntimeException("You're already in that group.");
    }

    $previous = todoer_user_group($pdo, $userId);
    $pdo->beginTransaction();
    try {
        todoer_place_user_in_group($pdo, $userId, (int) $group['id'], 'member');
        if ($previous) {
            todoer_ensure_group_has_admin($pdo, (int) $previous['id']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['group_id' => (int) $group['id'], 'name' => $group['name']];
}

/** Admin action: rename the group. */
function todoer_rename_group(PDO $pdo, int $groupId, int $actingUserId, string $name): string {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    $name = todoer_clean_group_name($name);
    $stmt = $pdo->prepare('UPDATE groups SET name = ? WHERE id = ?');
    $stmt->execute([$name, $groupId]);
    return $name;
}

/** Admin action: roll the invite code, invalidating any code already handed out. */
function todoer_regenerate_invite_code(PDO $pdo, int $groupId, int $actingUserId): string {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    $code = todoer_generate_invite_code($pdo);
    $stmt = $pdo->prepare('UPDATE groups SET invite_code = ? WHERE id = ?');
    $stmt->execute([$code, $groupId]);
    return $code;
}

/** Admin action: promote another member to admin (so admin duty can be shared or handed over). */
function todoer_set_member_role(PDO $pdo, int $groupId, int $actingUserId, int $targetUserId, string $role): void {
    todoer_require_group_admin($pdo, $groupId, $actingUserId);
    $role = $role === 'admin' ? 'admin' : 'member';
    if (!todoer_is_group_member($pdo, $groupId, $targetUserId)) {
        throw new RuntimeException('That person is not in this group.');
    }
    if ($role === 'member' && $targetUserId === $actingUserId) {
        throw new RuntimeException('Promote someone else to admin before stepping down.');
    }
    $stmt = $pdo->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$role, $groupId, $targetUserId]);
    todoer_ensure_group_has_admin($pdo, $groupId);
}

/**
 * Keeps the "a group always has an admin" invariant: if the last admin left or was demoted,
 * the longest-standing remaining member is promoted. A group with no members left at all is
 * fine -- it just holds its history until someone joins with the invite code.
 */
function todoer_ensure_group_has_admin(PDO $pdo, int $groupId): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND role = 'admin'");
    $stmt->execute([$groupId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $next = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ? ORDER BY joined_at ASC, user_id ASC LIMIT 1');
    $next->execute([$groupId]);
    $userId = $next->fetchColumn();
    if ($userId === false) {
        return;
    }
    $promote = $pdo->prepare("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?");
    $promote->execute([$groupId, (int) $userId]);
}
