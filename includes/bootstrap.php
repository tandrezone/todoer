<?php
// Never leak stack traces / file paths to the browser (they'd otherwise show up either in a
// raw PHP fatal-error page or inline in an API's JSON body). Errors and exceptions are logged
// server-side instead, and everything downstream gets a short, generic message.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false; // respect @-suppressed calls and error_reporting() level
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['ok' => false, 'error' => 'Something went wrong on our end. Please try again.']);
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/period.php';
require_once __DIR__ . '/assignment.php';

$GLOBALS['pdo'] = todoer_db();

// Execution/expiration/reassignment sweep, then check whether any period finished early
// (every task done/expired), then the original time-based period close. Order matters: a
// reassignment or a completion can be what makes a period newly eligible to close.
todoer_process_expirations($GLOBALS['pdo']);
todoer_process_deadline_notifications($GLOBALS['pdo']);
foreach (TODOER_LIST_TYPES as $listType) {
    todoer_maybe_finish_period_early($GLOBALS['pdo'], $listType);
}
todoer_close_elapsed_periods($GLOBALS['pdo']);
