<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/keep_import.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
$group = todoer_require_group($pdo, (int) $user['id'], $user['username']);
$groupId = (int) $group['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todoer_fail('Method not allowed.', 405);
}

todoer_require_csrf();

// --- Step 2: commit selected candidates as real tasks (JSON body) ---
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
if (!$isMultipart) {
    $body = todoer_json_body();
    if (($body['action'] ?? '') !== 'commit') {
        todoer_fail('Unknown action.');
    }
    $items = $body['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        todoer_fail('Nothing selected to import.');
    }

    // Imported tasks land in the shared ANY_USER pool at MODERATE priority with no window/timer
    // of their own -- matching api/tasks.php's 'add' defaults -- and created_by/status/etc. are
    // set explicitly so this insert stays valid against the assignment-feature schema.
    // Imported tasks get the period's own window (06:30 to 23:59) like any other task added
    // without one of its own -- see todoer_task_window().
    $insert = $pdo->prepare(
        "INSERT INTO tasks
            (group_id, user_id, created_by, list_type, period_key, title, points, status,
             window_start, window_end, assigned_type, assigned_user_id, priority, time_limit_minutes)
         VALUES (?, NULL, ?, ?, ?, ?, ?, 'unassigned', ?, ?, 'ANY_USER', NULL, 'MODERATE', NULL)"
    );

    $created = 0;
    $createdIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $listType = $item['list_type'] ?? '';
            $title = trim((string) ($item['title'] ?? ''));
            if (!in_array($listType, TODOER_LIST_TYPES, true) || $title === '') {
                continue;
            }
            $title = mb_substr($title, 0, 200);
            $periodKey = todoer_period_key($listType);
            $points = TODOER_POINTS[$listType];
            $window = todoer_task_window($listType, $periodKey, null, null);
            $insert->execute([
                $groupId, $user['id'], $listType, $periodKey, $title, $points,
                $window['start'], $window['end'],
            ]);
            $createdIds[] = (int) $pdo->lastInsertId();
            $created++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // If a given period's game is already running, don't strand these as 'unassigned' until the
    // next manual Start -- same backstop api/tasks.php's 'add' action relies on.
    foreach ($createdIds as $taskId) {
        todoer_maybe_assign_new_task($pdo, $taskId);
    }

    todoer_respond(['ok' => true, 'created' => $created]);
}

// --- Step 1: parse uploaded Keep export file(s) into candidate rows ---
$opts = [
    'skip_archived' => !empty($_POST['skip_archived']),
    'skip_trashed' => !empty($_POST['skip_trashed']),
    'include_checked' => !empty($_POST['include_checked']),
    'plain_note_mode' => in_array($_POST['plain_note_mode'] ?? '', ['skip', 'line', 'title'], true)
        ? $_POST['plain_note_mode']
        : 'line',
];

$allNotes = [];
$errors = [];
$fileCount = 0;

foreach ($_FILES['files']['tmp_name'] ?? [] as $i => $tmpName) {
    if ($_FILES['files']['error'][$i] === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = $_FILES['files']['name'][$i] . ': upload failed (error code ' . $_FILES['files']['error'][$i] . ').';
        continue;
    }
    $fileCount++;
    try {
        $notes = todoer_keep_notes_from_upload($tmpName, $_FILES['files']['name'][$i]);
        $allNotes = array_merge($allNotes, $notes);
    } catch (RuntimeException $e) {
        $errors[] = $_FILES['files']['name'][$i] . ': ' . $e->getMessage();
    }
}

if ($fileCount === 0) {
    todoer_fail('No files were received.');
}

$candidates = todoer_keep_candidates($allNotes, $opts);

// De-duplicate identical text (Keep exports often repeat the same shared checklist item across notes).
$seen = [];
$deduped = [];
foreach ($candidates as $c) {
    $key = mb_strtolower($c['text']);
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $deduped[] = $c;
}

todoer_respond([
    'ok' => true,
    'notes_found' => count($allNotes),
    'candidates' => $deduped,
    'errors' => $errors,
]);
