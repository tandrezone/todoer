<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = todoer_require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Todoer &mdash; Import from Google Keep</title>
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
    <a href="prizes.php">Prizes</a>
    <a href="logout.php">Log out</a>
  </nav>
</header>

<main class="import-page">
  <h1>Import from Google Keep</h1>

  <ol class="import-steps">
    <li>Go to <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a>, click <strong>Deselect all</strong>, then check only <strong>Keep</strong>, and export.</li>
    <li>Download and unzip the export. Inside you'll find a <code>Takeout/Keep/</code> folder full of <code>.json</code> files (one per note) &mdash; each also has a matching <code>.html</code> file you can ignore.</li>
    <li>Below, select either that whole <code>.zip</code> (if this server has PHP's zip extension) or just the <code>.json</code> files directly, then scan them.</li>
    <li>Pick which items to bring in and which list (daily/weekly/monthly) each goes to, then import.</li>
  </ol>

  <form id="scan-form" class="import-form">
    <label class="file-label">
      Keep export (.zip or one/many .json files)
      <input type="file" id="file-input" name="files" multiple accept=".zip,.json" required>
    </label>

    <div class="import-options">
      <label><input type="checkbox" id="opt-skip-archived" checked> Skip archived notes</label>
      <label><input type="checkbox" id="opt-skip-trashed" checked> Skip trashed notes</label>
      <label><input type="checkbox" id="opt-include-checked"> Also include items already checked off in Keep</label>
      <label class="plain-mode-label">
        Plain notes (no checklist):
        <select id="opt-plain-mode">
          <option value="line" selected>One task per line</option>
          <option value="title">One task from the note title</option>
          <option value="skip">Skip entirely</option>
        </select>
      </label>
    </div>

    <button type="submit" class="btn-primary">Scan files</button>
  </form>

  <p id="scan-status" class="hint"></p>

  <div id="preview" class="import-preview" hidden>
    <div class="preview-toolbar">
      <span id="preview-count"></span>
      <span class="spacer"></span>
      <button type="button" id="select-all">Select all</button>
      <button type="button" id="select-none">Select none</button>
      <label class="bulk-list">
        Set list for selected:
        <select id="bulk-list-type">
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
        <button type="button" id="apply-bulk-list">Apply</button>
      </label>
    </div>
    <ul id="candidate-list" class="candidate-list"></ul>
    <button type="button" id="commit-btn" class="btn-primary">Import selected tasks</button>
  </div>

  <p id="commit-status" class="hint"></p>
</main>

<script src="assets/js/import.js"></script>
</body>
</html>
