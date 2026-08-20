<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (todoer_current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$mode = ($_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals(todoer_csrf_token(), $postedToken)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if ($mode === 'register') {
            [$ok, $error] = todoer_register($username, $password, $_POST['invite_code'] ?? '');
        } else {
            [$ok, $error] = todoer_login($username, $password);
        }
        if ($ok) {
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Todoer &mdash; sign in</title>
<link rel="icon" href="favicon.ico" sizes="32x32">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="manifest" href="site.webmanifest">
<meta name="theme-color" content="#3559b8">
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(todoer_csrf_token()) ?>">
      <label>Username
        <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </label>
      <label>Password
        <input type="password" name="password" required minlength="8">
      </label>
      <label class="invite-field" id="invite-field"<?= $mode === 'register' ? '' : ' hidden' ?>>Invite code <span class="optional">(optional)</span>
        <input type="text" name="invite_code" maxlength="16" autocomplete="off" placeholder="joining friends? paste their code"
               value="<?= htmlspecialchars($_POST['invite_code'] ?? '') ?>">
      </label>
      <button type="submit" class="btn-primary" id="submit-btn"><?= $mode === 'register' ? 'Create account & join' : 'Log in' ?></button>
    </form>
    <p class="hint">You play inside a <strong>group</strong>: everyone in it shares the same daily, weekly and monthly lists, competes on the same leaderboard, and wins prizes together. With an invite code you join an existing group; without one you get your own, private until you add someone &mdash; and either way, nobody outside your group sees your tasks or your standings.</p>
  </div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('mode-field').value = btn.dataset.mode;
    // The invite code only means anything when creating an account.
    document.getElementById('invite-field').hidden = btn.dataset.mode !== 'register';
    document.getElementById('submit-btn').textContent = btn.dataset.mode === 'register' ? 'Create account & join' : 'Log in';
  });
});
</script>
</body>
</html>
