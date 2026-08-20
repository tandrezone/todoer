<?php
// Group management: who is in the group, and (for admins) who gets added or removed.
//
// The group acted on is always the caller's own group, resolved from their session membership --
// no group id is ever accepted from the client, so there is no request that can read or reshape
// somebody else's group. Mutating actions additionally require the caller to be that group's
// admin; the helpers in includes/groups.php throw on anything else and the catch below turns
// that into a plain 403.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
$groupId = (int) $group['id'];
$isAdmin = $group['role'] === 'admin';

/** The whole group view the page renders from. */
function todoer_group_payload(PDO $pdo, array $group, int $userId): array {
    $groupId = (int) $group['id'];
    $isAdmin = todoer_is_group_admin($pdo, $groupId, $userId);
    $members = array_map(function (array $member) use ($userId): array {
        return [
            'id' => (int) $member['id'],
            'username' => $member['username'],
            'color' => $member['color'],
            'role' => $member['role'],
            'active' => (int) $member['active'],
            'joined_at' => $member['joined_at'],
            'is_me' => (int) $member['id'] === $userId,
        ];
    }, todoer_group_members($pdo, $groupId));

    return [
        'ok' => true,
        'group' => [
            'id' => $groupId,
            'name' => $group['name'],
            'role' => $isAdmin ? 'admin' : 'member',
            'is_admin' => $isAdmin,
            // Only an admin needs the invite code, and only an admin should be able to hand out
            // access to the group's tasks -- so members never receive it.
            'invite_code' => $isAdmin ? $group['invite_code'] : null,
            'created_at' => $group['created_at'],
        ],
        'members' => $members,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    todoer_respond(todoer_group_payload($pdo, $group, (int) $user['id']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todoer_fail('Method not allowed.', 405);
}

todoer_require_csrf();
$body = todoer_json_body();
$action = $body['action'] ?? '';

// The group helpers signal "you're not allowed to do that" / "that input doesn't work" by
// throwing RuntimeException with a message meant for the user, so each action below can be
// written as the happy path and the failures land here as a 403/400.
try {
    switch ($action) {
        case 'add_member':
            $added = todoer_add_member_by_username($pdo, $groupId, (int) $user['id'], (string) ($body['username'] ?? ''));
            $message = $added['username'] . ' is now in the group.';
            break;

        case 'create_member':
            $created = todoer_create_member_account(
                $pdo,
                $groupId,
                (int) $user['id'],
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? '')
            );
            $message = 'Created an account for ' . $created['username'] . '. Share the password with them.';
            break;

        case 'remove_member':
            todoer_remove_member($pdo, $groupId, (int) $user['id'], (int) ($body['user_id'] ?? 0));
            $message = 'Removed from the group. They keep their account and a private list of their own.';
            break;

        case 'set_role':
            todoer_set_member_role(
                $pdo,
                $groupId,
                (int) $user['id'],
                (int) ($body['user_id'] ?? 0),
                (string) ($body['role'] ?? 'member')
            );
            $message = 'Role updated.';
            break;

        case 'rename':
            $name = todoer_rename_group($pdo, $groupId, (int) $user['id'], (string) ($body['name'] ?? ''));
            $message = 'Renamed to "' . $name . '".';
            break;

        case 'regenerate_code':
            todoer_regenerate_invite_code($pdo, $groupId, (int) $user['id']);
            $message = 'New invite code generated. The old one no longer works.';
            break;

        case 'join':
            $joined = todoer_join_group_by_code($pdo, (int) $user['id'], (string) ($body['invite_code'] ?? ''));
            $message = 'You joined "' . $joined['name'] . '".';
            break;

        case 'leave':
            todoer_leave_group($pdo, $groupId, (int) $user['id']);
            $message = 'You left the group and now have a private list of your own.';
            break;

        default:
            todoer_fail('Unknown action.');
    }
} catch (RuntimeException $e) {
    todoer_fail($e->getMessage(), 403);
}

// Re-resolve after the change: joining or leaving means the caller's group is now a different
// one, so the response has to describe where they actually ended up.
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
todoer_respond(array_merge(todoer_group_payload($pdo, $group, (int) $user['id']), ['message' => $message]));
