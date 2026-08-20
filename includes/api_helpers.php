<?php
function todoer_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function todoer_respond($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function todoer_fail(string $message, int $code = 400): void {
    todoer_respond(['ok' => false, 'error' => $message], $code);
}

/**
 * CSRF guard for state-changing (POST) API requests. The session-bound token from
 * todoer_csrf_token() must come back in the X-CSRF-Token header -- the pages that can issue
 * these requests embed it in a <meta name="csrf-token"> tag and the app's JS reads it from
 * there (see assets/js/app.js/prizes.js/import.js), so a same-origin page always has it but a
 * request forged from another origin doesn't. Uses hash_equals() for a timing-safe compare.
 * Call this before acting on any POST body in an api/*.php endpoint.
 */
function todoer_require_csrf(): void {
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        todoer_fail('Invalid or missing CSRF token. Please reload the page and try again.', 403);
    }
}
