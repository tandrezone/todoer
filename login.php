<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (todoer_current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$mode = ($_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($mode === 'register') {
        [$ok, $error] = todoer_register($username, $password);
    } else {
        [$ok, $error] = todoer_login($username, $password);
    }
    if ($ok) {
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Todoer &mdash; sign in</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <h1>🏆 Todoer</h1>
    <p class="tagline">Turn your to-do list into a competition.</p>

    <div class="tabs">
      <button type="button" class="tab-btn <?= $mode === 'login' ? 'active' : '' ?>" data-mode="login">Log in</button>
      <button type="button" class="tab-btn <?= $mode === 'register' ? 'active' : '' ?>" data-mode="register">Join</button>
    </div>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" id="auth-form">
      <input type="hidden" name="mode" id="mode-field" value="<?= htmlspecialchars($mode) ?>">
      <label>Username
        <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required minlength="3">
      </label>
      <button type="submit" class="btn-primary" id="submit-btn"><?= $mode === 'register' ? 'Create account & join' : 'Log in' ?></button>
    </form>
    <p class="hint">Everyone playing adds their own account, then adds tasks to the daily, weekly and monthly lists. Points add up automatically and the top scorer wins a prize when each period ends.</p>
  </div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('mode-field').value = btn.dataset.mode;
    document.getElementById('submit-btn').textContent = btn.dataset.mode === 'register' ? 'Create account & join' : 'Log in';
  });
});
</script>
</body>
</html>
