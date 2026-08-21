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

// Every time in this app is a *wall-clock* time: a list opening at 06:30 and closing at 23:59,
// a period key of "today", a deadline. All of that runs off PHP's default timezone, which is
// commonly UTC -- so left alone, "06:30" would mean 07:30 on a British summer morning, and the
// day would roll over an hour early. So the app picks its own zone rather than inheriting the
// server's: TODOER_TIMEZONE if set, otherwise the constant below (change this one line, or set
// the variable, if the group lives somewhere else).
const TODOER_DEFAULT_TIMEZONE = 'Europe/London';
$todoerTimezone = getenv('TODOER_TIMEZONE') ?: TODOER_DEFAULT_TIMEZONE;
try {
    date_default_timezone_set($todoerTimezone);
} catch (Throwable $e) {
    error_log('Todoer: invalid timezone "' . $todoerTimezone . '", falling back to UTC.');
    date_default_timezone_set('UTC');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/period.php';
require_once __DIR__ . '/assignment.php';

$GLOBALS['pdo'] = todoer_db();

// Execution/expiration/reassignment sweep, then check whether any period finished early
// (every task done/expired), then the original time-based period close. Order matters: a
// reassignment or a completion can be what makes a period newly eligible to close.
//
// The sweeps run for every group on the install, not just the caller's: they're background
// bookkeeping (timers, deadlines, prize awards) that has to keep ticking for a group even while
// none of its members has the app open. They only ever *write* within a single group's own rows,
// so this is not a read path and leaks nothing between groups.
todoer_process_expirations($GLOBALS['pdo']);
todoer_process_deadline_notifications($GLOBALS['pdo']);
foreach (todoer_all_group_ids($GLOBALS['pdo']) as $todoerGroupId) {
    foreach (TODOER_LIST_TYPES as $listType) {
        todoer_maybe_finish_period_early($GLOBALS['pdo'], $todoerGroupId, $listType);
    }
    todoer_close_elapsed_periods($GLOBALS['pdo'], $todoerGroupId);
}
