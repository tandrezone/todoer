<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    todoer_respond(['ok' => true, 'public_key' => todoer_push_public_key()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    todoer_fail('Method not allowed.', 405);
}

todoer_require_csrf();
$body = todoer_json_body();
if (($body['action'] ?? '') === 'subscribe') {
    todoer_save_push_subscription($pdo, (int) $user['id'], $body['subscription'] ?? []);
    todoer_respond(['ok' => true]);
}
if (($body['action'] ?? '') === 'unsubscribe') {
    todoer_remove_push_subscription($pdo, (int) $user['id'], (string) ($body['endpoint'] ?? ''));
    todoer_respond(['ok' => true]);
}
todoer_fail('Unknown action.');