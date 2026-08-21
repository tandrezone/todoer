<?php
/**
 * Sign in / join.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var string $csrfToken
 * @var string $mode      'login' | 'register'
 * @var string $error     Empty when there is nothing to report.
 * @var string $username  Whatever was typed, so a failed attempt does not clear the form.
 * @var string $inviteCode
 */
?>
<div class="auth-card">
  <h1>🏆 Todoer</h1>
  <p class="tagline">Turn your to-do list into a competition.</p>

  <div class="tabs">
    <button type="button" class="tab-btn <?= $mode === 'login' ? 'active' : '' ?>" data-mode="login">Log in</button>
    <button type="button" class="tab-btn <?= $mode === 'register' ? 'active' : '' ?>" data-mode="register">Join</button>
  </div>

  <?php if ($error !== ''): ?>
    <p class="error"><?= $e($error) ?></p>
  <?php endif; ?>

  <form method="post" id="auth-form" action="<?= $e($url->path('/login')) ?>">
    <input type="hidden" name="mode" id="mode-field" value="<?= $e($mode) ?>">
    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Username
      <input type="text" name="username" required autofocus maxlength="40" value="<?= $e($username) ?>">
    </label>
    <label>Password
      <input type="password" name="password" required minlength="8">
    </label>
    <label class="invite-field" id="invite-field"<?= $mode === 'register' ? '' : ' hidden' ?>>Invite code
      <span class="optional">(optional)</span>
      <input type="text" name="invite_code" maxlength="16" autocomplete="off"
             placeholder="joining friends? paste their code" value="<?= $e($inviteCode) ?>">
    </label>
    <button type="submit" class="btn-primary" id="submit-btn">
      <?= $mode === 'register' ? 'Create account &amp; join' : 'Log in' ?>
    </button>
  </form>

  <p class="hint">You play inside a <strong>group</strong>: everyone in it shares the same daily, weekly and
    monthly lists, competes on the same leaderboard, and wins prizes together. With an invite code you join an
    existing group; without one you get your own, private until you add someone &mdash; and either way, nobody
    outside your group sees your tasks or your standings.</p>
</div>
