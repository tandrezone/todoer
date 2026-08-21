<?php

/**
 * End-to-end smoke tests.
 *
 * These drive the real application: a real container, the real middleware stack, real SQLite. The
 * only substitutions are an in-memory session (so a CLI run has somewhere to keep one) and a
 * temporary data directory. Everything else -- routing, CSRF, authentication, group scoping, the
 * assignment engine, period closing -- is the code that ships.
 *
 * Run with:  php tests/smoke.php
 */

declare(strict_types=1);

use App\Application;
use App\Domain\Enum\ListType;
use App\Domain\Period\Period;
use App\Repository\GroupRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\AssignmentService;
use App\Service\PeriodService;
use App\Session\ArraySession;
use App\Session\CsrfTokenManager;
use App\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

$rootDir = dirname(__DIR__);
require $rootDir . '/vendor/autoload.php';

$dataDir = sys_get_temp_dir() . '/todoer-smoke-' . getmypid();
if (is_dir($dataDir)) {
    array_map('unlink', glob($dataDir . '/*') ?: []);
} else {
    mkdir($dataDir, 0770, true);
}
putenv('TODOER_DATA_DIR=' . $dataDir);

$app = Application::boot($rootDir, '');
$container = $app->container();

$session = new ArraySession([], true);
$container->set(SessionInterface::class, static fn(): SessionInterface => $session);

$factory = $container->typed(Psr17Factory::class);
$csrf = static fn(): string => $container->typed(CsrfTokenManager::class)->token();

// --- Tiny test harness ------------------------------------------------------------------------
$passed = 0;
$failures = [];

$check = static function (string $name, bool $condition, string $detail = '') use (&$passed, &$failures): void {
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";

        return;
    }
    $failures[] = $name . ($detail !== '' ? ' -- ' . $detail : '');
    echo "  FAIL $name" . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
};

$section = static function (string $title): void {
    echo "\n$title\n";
};

$body = static fn(ResponseInterface $response): string => (string) $response->getBody();
$json = static function (ResponseInterface $response): array {
    $decoded = json_decode((string) $response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
};

$get = static function (string $path) use ($app, $factory): ResponseInterface {
    return $app->handle($factory->createServerRequest('GET', 'http://localhost' . $path));
};

$post = static function (string $path, array $payload, bool $withCsrf = true) use ($app, $factory, $csrf): ResponseInterface {
    $request = $factory->createServerRequest('POST', 'http://localhost' . $path)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream((string) json_encode($payload)));
    if ($withCsrf) {
        $request = $request->withHeader('X-CSRF-Token', $csrf());
    }

    return $app->handle($request);
};

$postForm = static function (string $path, array $fields) use ($app, $factory, $csrf): ResponseInterface {
    $request = $factory->createServerRequest('POST', 'http://localhost' . $path)
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody($fields + ['csrf_token' => $csrf()]);

    return $app->handle($request);
};

$upload = static function (string $path, array $files, array $fields = []) use ($app, $factory, $csrf): ResponseInterface {
    $request = $factory->createServerRequest('POST', 'http://localhost' . $path)
        ->withHeader('X-CSRF-Token', $csrf())
        ->withParsedBody($fields)
        ->withUploadedFiles($files);

    return $app->handle($request);
};

$uploadedFile = static function (string $contents, string $filename) use ($factory): UploadedFileInterface {
    return $factory->createUploadedFile($factory->createStream($contents), strlen($contents), UPLOAD_ERR_OK, $filename);
};

$logout = static function () use ($session): void {
    $session->destroy();
    $session->start();
};

// --- 1. Anonymous access ----------------------------------------------------------------------
$section('Anonymous access');

$response = $get('/');
$check('a page redirects an anonymous visitor to /login', $response->getStatusCode() === 302
    && $response->getHeaderLine('Location') === '/login', 'got ' . $response->getStatusCode());

$response = $get('/api/tasks');
$check('an API endpoint answers 401 rather than redirecting', $response->getStatusCode() === 401);
$check('the 401 is JSON with a message', ($json($response)['error'] ?? '') === 'Not logged in.');

