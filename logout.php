<?php
require_once __DIR__ . '/includes/bootstrap.php';
todoer_logout();
header('Location: login.php');
exit;
