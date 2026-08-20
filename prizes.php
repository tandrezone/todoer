<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = todoer_require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(todoer_csrf_token()) ?>">
<title>Todoer &mdash; Prizes</title>
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="manifest" href="site.webmanifest">
<meta name="theme-color" content="#3559b8">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🏆 Todoer</div>
  <nav class="topnav">
    <span class="me" style="--me-color: <?= htmlspecialchars($user['color']) ?>">
      <span class="dot"></span><?= htmlspecialchars($user['username']) ?>
    </span>
    <a href="index.php">Dashboard</a>
    <a href="import.php">Import</a>
    <a href="logout.php">Log out</a>
  </nav>
</header>

<main class="prizes-page">
  <h1>Prize history</h1>
  <p class="hint">When a day, week or month ends, whoever scored the most points is crowned winner and wins a random prize from the pool. Ties are broken randomly.</p>
  <div id="prize-list" class="prize-list"></div>
</main>

<script src="assets/js/prizes.js"></script>
</body>
</html>
