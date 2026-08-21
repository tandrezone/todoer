<?php
/**
 * Backup and restore of a group's tasks.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var \App\Domain\User\User $user
 * @var \App\Domain\Group\Group $group
 * @var callable $partial
 */
?>
<?= $partial('partials/topnav', [
    'user' => $user,
    'group' => $group,
    'activeNav' => 'backup',
    'showPushButton' => false,
]) ?>

<main class="import-page">
  <h1>Backup &amp; restore &mdash; <?= $e($group->name) ?></h1>

  <section class="import-form">
    <h2>Export</h2>
    <p class="hint">Downloads every task belonging to <strong><?= $e($group->name) ?></strong> &mdash; every field
      (list, period, title, points, status, timing, priority, assignment) &mdash; as a single JSON file you can keep
      as a backup or move to another install.</p>
    <a href="<?= $e($url->path('/api/export/tasks')) ?>" class="btn-primary">Export tasks as JSON</a>
  </section>

  <section class="import-form">
    <h2>Import</h2>
    <p class="hint">Restores tasks from a JSON file previously produced by the export above. Tasks land in
      <strong><?= $e($group->name) ?></strong>; a holder or assignee who isn't a member of this group is imported as
      unassigned instead.</p>
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