$response = $get('/login');
$check('the sign-in page renders', $response->getStatusCode() === 200 && str_contains($body($response), 'Turn your to-do list into a competition'));
$check('security headers are present', $response->getHeaderLine('X-Content-Type-Options') === 'nosniff');

$response = $get('/nope');
$check('an unknown path is a 404 page, not a stack trace', $response->getStatusCode() === 404
    && !str_contains($body($response), $rootDir));

$response = $get('/logout');
$check('logout refuses GET (405) with an Allow header', $response->getStatusCode() === 405
    && str_contains($response->getHeaderLine('Allow'), 'POST'));

// --- 2. Registration and CSRF -----------------------------------------------------------------
$section('Registration, sessions and CSRF');

$response = $app->handle(
    $factory->createServerRequest('POST', 'http://localhost/login')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody(['mode' => 'register', 'username' => 'alice', 'password' => 'correct horse'])
);
$check('a form post without the CSRF token is refused', $response->getStatusCode() === 403);

$response = $postForm('/login', ['mode' => 'register', 'username' => 'alice', 'password' => 'correct horse']);
$check('registering signs you in and redirects', $response->getStatusCode() === 302
    && $response->getHeaderLine('Location') === '/');

$response = $postForm('/login', ['mode' => 'register', 'username' => 'alice', 'password' => 'another one']);
$check('a duplicate username is reported on the form, not thrown', $response->getStatusCode() === 302,
    'already signed in, so the page redirects');

$response = $get('/');
$check('the dashboard renders for a signed-in user', $response->getStatusCode() === 200
    && str_contains($body($response), 'data-list-type="daily"'));
$check('the dashboard carries a CSRF token and a base path', str_contains($body($response), 'name="csrf-token"')
    && str_contains($body($response), 'name="base-path"'));

$response = $post('/api/tasks', ['action' => 'add', 'list_type' => 'daily', 'title' => 'x'], false);
$check('an API POST without the CSRF header is refused', $response->getStatusCode() === 403);

// --- 3. The task lifecycle --------------------------------------------------------------------
$section('Tasks: add, start, complete');

$response = $get('/api/tasks');
$payload = $json($response);
$check('the dashboard payload has all three lists', isset($payload['tasks']['daily'], $payload['tasks']['weekly'], $payload['tasks']['monthly']));
$check('the caller lands in a personal group', ($payload['group']['name'] ?? '') === "alice's group");
$check('the caller is that group\'s admin', ($payload['group']['role'] ?? '') === 'admin');

$response = $post('/api/tasks', [
    'action' => 'add',
    'list_type' => 'daily',
    'title' => 'Take out the bins',
    'priority' => 'HIGH',
    'time_limit_minutes' => 99,
]);
$taskId = $json($response)['id'] ?? 0;
$check('a task can be added', $taskId > 0);

$response = $post('/api/tasks', ['action' => 'add', 'list_type' => 'daily', 'title' => '   ']);
$check('a blank title is rejected with 400', $response->getStatusCode() === 400
    && ($json($response)['error'] ?? '') === 'Task title cannot be empty.');

$response = $post('/api/tasks', ['action' => 'add', 'list_type' => 'yearly', 'title' => 'nope']);
$check('an invalid list type is rejected', $response->getStatusCode() === 400);

$payload = $json($get('/api/tasks'));
$check('the new task is waiting to be assigned', $payload['tasks']['daily']['unassigned_count'] === 1);
$check('a HIGH priority task ignores the submitted per-task timer',
    $payload['tasks']['daily']['board'][0]['time_limit_minutes'] === null);

// --- 3b. Times per period: one list, occurrences split into equal windows ---------------------
// (Run here, while daily is still stopped -- adding is refused once a list is running, and this
// section leaves the board exactly as it found it before the list starts below.)
$section('Tasks: "times per period" splits the window into occurrences');

$response = $post('/api/tasks', ['action' => 'add', 'list_type' => 'daily', 'title' => 'Stretch break', 'times_per_period' => 3]);
$check('adding a 3x/day task succeeds', ($json($response)['id'] ?? 0) > 0);

