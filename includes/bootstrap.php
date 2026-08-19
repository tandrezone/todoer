<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/period.php';

$GLOBALS['pdo'] = todoer_db();
todoer_close_elapsed_periods($GLOBALS['pdo']);
