<?php
/**
 * The dashboard: one unified Tasks list, the add/assign form, the team board and the leaderboard
 * sidebar.
 *
 * Everything dynamic on this page is fetched and rendered by assets/js/app.js from /api/tasks and
 * /api/leaderboard -- this template is the shell those results are poured into, which is why there
 * is no task markup here.
 *
 * Daily/weekly/monthly are still how a task's periodicity, points and Start/Stop/scoring cadence
 * work underneath (see App\Service\PeriodService) -- they just no longer get three separate list
 * cards. One task list shows everything; the "Repeats" field on the add form is what used to be
 * "which list", and the three small period pills below are what used to be each list's own
 * Start/Stop header.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var \App\Domain\User\User $user
 * @var \App\Domain\Group\Group $group
 * @var callable $partial
 */
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$periodicities = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
?>
<?= $partial('partials/topnav', [
    'user' => $user,
    'group' => $group,
    'activeNav' => 'dashboard',
    'showPushButton' => true,
]) ?>

<div id="banner-slot"></div>
<div id="notification-slot" class="notification-slot"></div>

<main class="layout">
  <section class="lists">
    <div class="list-card" data-tasks-card>
      <div class="list-card-head">
        <h2>Tasks</h2>
      </div>
      <div class="period-pills" data-period-pills>
        <?php foreach ($periodicities as $type => $label): ?>
        <div class="period-pill" data-list-type="<?= $e($type) ?>">
          <div class="period-pill-row">
            <span class="period-pill-label"><?= $e($label) ?></span>
            <span class="period-label" data-period-label></span>
            <button type="button" class="start-btn" data-start-btn
                    title="Assign any not-yet-assigned <?= $e(strtolower($label)) ?> tasks">Start</button>
          </div>
          <p class="unassigned-hint" data-unassigned-hint hidden></p>
        </div>
        <?php endforeach; ?>
      </div>
      <ul class="task-list" data-task-list></ul>
      <form class="add-task-form" data-add-form>
        <div class="add-task-row">
          <input type="text" name="title" placeholder="Add a task&hellip;" required maxlength="200">
          <button type="submit">+</button>
        </div>
        <div class="add-task-row add-task-row-secondary">
          <label class="repeats-field">Repeats
            <select name="list_type" class="list-type-select">
              <?php foreach ($periodicities as $type => $label): ?>
                <option value="<?= $e($type) ?>"><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="times-field">How many times
            <input type="number" name="times_per_period" min="1" max="24" value="1">
            <span class="field-note">Splits the period (or your window below) into this many equal slots -- each one is a separate, points-earning occurrence.</span>
          </label>
        </div>
        <details class="task-options">
          <summary>+ Assign, prioritize, or set a time window</summary>
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

            <label class="window-daily">Window start (time of day)
              <input type="time" name="window_start_time">
            </label>
            <label class="window-daily">Window end (time of day)
              <input type="time" name="window_end_time">
            </label>
            <?php foreach (['start' => 'Window start (day)', 'end' => 'Window end (day)'] as $bound => $boundLabel): ?>
              <label class="window-weekly"><?= $e($boundLabel) ?>
                <select name="window_<?= $e($bound) ?>_day">
                  <option value="">&mdash;</option>
                  <?php foreach ($weekdays as $value => $dayName): ?>
                    <option value="<?= $e((string) $value) ?>"><?= $e($dayName) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endforeach; ?>
            <label class="window-monthly">Window start (day of month)
              <input type="number" name="window_start_dom" min="1" max="31" placeholder="e.g. 5">
            </label>
            <label class="window-monthly">Window end (day of month)
              <input type="number" name="window_end_dom" min="1" max="31" placeholder="e.g. 20">
            </label>
          </div>
        </details>
      </form>
      <details class="team-board">
        <summary>Team board <span class="board-count" data-board-count></span></summary>
        <ul class="board-list" data-board-list></ul>
      </details>
    </div>
  </section>

  <aside class="sidebar">
    <div class="board-card" data-board="daily"><h3>Today</h3><ol class="board-rows"></ol></div>
    <div class="board-card" data-board="weekly"><h3>This week</h3><ol class="board-rows"></ol></div>
    <div class="board-card" data-board="monthly"><h3>This month</h3><ol class="board-rows"></ol></div>
    <div class="board-card all-time" data-board="all_time"><h3>🏅 All-time</h3><ol class="board-rows"></ol></div>
  </aside>
</main>

<dialog id="task-edit-dialog" class="task-edit-dialog">
  <form method="dialog" id="task-edit-form">
    <div class="dialog-head">
      <h2>Edit task</h2>
      <button type="button" class="dialog-close" data-close-edit title="Close">&times;</button>
    </div>
    <input type="hidden" name="task_id">
    <input type="hidden" name="list_type">
    <label>Task name
      <input type="text" name="title" required maxlength="200">
    </label>
    <div class="task-options-grid">
      <label>Assign to
        <select name="assigned_type" class="edit-assigned-type-select">
          <option value="ANY_USER">Anyone (shared pool)</option>
          <option value="SPECIFIC_USER">A specific person</option>
        </select>
      </label>
      <label class="edit-specific-user-field">Person
        <select name="assigned_user_id" class="edit-specific-user-select"></select>
      </label>
      <label>Priority
        <select name="priority" class="edit-priority-select">
          <option value="LOW">Low</option>
          <option value="MODERATE">Moderate</option>
          <option value="HIGH">High (short timer)</option>
        </select>
      </label>
      <label class="edit-time-limit-field">Time limit once assigned (minutes)
        <input type="number" name="time_limit_minutes" min="1" placeholder="no timer">
      </label>
      <label>How many times per period
        <input type="number" name="times_per_period" min="1" max="24" value="1">
        <span class="field-note">Resizes this task's slice of the period -- it does not add or remove the other occurrences.</span>
      </label>
      <label>Which occurrence is this
        <input type="number" name="occurrence_index" min="1" max="24" value="1">
      </label>
      <label class="edit-window-daily">Window start (time of day)
        <input type="time" name="window_start_time">
      </label>
      <label class="edit-window-daily">Window end (time of day)
        <input type="time" name="window_end_time">
      </label>
      <?php foreach (['start' => 'Window start (day)', 'end' => 'Window end (day)'] as $bound => $boundLabel): ?>
        <label class="edit-window-weekly"><?= $e($boundLabel) ?>
          <select name="window_<?= $e($bound) ?>_day">
            <option value="">&mdash;</option>
            <?php foreach ($weekdays as $value => $dayName): ?>
              <option value="<?= $e((string) $value) ?>"><?= $e($dayName) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <label class="edit-window-monthly">Window start (day of month)
        <input type="number" name="window_start_dom" min="1" max="31">
      </label>
      <label class="edit-window-monthly">Window end (day of month)
        <input type="number" name="window_end_dom" min="1" max="31">
      </label>
    </div>
    <div class="dialog-actions">
      <button type="button" data-close-edit>Cancel</button>
      <button type="submit" class="dialog-save">Save changes</button>
    </div>
  </form>
</dialog>