$boardRows = static fn(array $payload): array => array_values(array_filter(
    $payload['tasks']['daily']['board'],
    static fn(array $row): bool => $row['title'] === 'Stretch break'
));
$occurrences = $boardRows($json($get('/api/tasks')));
$check('it creates 3 separate rows, not 1', count($occurrences) === 3, 'got ' . count($occurrences));

usort($occurrences, static fn(array $a, array $b): int => strcmp((string) $a['window_start'], (string) $b['window_start']));
$check('each occurrence got its own, non-overlapping window',
    $occurrences[0]['window_start'] !== null
    && $occurrences[0]['window_end'] === $occurrences[1]['window_start']
    && $occurrences[1]['window_end'] === $occurrences[2]['window_start']);
$check('occurrence_index/occurrence_count are numbered correctly',
    [$occurrences[0]['occurrence_index'], $occurrences[1]['occurrence_index'], $occurrences[2]['occurrence_index']] === [1, 2, 3]
    && $occurrences[0]['occurrence_count'] === 3 && $occurrences[2]['occurrence_count'] === 3);
$check('every occurrence earns the list\'s normal points, same as any other daily task',
    $occurrences[0]['points'] === 1 && $occurrences[1]['points'] === 1 && $occurrences[2]['points'] === 1);

$response = $post('/api/tasks', [
    'action' => 'edit',
    'task_id' => $occurrences[0]['id'],
    'title' => 'Stretch break',
    'assigned_type' => 'ANY_USER',
    'priority' => 'MODERATE',
    'times_per_period' => 5,
    'occurrence_index' => 2,
]);
$check('editing one occurrence\'s slot/count is accepted', ($json($response)['ok'] ?? false) === true);
$edited = $boardRows($json($get('/api/tasks')));
$editedRow = current(array_filter($edited, static fn(array $r): bool => $r['id'] === $occurrences[0]['id']));
$check('the edited row now reports occurrence 2 of 5', $editedRow !== false
    && $editedRow['occurrence_index'] === 2 && $editedRow['occurrence_count'] === 5);
$check('editing one occurrence did not touch the sibling rows',
    count($boardRows($json($get('/api/tasks')))) === 3);

$plainAddResponse = $post('/api/tasks', ['action' => 'add', 'list_type' => 'daily', 'title' => 'Plain add-back-compat check']);
$plainAddId = $json($plainAddResponse)['id'] ?? 0;
$plainAddRow = current(array_filter(
    $json($get('/api/tasks'))['tasks']['daily']['board'],
    static fn(array $r): bool => $r['id'] === $plainAddId
));
$check('a plain add (no times_per_period) still defaults to a single, window-less task',
    $plainAddRow !== false && $plainAddRow['window_start'] === null && $plainAddRow['window_end'] === null
    && $plainAddRow['occurrence_index'] === 1 && $plainAddRow['occurrence_count'] === 1);

// Clean up everything this section added (by id, not position) so the board is back to just the
// one pre-existing task the rest of the suite (starting the list below) assumes.
foreach (array_merge(array_column($occurrences, 'id'), [$plainAddId]) as $idToRemove) {
    $post('/api/tasks', ['action' => 'delete', 'task_id' => $idToRemove]);
}
$remaining = count($json($get('/api/tasks'))['tasks']['daily']['board']);
$check('the board is back down to just the original task', $remaining === 1, "got $remaining");

$response = $post('/api/tasks', ['action' => 'start', 'list_type' => 'daily']);
$started = $json($response);
$check('starting the list distributes it', ($started['started'] ?? false) === true && ($started['open_assigned'] ?? 0) === 1);

$payload = $json($get('/api/tasks'));
$dailyItems = $payload['tasks']['daily']['items'];
$check('the list is running', $payload['tasks']['daily']['running'] === true);
$check('the task is now mine and open', ($dailyItems[0]['is_mine'] ?? false) === true && $dailyItems[0]['status'] === 'open');
$check('I can tick it off', ($dailyItems[0]['can_complete'] ?? false) === true);

$response = $post('/api/tasks', ['action' => 'add', 'list_type' => 'daily', 'title' => 'while running']);
$check('adding is refused while the list runs', $response->getStatusCode() === 400
    && str_contains($json($response)['error'] ?? '', 'stop it before adding'));

