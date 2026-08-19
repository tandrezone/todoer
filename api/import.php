<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/keep_import.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todoer_fail('Method not allowed.', 405);
}

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

    $insert = $pdo->prepare(
        'INSERT INTO tasks (user_id, list_type, period_key, title, points) VALUES (?, ?, ?, ?, ?)'
    );

    $created = 0;
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
            $insert->execute([$user['id'], $listType, $periodKey, $title, $points]);
            $created++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
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
