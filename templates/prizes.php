<?php
/**
 * Prize history. The list itself is rendered by assets/js/prizes.js from /api/prizes.
 *
 * @var callable $e
 * @var \App\Domain\User\User $user
 * @var \App\Domain\Group\Group $group
 * @var callable $partial
 */
?>
<?= $partial('partials/topnav', [
    'user' => $user,
    'group' => $group,
    'activeNav' => 'prizes',
    'showPushButton' => false,
]) ?>

<main class="prizes-page">
  <h1>Prize history &mdash; <?= $e($group->name) ?></h1>
  <p class="hint">When a day, week or month ends, whoever in <strong><?= $e($group->name) ?></strong> scored the most
    points is crowned winner and wins a random prize from the pool. Ties are broken randomly. Each group runs its own
    competition, so only your group's winners appear here.</p>
  <div id="prize-list" class="prize-list"></div>
</main>