$response = $post('/api/tasks', ['action' => 'complete', 'task_id' => $taskId]);
$completed = $json($response);
$check('completing my own task works', ($completed['ok'] ?? false) === true
    && array_key_exists('claimed_from', $completed) && $completed['claimed_from'] === null);

$boards = $json($get('/api/leaderboard'));
$check('the leaderboard shows the point', ($boards['boards']['daily']['rows'][0]['points'] ?? 0) === 1);
$check('all-time standings include the member', count($boards['all_time']) === 1);

$response = $post('/api/tasks', ['action' => 'reopen', 'task_id' => $taskId]);
$check('un-ticking my own task works', ($json($response)['ok'] ?? false) === true);
$check('the point is given back', ($json($get('/api/leaderboard'))['boards']['daily']['rows'][0]['points'] ?? -1) === 0);

// --- 4. Two players in one group --------------------------------------------------------------
$section('Groups: invites, sharing and stealing');

$groupPayload = $json($get('/api/group'));
$inviteCode = $groupPayload['group']['invite_code'] ?? '';
$check('an admin is given the invite code', $inviteCode !== '');

$logout();
$response = $postForm('/login', ['mode' => 'register', 'username' => 'bob', 'password' => 'correct horse', 'invite_code' => $inviteCode]);
$check('a second player can join with the invite code', $response->getStatusCode() === 302);

$payload = $json($get('/api/tasks'));
$check('the group now has two members', ($payload['group']['member_count'] ?? 0) === 2);
$check("bob sees alice's task on the shared board", count($payload['tasks']['daily']['board']) === 1);
$boardTask = $payload['tasks']['daily']['board'][0];
$check('and it is marked as not his', $boardTask['is_mine'] === false);
$check('but it is up for grabs while the list runs', $boardTask['claimable'] === true);

$response = $post('/api/tasks', ['action' => 'complete', 'task_id' => $taskId]);
$claim = $json($response);
$check('bob can take the task and bank the points', ($claim['ok'] ?? false) === true && ($claim['claimed_from'] ?? 0) > 0);

$groupJson = $json($get('/api/group'));
$check('a member is not given the invite code',
    array_key_exists('invite_code', $groupJson['group'] ?? []) && $groupJson['group']['invite_code'] === null);
$response = $post('/api/group', ['action' => 'rename', 'name' => 'Hacked']);
$check('a member cannot rename the group', $response->getStatusCode() === 403);

$logout();
$postForm('/login', ['mode' => 'login', 'username' => 'alice', 'password' => 'correct horse']);
$payload = $json($get('/api/tasks'));
$titles = array_column($payload['notifications'] ?? [], 'title');
$check('alice is told her task was taken',
    count(array_filter($titles, static fn(string $t): bool => str_contains($t, 'did your task'))) === 1,
    'notifications: ' . implode(' | ', $titles));
$check('the points went to bob, not alice',
    ($json($get('/api/leaderboard'))['boards']['daily']['rows'][0]['username'] ?? '') === 'bob');

// --- 5. Group isolation -----------------------------------------------------------------------
$section('Group isolation');

$logout();
$postForm('/login', ['mode' => 'register', 'username' => 'carol', 'password' => 'correct horse']);
$payload = $json($get('/api/tasks'));
$check('a new group sees none of the other group\'s tasks', $payload['tasks']['daily']['board'] === []);
$check('and none of its members', ($payload['group']['member_count'] ?? 0) === 1);
$check('nor its leaderboard rows', count($json($get('/api/leaderboard'))['all_time']) === 1);

$response = $post('/api/tasks', ['action' => 'complete', 'task_id' => $taskId]);
$check('a task id from another group is simply not found', $response->getStatusCode() === 404);

$response = $post('/api/tasks', [
    'action' => 'add',
    'list_type' => 'daily',
    'title' => 'lock to an outsider',
    'assigned_type' => 'SPECIFIC_USER',
    'assigned_user_id' => 1,
]);
$check('a task cannot be locked to someone outside the group', $response->getStatusCode() === 400
    && str_contains($json($response)['error'] ?? '', 'from your group'));

