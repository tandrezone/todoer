<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/period.php';
require_once __DIR__ . '/assignment.php';

$GLOBALS['pdo'] = todoer_db();

// Execution/expiration/reassignment sweep, then check whether any period finished early
// (every task done/expired), then the original time-based period close. Order matters: a
// reassignment or a completion can be what makes a period newly eligible to close.
todoer_process_expirations($GLOBALS['pdo']);
foreach (TODOER_LIST_TYPES as $listType) {
    todoer_maybe_finish_period_early($GLOBALS['pdo'], $listType);
}
todoer_close_elapsed_periods($GLOBALS['pdo']);
