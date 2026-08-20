<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = todoer_require_login();
$group = todoer_require_group($GLOBALS['pdo'], (int) $user['id'], $user['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(todoer_csrf_token()) ?>">
<title>Todoer &mdash; Backup</title>
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
    <a href="group.php">Group</a>
    <a href="prizes.php">Prizes</a>
    <a href="import.php">Import from Keep</a>
    <a href="backup.php" class="active">Backup</a>
    <a href="logout.php">Log out</a>
  </nav>
</header>

<main class="import-page">
  <h1>Backup &amp; restore &mdash; <?= htmlspecialchars($group['name']) ?></h1>

  <section class="import-form">
    <h2>Export</h2>
    <p class="hint">Downloads every task belonging to <strong><?= htmlspecialchars($group['name']) ?></strong> &mdash; every field (list, period, title, points, status, timing, priority, assignment) &mdash; as a single JSON file you can keep as a backup or move to another install.</p>
    <a href="api/export.php" class="btn-primary">Export tasks as JSON</a>
  </section>

  <section class="import-form">
    <h2>Import</h2>
    <p class="hint">Restores tasks from a JSON file previously produced by the export above. Tasks land in <strong><?= htmlspecialchars($group['name']) ?></strong>; a holder or assignee that isn't a member of this group is imported as unassigned instead.</p>
    <form id="import-json-form">
      <label class="file-label">
        Tasks export (.json)
        <input type="file" id="import-json-file" name="file" accept=".json" required>
      </label>
      <button type="submit" class="btn-primary">Import tasks</button>
    </form>
    <p id="import-json-status" class="hint"></p>
  </section>
</main>

<script src="assets/js/backup.js"></script>
</body>
</html>