// --- 6. Escaping and injection ----------------------------------------------------------------
$section('Output escaping and SQL injection');

$post('/api/group', ['action' => 'rename', 'name' => '<script>alert(1)</script>']);
$html = $body($get('/'));
$check('a hostile group name is escaped in the page', str_contains($html, '&lt;script&gt;')
    && !str_contains($html, '<script>alert(1)</script>'));

$logout();
$response = $postForm('/login', ['mode' => 'login', 'username' => "' OR 1=1 --", 'password' => 'whatever']);
$check('an injection attempt in the username just fails to sign in',
    $response->getStatusCode() === 422 && str_contains($body($response), 'Wrong username or password'));

// --- 7. Export and restore --------------------------------------------------------------------
$section('Export, restore and Keep import');

$postForm('/login', ['mode' => 'login', 'username' => 'alice', 'password' => 'correct horse']);
$response = $get('/api/export/tasks');
$check('the export downloads as an attachment', str_contains($response->getHeaderLine('Content-Disposition'), 'attachment'));
$export = $json($response);
$check('the export contains the group\'s tasks and uses usernames',
    count($export['tasks'] ?? []) === 1 && ($export['tasks'][0]['user'] ?? '') === 'bob');

$response = $post('/api/import/tasks', $export);
$restored = $json($response);
$check('the export can be restored', ($restored['created'] ?? 0) === 1 && ($restored['skipped'] ?? -1) === 0);

$response = $post('/api/import/tasks', ['tasks' => [['list_type' => 'daily'], 'nonsense', ['list_type' => 'daily', 'title' => 'ok']]]);
$restored = $json($response);
$check('unusable rows are skipped rather than failing the restore',
    ($restored['created'] ?? 0) === 1 && ($restored['skipped'] ?? 0) === 2);

$note = (string) json_encode([
    'title' => 'Groceries',
    'listContent' => [['text' => 'milk', 'isChecked' => false], ['text' => 'eggs', 'isChecked' => true]],
]);
$response = $upload('/api/import/keep/scan', ['files' => [$uploadedFile($note, 'Groceries.json')]], [
    'skip_archived' => '1',
    'plain_note_mode' => 'line',
]);
$scan = $json($response);
$check('a Keep note is scanned into candidates', ($scan['notes_found'] ?? 0) === 1
    && count($scan['candidates'] ?? []) === 1 && $scan['candidates'][0]['text'] === 'milk');

$response = $post('/api/import/keep/commit', ['items' => [['title' => 'milk', 'list_type' => 'weekly']]]);
$check('picked candidates become tasks', ($json($response)['created'] ?? 0) === 1);

// --- 8. Timers, expiry and prizes -------------------------------------------------------------
$section('Timers, expiry and prize draws');

/** @var TaskRepository $taskRepository */
$taskRepository = $container->typed(TaskRepository::class);
/** @var GroupRepository $groupRepository */
$groupRepository = $container->typed(GroupRepository::class);
/** @var UserRepository $userRepository */
$userRepository = $container->typed(UserRepository::class);
/** @var AssignmentService $assignment */
$assignment = $container->typed(AssignmentService::class);
/** @var PeriodService $periods */
$periods = $container->typed(PeriodService::class);

$alice = $userRepository->findByUsername('alice');
$bob = $userRepository->findByUsername('bob');
$aliceGroupId = $groupRepository->membershipFor($alice->id)->group->id;

// A task that timed out while alice held it: the sweep hands it to the other active member.
$pdo = $container->typed(PDO::class);
$pdo->prepare(
    "INSERT INTO tasks (group_id, user_id, created_by, list_type, period_key, title, points, status,
                        assigned_type, priority, time_limit_minutes, assigned_at, created_at)
     VALUES (?, ?, ?, 'daily', ?, 'Overdue chore', 1, 'open', 'ANY_USER', 'MODERATE', 5, ?, ?)"
)->execute([
    $aliceGroupId,
    $alice->id,
    $alice->id,
    Period::forTimestamp(ListType::Daily, time())->key,
    date('Y-m-d H:i:s', time() - 3600),
    date('Y-m-d H:i:s', time() - 3600),
]);
$overdueId = (int) $pdo->lastInsertId();

