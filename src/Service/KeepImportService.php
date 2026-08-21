<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use ZipArchive;

/**
 * Parses Google Keep notes exported via Google Takeout into flat candidate rows the import UI can
 * offer to add to a list.
 *
 * Takeout gives each note as its own .json file: a checklist note has "listContent", a plain note
 * has "textContent". Anything that is not recognisable as a Keep note (the .html copy Takeout also
 * includes, an image) is skipped quietly rather than failing the whole upload -- people select whole
 * folders.
 *
 * The .zip path is guarded against zip bombs before any decompression happens. A real Keep export is
 * a flat pile of small plain-text files, so the ceilings are generous for genuine use while still
 * bounding the worst case from a hostile archive.
 */
final class KeepImportService
{
    private const ZIP_MAX_ENTRIES = 20000;

    private const ZIP_MAX_ENTRY_BYTES = 5 * 1024 * 1024;          // 5 MB per note file

    private const ZIP_MAX_TOTAL_BYTES = 100 * 1024 * 1024;        // 100 MB decompressed, combined

    /** @var list<string> */
    private const PLAIN_NOTE_MODES = ['skip', 'line', 'title'];

    /**
     * @param  list<UploadedFileInterface> $files
     * @param  array<string, mixed>        $options
     * @return array{notes_found: int, candidates: list<array{text: string, checked: bool, source: string}>, errors: list<string>}
     */
    public function scan(array $files, array $options): array
    {
        $notes = [];
        $errors = [];
        $received = 0;

        foreach ($files as $file) {
            if ($file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = $file->getClientFilename() ?? 'upload';
            if ($file->getError() !== UPLOAD_ERR_OK) {
                $errors[] = $name . ': upload failed (error code ' . $file->getError() . ').';
                continue;
            }

            $received++;
            try {
                $notes = array_merge($notes, $this->notesFromUpload($file));
            } catch (RuntimeException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        if ($received === 0) {
            throw new ValidationException('No files were received.');
        }

        return [
            'notes_found' => count($notes),
            'candidates' => $this->deduplicate($this->candidates($notes, $options)),
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $options */
    public static function normaliseOptions(
        bool $skipArchived,
        bool $skipTrashed,
        bool $includeChecked,
        string $plainNoteMode
    ): array {
        return [
            'skip_archived' => $skipArchived,
            'skip_trashed' => $skipTrashed,
            'include_checked' => $includeChecked,
            'plain_note_mode' => in_array($plainNoteMode, self::PLAIN_NOTE_MODES, true) ? $plainNoteMode : 'line',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function notesFromUpload(UploadedFileInterface $file): array
    {
        $name = $file->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $note = $this->parseNote((string) $file->getStream(), basename($name));

            return $note === null ? [] : [$note];
        }

        if ($extension === 'zip') {
            return $this->notesFromZip($file, $name);
        }

        // Unrecognised file -- skip quietly.
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function notesFromZip(UploadedFileInterface $file, string $originalName): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                "This server's PHP build does not have the zip extension. "
                . 'Please extract the Takeout zip yourself and upload the .json note files directly instead.'
            );
        }

        // ZipArchive needs a real path. An uploaded file already has one; a stream-only upload
        // (which is what a test or a non-SAPI caller produces) is spooled to a temporary file.
        $path = $this->localPath($file);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open "' . $originalName . '" as a zip file.');
        }

        try {
            if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
                throw new RuntimeException(
                    '"' . $originalName . '" has too many files (' . $zip->numFiles . ') to be a real Keep export.'
                );
            }

            $notes = [];
            $totalUncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entryName = (string) $zip->getNameIndex($index);
                if (strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) !== 'json') {
                    continue;
                }

                $size = (int) ($zip->statIndex($index)['size'] ?? 0);
                if ($size > self::ZIP_MAX_ENTRY_BYTES) {
                    continue; // a single "note" this large is not a real Keep note
                }
                $totalUncompressed += $size;
                if ($totalUncompressed > self::ZIP_MAX_TOTAL_BYTES) {
                    throw new RuntimeException(
                        '"' . $originalName . '" decompresses to more data than a Keep export should'
                        . ' -- refusing to continue.'
                    );
                }

                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    continue;
                }
                $note = $this->parseNote($contents, basename($entryName));
                if ($note !== null) {
                    $notes[] = $note;
                }
            }

            return $notes;
        } finally {
            $zip->close();
        }
    }

    private function localPath(UploadedFileInterface $file): string
    {
        $metaPath = $file->getStream()->getMetadata('uri');
        if (is_string($metaPath) && $metaPath !== '' && is_file($metaPath)) {
            return $metaPath;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'todoer-keep-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file to read the zip.');
        }
        file_put_contents($temporary, (string) $file->getStream());

        return $temporary;
    }

    /** @return array<string, mixed>|null */
    private function parseNote(string $raw, string $sourceName): ?array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        // A Keep note always has at least one of these; anything else is not a Keep note export.
        if (!array_key_exists('listContent', $data)
            && !array_key_exists('textContent', $data)
            && !array_key_exists('title', $data)
        ) {
            return null;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $isChecklist = isset($data['listContent']) && is_array($data['listContent']) && $data['listContent'] !== [];

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
     * Flattens parsed notes into candidate task rows, applying the import options.
     *
     * @param  list<array<string, mixed>> $notes
     * @param  array<string, mixed>       $options
     * @return list<array{text: string, checked: bool, source: string}>
     */
    private function candidates(array $notes, array $options): array
    {
        $skipArchived = (bool) ($options['skip_archived'] ?? true);
        $skipTrashed = (bool) ($options['skip_trashed'] ?? true);
        $includeChecked = (bool) ($options['include_checked'] ?? false);
        $plainMode = (string) ($options['plain_note_mode'] ?? 'line');

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
                        'checked' => (bool) $item['checked'],
                        'source' => (string) $note['title'],
                    ];
                }
                continue;
            }

            if ($plainMode === 'skip') {
                continue;
            }
            if ($plainMode === 'title') {
                if ($note['title'] !== '') {
                    $candidates[] = ['text' => (string) $note['title'], 'checked' => false, 'source' => (string) $note['title']];
                }
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', (string) $note['text']) ?: [] as $line) {
                // Keep sometimes prefixes plain-text checklist-style lines with a checkbox glyph.
                $line = trim((string) preg_replace('/^[\x{2610}\x{2611}\x{2612}\-\*]\s*/u', '', trim($line)));
                if ($line !== '') {
                    $candidates[] = ['text' => $line, 'checked' => false, 'source' => (string) $note['title']];
                }
            }
        }

        return $candidates;
    }

    /**
     * Keep exports often repeat the same shared checklist item across notes.
     *
     * @param  list<array{text: string, checked: bool, source: string}> $candidates
     * @return list<array{text: string, checked: bool, source: string}>
     */
    private function deduplicate(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate['text']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }
}
