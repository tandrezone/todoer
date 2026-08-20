<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = todoer_require_login();
$group = todoer_require_group($GLOBALS['pdo'], (int) $user['id'], $user['username']);
$cssV = @filemtime(__DIR__ . '/assets/css/style.css') ?: time();
$jsV = @filemtime(__DIR__ . '/assets/js/group.js') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(todoer_csrf_token()) ?>">
<title>Todoer &mdash; Group</title>
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="manifest" href="site.webmanifest">
<meta name="theme-color" content="#3559b8">
<link rel="stylesheet" href="assets/css/style.css?v=<?= $cssV ?>">
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
    <a href="import.php">Import</a>
    <a href="backup.php">Backup</a>
    <a href="logout.php">Log out</a>
  </nav>
</header>

<main class="group-page">
  <h1 id="group-title"><?= htmlspecialchars($group['name']) ?></h1>
  <p class="hint">
    Your group is the whole game: you see each other's tasks, you compete on the same
    leaderboard, and prizes are awarded inside the group. People outside it can't see any of
    this &mdash; and you can't see theirs.
  </p>

  <div id="group-message" class="group-message" hidden></div>

  <section class="group-card">
    <h2>Members <span class="board-count" id="member-count"></span></h2>
    <ul class="member-list" id="member-list"></ul>
  </section>

  <section class="group-card" id="admin-tools" hidden>
    <h2>Add someone</h2>

    <form class="group-form" id="add-member-form">
      <label>They already have a Todoer account
        <input type="text" name="username" placeholder="their username" autocomplete="off">
      </label>
      <button type="submit">Add to group</button>
      <p class="hint">They'll move into this group. Their old tasks and points stay behind in their previous group.</p>
    </form>

    <form class="group-form" id="create-member-form">
      <label>Or create an account for them
        <input type="text" name="username" placeholder="username" autocomplete="off" maxlength="40">
      </label>
      <label>Their password
        <input type="password" name="password" placeholder="at least 8 characters" autocomplete="new-password">
      </label>
      <button type="submit">Create &amp; add</button>
      <p class="hint">Hand them the password afterwards &mdash; they can change nothing about it here, so pick something you can pass on.</p>
    </form>

    <h2>Invite code</h2>
    <p class="hint">Anyone with this code can join the group and see everything in it. Roll it if it gets out.</p>
    <div class="invite-row">
      <code id="invite-code">&mdash;</code>
      <button type="button" id="regenerate-code">Generate a new code</button>
    </div>

    <h2>Group name</h2>
    <form class="group-form" id="rename-form">
      <label>Name
        <input type="text" name="name" maxlength="60" value="<?= htmlspecialchars($group['name']) ?>">
      </label>
      <button type="submit">Rename</button>
    </form>
  </section>

  <section class="group-card">
    <h2>Join another group</h2>
    <form class="group-form" id="join-form">
      <label>Invite code
        <input type="text" name="invite_code" placeholder="e.g. K7PQ2XVM" autocomplete="off" maxlength="16">
      </label>
      <button type="submit">Join</button>
      <p class="hint">You can only be in one group at a time, so joining moves you out of this one. Your tasks and points stay where they were earned.</p>
    </form>
    <button type="button" id="leave-group" class="danger-btn">Leave this group</button>
  </section>
</main>

<script src="assets/js/group.js?v=<?= $jsV ?>"></script>
</body>
</html>
