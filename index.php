<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = todoer_require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Todoer</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🏆 Todoer</div>
  <nav class="topnav">
    <span class="me" style="--me-color: <?= htmlspecialchars($user['color']) ?>">
      <span class="dot"></span><?= htmlspecialchars($user['username']) ?>
    </span>
    <a href="prizes.php">Prizes</a>
    <a href="import.php">Import</a>
    <a href="logout.php">Log out</a>
  </nav>
</header>

<div id="banner-slot"></div>

<main class="layout">
  <section class="lists">
    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $type => $label): ?>
    <div class="list-card" data-list-type="<?= $type ?>">
      <div class="list-card-head">
        <h2><?= $label ?></h2>
        <span class="period-label" data-period-label></span>
      </div>
      <ul class="task-list" data-task-list></ul>
      <form class="add-task-form" data-add-form>
        <input type="text" placeholder="Add a <?= strtolower($label) ?> task&hellip;" required maxlength="200">
        <button type="submit">+</button>
      </form>
    </div>
    <?php endforeach; ?>
  </section>

  <aside class="sidebar">
    <div class="board-card" data-board="daily"><h3>Today</h3><ol class="board-rows"></ol></div>
    <div class="board-card" data-board="weekly"><h3>This week</h3><ol class="board-rows"></ol></div>
    <div class="board-card" data-board="monthly"><h3>This month</h3><ol class="board-rows"></ol></div>
    <div class="board-card all-time" data-board="all_time"><h3>🏅 All-time</h3><ol class="board-rows"></ol></div>
  </aside>
</main>

<script src="assets/js/app.js"></script>
</body>
</html>
