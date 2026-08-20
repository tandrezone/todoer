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
        <div class="list-card-head-right">
          <span class="period-label" data-period-label></span>
          <button type="button" class="start-btn" data-start-btn title="Assign any not-yet-assigned tasks in this list">Start <?= strtolower($label) ?></button>
        </div>
      </div>
      <p class="unassigned-hint" data-unassigned-hint hidden></p>
      <ul class="task-list" data-task-list></ul>
      <form class="add-task-form" data-add-form>
        <div class="add-task-row">
          <input type="text" name="title" placeholder="Add a <?= strtolower($label) ?> task&hellip;" required maxlength="200">
          <button type="submit">+</button>
        </div>
        <details class="task-options">
          <summary>Assignment &amp; timing options</summary>
          <div class="task-options-grid">
            <label>Assign to
              <select name="assigned_type" class="assigned-type-select">
                <option value="ANY_USER">Anyone (shared pool)</option>
                <option value="SPECIFIC_USER">A specific person</option>
              </select>
            </label>
            <label class="specific-user-field" hidden>Person
              <select name="assigned_user_id" class="specific-user-select"></select>
            </label>
            <label>Priority
              <select name="priority" class="priority-select">
                <option value="LOW">Low</option>
                <option value="MODERATE" selected>Moderate</option>
                <option value="HIGH">High (short timer, auto-reassigned if missed)</option>
              </select>
            </label>
            <label class="time-limit-field">Time limit once assigned (minutes)
              <input type="number" name="time_limit_minutes" min="1" placeholder="no timer, just the window">
            </label>
            <label>Window start
              <input type="datetime-local" name="window_start">
            </label>
            <label>Window end
              <input type="datetime-local" name="window_end">
            </label>
          </div>
        </details>
      </form>
      <details class="team-board">
        <summary>Team board <span class="board-count" data-board-count></span></summary>
        <ul class="board-list" data-board-list></ul>
      </details>
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
