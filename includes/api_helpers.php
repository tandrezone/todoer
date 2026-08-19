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
