<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$user = todoer_current_user();
if (!$user) {
    todoer_fail('Not logged in.', 401);
}

$pdo = $GLOBALS['pdo'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The service worker re-subscribes on its own when the browser rotates an endpoint
    // (pushsubscriptionchange), and that POST needs the session's CSRF token -- it has no page to
    // read a <meta> tag from. Handing it back here is safe: a cross-origin caller can't read this
    // response, and any same-origin page could already read the token from its own markup.
    todoer_respond([
        'ok' => true,
        'public_key' => todoer_push_public_key(),
        'csrf_token' => todoer_csrf_token(),
    ]);
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