$assignment->processExpirations();
$reassigned = $taskRepository->find($overdueId, $aliceGroupId);
$check('an overdue shared task is handed to somebody else', $reassigned->userId === $bob->id);

$history = $pdo->query('SELECT event, note FROM task_history ORDER BY id DESC LIMIT 1')->fetch();
$check('and the reassignment is recorded in the history',
    $history['event'] === 'reassigned' && $history['note'] === 'timed out');

// A locked task nobody did: expired rather than silently unlocked.
$pdo->prepare(
    "INSERT INTO tasks (group_id, user_id, created_by, list_type, period_key, title, points, status,
                        assigned_type, assigned_user_id, priority, time_limit_minutes, assigned_at, created_at)
     VALUES (?, ?, ?, 'daily', ?, 'Locked and missed', 1, 'open', 'SPECIFIC_USER', ?, 'MODERATE', 5, ?, ?)"
)->execute([
    $aliceGroupId,
    $alice->id,
    $alice->id,
    Period::forTimestamp(ListType::Daily, time())->key,
    $alice->id,
    date('Y-m-d H:i:s', time() - 3600),
    date('Y-m-d H:i:s', time() - 3600),
]);
$lockedId = (int) $pdo->lastInsertId();
$assignment->processExpirations();
$check('a missed locked task expires instead of moving', $taskRepository->find($lockedId, $aliceGroupId)->status->value === 'expired');

// An elapsed period with a clear winner: closing it crowns them and draws a prize.
$yesterday = Period::forTimestamp(ListType::Daily, time() - 86400);
$pdo->prepare(
    "INSERT INTO tasks (group_id, user_id, created_by, list_type, period_key, title, points, status,
                        assigned_type, priority, created_at, completed_at)
     VALUES (?, ?, ?, 'daily', ?, 'Yesterday''s win', 5, 'done', 'ANY_USER', 'MODERATE', ?, ?)"
)->execute([$aliceGroupId, $alice->id, $alice->id, $yesterday->key, $yesterday->key . ' 09:00:00', $yesterday->key . ' 10:00:00']);

$periods->closeElapsedPeriods($aliceGroupId);
$prizes = $json($get('/api/prizes'));
$awardsByPeriod = [];
foreach ($prizes['awards'] ?? [] as $row) {
    $awardsByPeriod[$row['list_type'] . '/' . $row['period_key']] = $row;
}
$award = $awardsByPeriod['daily/' . $yesterday->key] ?? [];
$check('closing an elapsed period awards a prize', $award !== [],
    'awards: ' . implode(', ', array_keys($awardsByPeriod)));
$check('to the top scorer, with the prize description', ($award['username'] ?? '') === 'alice' && ($award['prize'] ?? '') !== '');
$check('and the winner sees it as theirs', ($award['is_mine'] ?? false) === true);
$check('the period whose every task was settled was crowned early, without waiting for the clock',
    isset($awardsByPeriod['daily/' . Period::forTimestamp(ListType::Daily, time())->key]));

$awardCount = count($prizes['awards'] ?? []);
$periods->closeElapsedPeriods($aliceGroupId);
$check('closing again does not double-award', count($json($get('/api/prizes'))['awards'] ?? []) === $awardCount);

$response = $post('/api/prizes', ['action' => 'claim', 'award_id' => $award['id']]);
$check('the winner can mark their prize claimed', ($json($response)['ok'] ?? false) === true);
$check('and it shows as claimed', (int) ($json($get('/api/prizes'))['awards'][0]['claimed'] ?? 0) === 1);

$logout();
$postForm('/login', ['mode' => 'login', 'username' => 'bob', 'password' => 'correct horse']);
$response = $post('/api/prizes', ['action' => 'claim', 'award_id' => $award['id']]);
$check('somebody else cannot claim it', $response->getStatusCode() === 400);

// --- Summary ----------------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
if ($failures === []) {
    echo "All $passed checks passed.\n";
    exit(0);
}
echo count($failures) . ' of ' . ($passed + count($failures)) . " checks FAILED:\n";
foreach ($failures as $failure) {
    echo "  - $failure\n";
}
exit(1);
