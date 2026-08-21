<?php
/**
 * The top bar. Which page you are on is marked rather than hidden, so the nav does not change shape
 * as you move around.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var \App\Domain\User\User $user
 * @var \App\Domain\Group\Group|null $group
 * @var string $activeNav
 * @var string $csrfToken
 * @var bool   $showPushButton
 */
$links = [
    'dashboard' => ['label' => 'Dashboard', 'href' => $url->path('/')],
    'prizes' => ['label' => 'Prizes', 'href' => $url->path('/prizes')],
    'import' => ['label' => 'Import', 'href' => $url->path('/import')],
    'backup' => ['label' => 'Backup', 'href' => $url->path('/backup')],
];
?>
<header class="topbar">
  <div class="brand">🏆 Todoer</div>
  <nav class="topnav">
    <span class="me" style="--me-color: <?= $e($user->color) ?>">
      <span class="dot"></span><?= $e($user->username) ?>
    </span>
    <?php if ($group !== null): ?>
      <a href="<?= $e($url->path('/group')) ?>" class="group-chip<?= $activeNav === 'group' ? ' active' : '' ?>"
         title="Everyone who shares these lists and competes with you">
        <span class="group-chip-icon">👥</span><?= $e($group->name) ?>
      </a>
    <?php endif; ?>
    <?php foreach ($links as $key => $link): ?>
      <?php if ($key === 'dashboard' && $activeNav === 'dashboard') {
          continue;
      } ?>
      <a href="<?= $e($link['href']) ?>"<?= $activeNav === $key ? ' class="active"' : '' ?>><?= $e($link['label']) ?></a>
    <?php endforeach; ?>
    <?php if ($showPushButton): ?>
      <button type="button" id="enable-push" class="push-btn" hidden>Enable notifications</button>
    <?php endif; ?>
    <?php /* Signing out changes state, so it is a POST with the session's CSRF token rather than a link. */ ?>
    <form method="post" action="<?= $e($url->path('/logout')) ?>" class="logout-form">
      <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="link-btn">Log out</button>
    </form>
  </nav>
</header>
