<?php
// Parses Google Keep notes exported via Google Takeout (https://takeout.google.com)
// into flat "candidate" task rows the import UI can offer to add to a list.
//
// Takeout gives each Keep note as its own .json file, e.g.:
// {
//   "title": "Groceries",
//   "textContent": "milk\neggs\nbread",
//   "listContent": [ {"text": "milk", "isChecked": false}, ... ],
//   "isArchived": false,
//   "isTrashed": false,
//   "isPinned": false,
//   "userEditedTimestampUsec": 1699999999000000
// }
// Checklist notes have "listContent"; plain notes have "textContent" instead.

/**
 * Reads an uploaded file (a single .json note, or a .zip containing many)
 * and returns a flat array of parsed note structures:
 *   ['title' => string, 'is_checklist' => bool, 'items' => [['text','checked'],...],
 *    'text' => string|null, 'is_archived' => bool, 'is_trashed' => bool]
 * Throws RuntimeException with a user-facing message on unrecoverable errors.
 */
function todoer_keep_notes_from_upload(string $tmpPath, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'json') {
        $note = todoer_parse_keep_json_string(file_get_contents($tmpPath), $originalName);
        return $note ? [$note] : [];
    }

    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'This server\'s PHP build does not have the zip extension. ' .
                'Please extract the Takeout zip yourself and upload the .json note files directly instead.'
            );
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            throw new RuntimeException("Could not open \"$originalName\" as a zip file.");
        }
        $notes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'json') {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            $note = todoer_parse_keep_json_string($contents, basename($entryName));
            if ($note) {
                $notes[] = $note;
            }
        }
        $zip->close();
        return $notes;
    }

    // Unrecognised file (e.g. the .html copy Takeout also includes, or an image) — skip quietly.
    return [];
}

function todoer_parse_keep_json_string(string $raw, string $sourceName): ?array {
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    // A Keep note always has at least one of these; anything else isn't a Keep note export.
    if (!array_key_exists('listContent', $data) && !array_key_exists('textContent', $data) && !array_key_exists('title', $data)) {
        return null;
    }

    $title = trim((string) ($data['title'] ?? ''));
    $isChecklist = isset($data['listContent']) && is_array($data['listContent']) && count($data['listContent']) > 0;

    $items = [];
    if ($isChecklist) {
        foreach ($data['listContent'] as $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $items[] = ['text' => $text, 'checked' => !empty($row['isChecked'])];
        }
    }

    return [
        'title' => $title !== '' ? $title : $sourceName,
        'is_checklist' => $isChecklist,
        'items' => $items,
        'text' => isset($data['textContent']) ? (string) $data['textContent'] : null,
        'is_archived' => !empty($data['isArchived']),
        'is_trashed' => !empty($data['isTrashed']),
    ];
}

/**
 * Flattens parsed Keep notes into candidate task rows, applying the import options:
 *   skip_archived, skip_trashed   (bool) - drop notes with those flags
 *   include_checked               (bool) - also offer items already checked off in Keep
 *   plain_note_mode                'skip' | 'line' | 'title'
 *     skip  - ignore notes with no checklist
 *     line  - one candidate per non-empty line of the note body
 *     title - one candidate using just the note's title
 * Each candidate: ['text' => string, 'checked' => bool, 'source' => string]
 */
function todoer_keep_candidates(array $notes, array $opts): array {
    $skipArchived = $opts['skip_archived'] ?? true;
    $skipTrashed = $opts['skip_trashed'] ?? true;
    $includeChecked = $opts['include_checked'] ?? false;
    $plainMode = $opts['plain_note_mode'] ?? 'line';

    $candidates = [];
    foreach ($notes as $note) {
        if ($skipArchived && $note['is_archived']) {
            continue;
        }
        if ($skipTrashed && $note['is_trashed']) {
            continue;
        }

        if ($note['is_checklist']) {
            foreach ($note['items'] as $item) {
                if ($item['checked'] && !$includeChecked) {
                    continue;
                }
                $candidates[] = [
                    'text' => $item['text'],
                    'checked' => $item['checked'],
                    'source' => $note['title'],
                ];
            }
            continue;
        }

        if ($plainMode === 'skip') {
            continue;
        }
        if ($plainMode === 'title') {
            if ($note['title'] !== '') {
                $candidates[] = ['text' => $note['title'], 'checked' => false, 'source' => $note['title']];
            }
            continue;
        }
        // plainMode === 'line'
        $lines = preg_split('/\r\n|\r|\n/', (string) $note['text']);
        foreach ($lines as $line) {
            $line = trim($line);
            // Keep sometimes prefixes plain-text checklist-style lines with a checkbox glyph.
            $line = preg_replace('/^[\x{2610}\x{2611}\x{2612}\-\*]\s*/u', '', $line);
            if ($line !== '') {
                $candidates[] = ['text' => $line, 'checked' => false, 'source' => $note['title']];
            }
        }
    }

    return $candidates;
}
