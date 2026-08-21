// Request URLs are built from the base path the server rendered into the page, so the app works
// whether it is served from https://example.com/ or http://nas.local/todoer/ -- and the endpoints
// are clean paths handled by the front controller (public/index.php) rather than .php files.
const BASE_PATH = document.querySelector('meta[name="base-path"]')?.content || '/';
const appUrl = (path) => BASE_PATH + String(path).replace(/^\/+/, '');

const LIST_TYPES = ['daily', 'weekly', 'monthly'];
const LIST_LABEL = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' };
const PRIORITY_LABEL = { HIGH: 'High', MODERATE: 'Moderate', LOW: 'Low' };
const STATUS_LABEL = { unassigned: 'Unassigned', open: 'In progress', done: 'Done', expired: 'Missed' };

let knownUserCount = 0;
const taskCache = new Map();

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function setupPushNotifications() {
  const button = document.getElementById('enable-push');
  if (!button || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

  const config = await jsonFetch(appUrl('api/notifications'));
  // No server key means push isn't available at all (library missing / keys unwritable), and a
  // blocked permission can't be re-asked from script -- either way, don't offer a dead button.
  if (!config.public_key || Notification.permission === 'denied') return;

  const registration = await navigator.serviceWorker.register(appUrl('service-worker.js'), { scope: BASE_PATH });
  const serverKey = urlBase64ToUint8Array(config.public_key);
  let subscription = await registration.pushManager.getSubscription();

  // A subscription is signed with the VAPID key it was created with. If the server's key has
  // changed since (regenerated keypair, restored-from-backup install), that subscription is dead
  // -- the push service rejects it with a 403 that only shows up in the server log. Detect the
  // mismatch and start over rather than sitting on a subscription that can never deliver.
  if (subscription && !sameKey(subscription.options?.applicationServerKey, serverKey)) {
    try { await subscription.unsubscribe(); } catch (error) { /* re-subscribing is what matters */ }
    subscription = null;
  }

  if (subscription) {
    await sendSubscription(subscription);
    button.hidden = true;
    return;
  }

  button.hidden = false;
  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') return;
      const fresh = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: serverKey,
      });
      await sendSubscription(fresh);
      button.hidden = true;
    } catch (err) {
      alert('Could not turn on notifications: ' + err.message);
    } finally {
      // Left enabled on failure on purpose -- a denied prompt or a transient error should be
      // retryable instead of leaving a permanently inert button on the page.
      button.disabled = false;
    }
  });
}

function sendSubscription(subscription) {
  return jsonFetch(appUrl('api/notifications'), {
    method: 'POST',
    body: JSON.stringify({ action: 'subscribe', subscription }),
  });
}

/** Byte-compare the key a subscription was made with against the one the server is using now. */
function sameKey(a, b) {
  if (!a || !b) return false;
  const left = new Uint8Array(a);
  if (left.length !== b.length) return false;
  return left.every((byte, i) => byte === b[i]);
}

function urlBase64ToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
}

async function jsonFetch(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    ...options,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || 'Request failed');
  }
  return data;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

const WEEKDAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function ordinal(n) {
  const suffixes = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
}

// Windows are shown in whatever grain matches the list's natural cadence, since the full
// date is redundant: a daily window repeats every day (so just the time matters), a weekly
// window only needs which day of the week, and a monthly one only needs which day of the
// month. Storage-wise these are still full datetimes (see App\Domain\Period\Period) -- this only
// affects how they're displayed.
function formatWindow(dt, listType) {
  if (!dt) return '';
  // SQLite datetimes come back as "YYYY-MM-DD HH:MM:SS"; Safari's Date parser wants a "T".
  const d = new Date(dt.replace(' ', 'T'));
  if (isNaN(d)) return dt;
  if (listType === 'daily') {
    return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }
  if (listType === 'weekly') {
    return WEEKDAY_NAMES[(d.getDay() + 6) % 7]; // JS getDay(): Sun=0..Sat=6 -> Mon=0..Sun=6
  }
  return ordinal(d.getDate()); // monthly: day-of-month only, no month name
}

// Mirrors App\Domain\Enum\ClaimDenial::message() on the server -- the server decides, this only
// phrases it for the board.
const CLAIM_BLOCKED_LABEL = {
  locked: 'locked to them',
  not_open_yet: 'window not open yet',
  window_closed: 'window closed',
  not_open: 'settled',
  not_running: 'theirs while the list is stopped',
  own: '',
};

function claimBlockedLabel(reason) {
  return CLAIM_BLOCKED_LABEL[reason] ?? 'not available';
}

function priorityBadgeHtml(priority) {
  const label = PRIORITY_LABEL[priority] || priority;
  return `<span class="chip priority-badge priority-${(priority || '').toLowerCase()}">${escapeHtml(label)}</span>`;
}

// Keeps the header chip honest while the page stays open: the group can be renamed, or someone
// can be added or removed, from another tab or by another member. `users` here is always just
// this group's members -- the API never returns anyone else.
function updateGroupChip(group) {
  if (!group) return;
  const chip = document.querySelector('.group-chip');
  if (!chip) return;
  const icon = chip.querySelector('.group-chip-icon')?.outerHTML || '';
  chip.innerHTML = icon + escapeHtml(group.name);
  chip.title = group.member_count === 1
    ? 'Just you for now — add someone to compete'
    : `${group.member_count} people share these lists and compete with you`;
}

function populateUserSelects(users) {
  document.querySelectorAll('.specific-user-select, .edit-specific-user-select').forEach(select => {
    select.innerHTML = users.map(u => `<option value="${u.id}">${escapeHtml(u.username)}</option>`).join('');
  });
}

async function loadTasks() {
  const data = await jsonFetch(appUrl('api/tasks'));
  renderNotifications(data.notifications || []);
  updateGroupChip(data.group);
  taskCache.clear();

  if (data.users.length !== knownUserCount) {
    knownUserCount = data.users.length;
    populateUserSelects(data.users);
  }

  // Daily/weekly/monthly each still have their own Start/Stop, period and scoring underneath
  // (see App\Service\PeriodService) -- they just no longer get three separate list cards. Each
  // gets a small pill instead, and the API already folds weekly/monthly tasks into "daily"'s
  // merged items/board (see App\Service\TaskBoardService::foldIntoDaily), which is what the one
  // task list and one team board below read from.
  LIST_TYPES.forEach(type => {
    const pill = document.querySelector(`.period-pill[data-list-type="${type}"]`);
    const info = data.tasks[type];
    pill.querySelector('[data-period-label]').textContent = info.label;
    pill.classList.toggle('is-running', info.running);

    const startBtn = pill.querySelector('[data-start-btn]');
    startBtn.textContent = info.running ? 'Stop' : 'Start';
    startBtn.dataset.running = info.running ? '1' : '0';

    const hint = pill.querySelector('[data-unassigned-hint]');
    if (!info.running && info.unassigned_count > 0) {
      hint.hidden = false;
      hint.textContent = `${info.unassigned_count} task${info.unassigned_count === 1 ? '' : 's'} waiting to be assigned.`;
    } else {
      hint.hidden = true;
    }
  });

  // The one merged list: the API already folds weekly/monthly tasks into "daily"'s items/board,
  // ordered mine-first while any of the three is running.
  const merged = data.tasks.daily;
  const listEl = document.querySelector('[data-task-list]');
  listEl.innerHTML = '';
  if (merged.items.length === 0) {
    listEl.innerHTML = merged.running
      ? '<li class="empty-hint">Nothing on the list.</li>'
      : '<li class="empty-hint">Nothing assigned to you yet — add a task or ask someone to run Start.</li>';
  }
  // In game mode the list is the whole board, ordered mine-first by the API. A divider marks
  // where "yours" ends and "anyone's" begins, so the top of the list still reads as your plate.
  let dividerPlaced = !merged.running;
  merged.items.forEach(task => {
    const taskType = task.list_type;
    taskCache.set(String(task.id), { ...task, listType: taskType });

    if (!dividerPlaced && !task.is_mine) {
      dividerPlaced = true;
      const divider = document.createElement('li');
      divider.className = 'list-divider';
      divider.innerHTML = '<span>Up for grabs</span>';
      listEl.appendChild(divider);
    }

    const info = data.tasks[taskType];
    const li = document.createElement('li');
    li.className = 'task-item'
      + (task.status === 'done' ? ' done' : '')
      + (task.status === 'expired' ? ' expired' : '')
      + (info.running && !task.is_mine ? ' not-mine' : '')
      + (info.running && task.claimable ? ' claimable' : '');
    const meta = [];
    if (task.window_start || task.window_end) {
      const startStr = formatWindow(task.window_start, taskType);
      const endStr = formatWindow(task.window_end, taskType);
      meta.push(`<span class="task-window">${escapeHtml(startStr)}${endStr ? ' &rarr; ' + escapeHtml(endStr) : ''}</span>`);
    }
    if (task.occurrence_count > 1) {
      meta.push(`<span class="occurrence-hint">${task.occurrence_index}/${task.occurrence_count}</span>`);
    }
    if (task.priority === 'HIGH' && task.status === 'open') {
      meta.push('<span class="timer-hint">⏱ short timer</span>');
    } else if (task.time_limit_minutes && task.status === 'open') {
      meta.push(`<span class="timer-hint">⏱ ${task.time_limit_minutes}m</span>`);
    }
    // Who has it, and (when you can't take it) why not. Daily's own stopped view is always just
    // your own tasks, so this only matters there while running -- but a folded weekly/monthly
    // task can belong to someone else even while daily is stopped, so those show a holder
    // regardless.
    let holderHtml = '';
    if ((info.running || taskType !== 'daily') && !task.is_mine) {
      const who = task.holder_username
        ? `<span class="dot" style="background:${escapeHtml(task.holder_color)}"></span>${escapeHtml(task.holder_username)}`
        : '<em>unassigned</em>';
      holderHtml = `<span class="task-holder">${who}</span>`;
      if (task.claimable) {
        meta.push('<span class="claim-hint">yours if you get there first</span>');
      } else if (task.status === 'open') {
        meta.push(`<span class="claim-blocked">${escapeHtml(claimBlockedLabel(task.claim_reason))}</span>`);
      }
    }
    const checkbox = task.can_complete
      ? `<input type="checkbox" ${task.status === 'done' ? 'checked' : ''} data-task-id="${task.id}"
           ${task.claimable ? 'title="Do this one instead — the points come to you"' : ''}>`
      : `<span class="task-check-placeholder" title="${escapeHtml(claimBlockedLabel(task.claim_reason))}"></span>`;
    li.innerHTML = `
      ${checkbox}
      <span class="task-body">
        <span class="task-title">${escapeHtml(task.title)}</span>
        ${meta.length ? `<span class="task-meta">${meta.join(' &middot; ')}</span>` : ''}
      </span>
      ${holderHtml}
      <span class="chip origin-badge">${LIST_LABEL[taskType]}</span>
      ${priorityBadgeHtml(task.priority)}
      <span class="chip task-points">+${task.points}</span>
      ${info.running ? '' : `
        <button class="task-edit" data-edit-id="${task.id}" title="Edit">&#9998;</button>
        <button class="task-del" data-del-id="${task.id}" title="Delete">&times;</button>
      `}
    `;
    listEl.appendChild(li);
  });

  // The team board is likewise one merged panel: every list's board rows, done ones pushed to
  // the bottom (mirroring App\Service\TaskBoardService::sortBoard), tie-broken by creation order.
  const boardTasks = LIST_TYPES.flatMap(type => data.tasks[type].board);
  boardTasks.sort((a, b) => {
    const aDone = a.status === 'done' ? 1 : 0;
    const bDone = b.status === 'done' ? 1 : 0;
    if (aDone !== bDone) return aDone - bDone;
    return String(a.created_at).localeCompare(String(b.created_at));
  });
  const boardList = document.querySelector('[data-board-list]');
  const boardCount = document.querySelector('[data-board-count]');
  boardCount.textContent = `(${boardTasks.length})`;
  boardList.innerHTML = '';
  if (boardTasks.length === 0) {
    boardList.innerHTML = '<li class="empty-hint">No tasks yet.</li>';
  }
  boardTasks.forEach(task => {
    taskCache.set(String(task.id), { ...task, listType: task.list_type });
    const li = document.createElement('li');
    li.className = 'board-task-row';
    const holder = task.holder_username
      ? `<span class="dot" style="background:${escapeHtml(task.holder_color)}"></span>${escapeHtml(task.holder_username)}`
      : (task.assigned_type === 'SPECIFIC_USER' ? '<em>locked, not yet assigned</em>' : '<em>unassigned</em>');
    const boardStartStr = formatWindow(task.window_start, task.list_type);
    const boardEndStr = formatWindow(task.window_end, task.list_type);
    const windowHtml = (boardStartStr || boardEndStr)
      ? `<span class="task-window">${escapeHtml(boardStartStr)}${boardEndStr ? ' &rarr; ' + escapeHtml(boardEndStr) : ''}</span>`
      : '';
    li.innerHTML = `
      <span class="chip status-chip status-${task.status}">${STATUS_LABEL[task.status] || task.status}</span>
      <span class="task-title">${escapeHtml(task.title)}</span>
      ${windowHtml}
      ${task.occurrence_count > 1 ? `<span class="occurrence-hint">${task.occurrence_index}/${task.occurrence_count}</span>` : ''}
      <span class="chip origin-badge">${LIST_LABEL[task.list_type]}</span>
      ${priorityBadgeHtml(task.priority)}
      <span class="board-holder">${holder}</span>
      ${task.can_edit && !data.tasks[task.list_type].running ? `<button class="task-edit" data-edit-id="${task.id}" title="Edit">&#9998;</button>` : ''}
    `;
    boardList.appendChild(li);
  });
}

function setEditWindowFields(form, task) {
  const type = task.listType;
  form.querySelectorAll('[class*="edit-window-"]').forEach(field => { field.hidden = true; });
  if (type === 'daily') {
    form.querySelector('.edit-window-daily').hidden = false;
    form.querySelectorAll('.edit-window-daily')[1].hidden = false;
    form.elements.window_start_time.value = task.window_start ? task.window_start.slice(11, 16) : '';
    form.elements.window_end_time.value = task.window_end ? task.window_end.slice(11, 16) : '';
  } else if (type === 'weekly') {
    form.querySelectorAll('.edit-window-weekly').forEach(field => { field.hidden = false; });
    const start = task.window_start ? new Date(task.window_start.replace(' ', 'T')).getDay() : '';
    const end = task.window_end ? new Date(task.window_end.replace(' ', 'T')).getDay() : '';
    form.elements.window_start_day.value = start === '' ? '' : String(start === 0 ? 7 : start);
    form.elements.window_end_day.value = end === '' ? '' : String(end === 0 ? 7 : end);
  } else {
    form.querySelectorAll('.edit-window-monthly').forEach(field => { field.hidden = false; });
    form.elements.window_start_dom.value = task.window_start ? String(Number(task.window_start.slice(8, 10))) : '';
    form.elements.window_end_dom.value = task.window_end ? String(Number(task.window_end.slice(8, 10))) : '';
  }
}

function openTaskEditor(task) {
  const dialog = document.getElementById('task-edit-dialog');
  const form = document.getElementById('task-edit-form');
  form.reset();
  form.elements.task_id.value = task.id;
  form.elements.list_type.value = task.listType;
  form.elements.title.value = task.title;
  form.elements.assigned_type.value = task.assigned_type;
  form.elements.assigned_user_id.value = task.assigned_user_id || '';
  form.elements.priority.value = task.priority;
  form.elements.time_limit_minutes.value = task.time_limit_minutes || '';
  form.elements.times_per_period.value = task.occurrence_count || 1;
  form.elements.occurrence_index.value = task.occurrence_index || 1;
  form.querySelector('.edit-specific-user-field').hidden = task.assigned_type !== 'SPECIFIC_USER';
  form.querySelector('.edit-time-limit-field').classList.toggle('disabled-field', task.priority === 'HIGH');
  form.elements.time_limit_minutes.disabled = task.priority === 'HIGH';
  setEditWindowFields(form, task);
  dialog.showModal();
}

async function saveTaskEdit(form) {
  const type = form.elements.list_type.value;
  const payload = {
    action: 'edit', task_id: form.elements.task_id.value, title: form.elements.title.value.trim(),
    assigned_type: form.elements.assigned_type.value, assigned_user_id: form.elements.assigned_user_id.value,
    priority: form.elements.priority.value, time_limit_minutes: form.elements.time_limit_minutes.value || null,
    times_per_period: form.elements.times_per_period.value || 1,
    occurrence_index: form.elements.occurrence_index.value || 1,
  };
  if (type === 'daily') {
    payload.window_start_time = form.elements.window_start_time.value || null;
    payload.window_end_time = form.elements.window_end_time.value || null;
  } else if (type === 'weekly') {
    payload.window_start_day = form.elements.window_start_day.value || null;
    payload.window_end_day = form.elements.window_end_day.value || null;
  } else {
    payload.window_start_dom = form.elements.window_start_dom.value || null;
    payload.window_end_dom = form.elements.window_end_dom.value || null;
  }
  await jsonFetch(appUrl('api/tasks'), { method: 'POST', body: JSON.stringify(payload) });
  document.getElementById('task-edit-dialog').close();
  await loadTasks();
}

function renderNotifications(notifications) {
  const slot = document.getElementById('notification-slot');
  slot.innerHTML = '';
  if (!notifications.length) return;
  notifications.forEach(notification => {
    const div = document.createElement('div');
    div.className = 'notification';
    div.innerHTML = `<strong>${escapeHtml(notification.title)}</strong><span>${escapeHtml(notification.body)}</span>`;
    slot.appendChild(div);
  });
  jsonFetch(appUrl('api/tasks'), {
    method: 'POST',
    body: JSON.stringify({
      action: 'notifications_read',
      ids: notifications.map(notification => notification.id),
    }),
  }).catch(() => {});
}

async function loadLeaderboard() {
  const data = await jsonFetch(appUrl('api/leaderboard'));
  ['daily', 'weekly', 'monthly'].forEach(type => {
    renderBoard(type, data.boards[type].rows);
  });
  renderBoard('all_time', data.all_time, true);
}

function renderBoard(key, rows, isAllTime = false) {
  const card = document.querySelector(`.board-card[data-board="${key}"]`);
  const ol = card.querySelector('.board-rows');
  ol.innerHTML = '';
  if (rows.length === 0) {
    ol.innerHTML = '<li class="empty-hint">No players yet.</li>';
    return;
  }
  const topScore = rows[0].points;
  rows.forEach((row, idx) => {
    const li = document.createElement('li');
    li.className = 'board-row' + (row.points > 0 && row.points === topScore ? ' leader' : '');
    li.innerHTML = `
      <span class="rank">${idx + 1}</span>
      <span class="dot" style="background:${escapeHtml(row.color)}"></span>
      <span class="name">${escapeHtml(row.username)}</span>
      <span class="pts">${row.points}${isAllTime && row.prize_count ? ' 🏆' + row.prize_count : ''}</span>
    `;
    ol.appendChild(li);
  });
}

async function loadBanner() {
  const data = await jsonFetch(appUrl('api/prizes'));
  const mine = data.awards.filter(a => a.is_mine && !a.claimed);
  const slot = document.getElementById('banner-slot');
  slot.innerHTML = '';
  if (mine.length === 0) return;
  const latest = mine[0];
  const div = document.createElement('div');
  div.className = 'banner';
  div.innerHTML = `
    <span>🎉 You won ${escapeHtml(latest.period_label)} (${escapeHtml(latest.list_type)})! Prize: ${escapeHtml(latest.prize)}</span>
    <a href="${appUrl('prizes')}"><button type="button">View prizes</button></a>
  `;
  slot.appendChild(div);
}

// Shows only the window fields that match the chosen periodicity -- a time-of-day for daily, a
// weekday for weekly, a day-of-month for monthly -- since a "window" is captured in whatever
// grain fits that cadence (see templates/dashboard.php and App\Domain\Period\Period).
function setAddWindowFieldsVisibility(form) {
  const type = form.querySelector('select[name=list_type]').value;
  form.querySelectorAll('.window-daily, .window-weekly, .window-monthly').forEach(field => { field.hidden = true; });
  form.querySelectorAll(`.window-${type}`).forEach(field => { field.hidden = false; });
}

function bindListEvents() {
  document.querySelectorAll('.add-task-form').forEach(form => {
    setAddWindowFieldsVisibility(form);

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const titleInput = form.querySelector('input[name=title]');
      const title = titleInput.value.trim();
      if (!title) return;
      const listType = form.querySelector('select[name=list_type]').value;

      const assignedType = form.querySelector('select[name=assigned_type]').value;
      const payload = {
        action: 'add',
        list_type: listType,
        title,
        assigned_type: assignedType,
        priority: form.querySelector('select[name=priority]').value,
        time_limit_minutes: form.querySelector('input[name=time_limit_minutes]').value || null,
        times_per_period: form.querySelector('input[name=times_per_period]').value || 1,
      };
      if (assignedType === 'SPECIFIC_USER') {
        payload.assigned_user_id = form.querySelector('select[name=assigned_user_id]').value;
      }
      // Window fields are named differently per periodicity (see templates/dashboard.php) since
      // each one is captured in whatever grain fits its cadence -- a time-of-day, a weekday, or
      // a day-of-month -- rather than a full date that would just repeat the period.
      if (listType === 'daily') {
        payload.window_start_time = form.querySelector('input[name=window_start_time]').value || null;
        payload.window_end_time = form.querySelector('input[name=window_end_time]').value || null;
      } else if (listType === 'weekly') {
        payload.window_start_day = form.querySelector('select[name=window_start_day]').value || null;
        payload.window_end_day = form.querySelector('select[name=window_end_day]').value || null;
      } else {
        payload.window_start_dom = form.querySelector('input[name=window_start_dom]').value || null;
        payload.window_end_dom = form.querySelector('input[name=window_end_dom]').value || null;
      }

      form.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
      try {
        await jsonFetch(appUrl('api/tasks'), {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        form.reset();
        form.querySelector('.specific-user-field').hidden = true;
        form.querySelector('.time-limit-field').classList.remove('disabled-field');
        setAddWindowFieldsVisibility(form);
        await loadTasks();
      } catch (err) {
        alert(err.message);
      } finally {
        form.querySelectorAll('input, select, button').forEach(el => el.disabled = false);
      }
    });
  });

  document.querySelector('.lists').addEventListener('change', async e => {
    if (e.target.matches('input[type=checkbox][data-task-id]')) {
      const taskId = e.target.dataset.taskId;
      const action = e.target.checked ? 'complete' : 'reopen';
      try {
        await jsonFetch(appUrl('api/tasks'), {
          method: 'POST',
          body: JSON.stringify({ action, task_id: taskId }),
        });
        await Promise.all([loadTasks(), loadLeaderboard(), loadBanner()]);
      } catch (err) {
        // A refused claim usually means somebody beat you to it or the window moved, so reload
        // to show the board as it actually is now instead of leaving a stale checkbox ticked.
        alert(err.message);
        await loadTasks();
      }
      return;
    }

    if (e.target.matches('.assigned-type-select')) {
      const form = e.target.closest('form');
      form.querySelector('.specific-user-field').hidden = e.target.value !== 'SPECIFIC_USER';
      return;
    }

    if (e.target.matches('.priority-select')) {
      const form = e.target.closest('form');
      const timeLimitField = form.querySelector('.time-limit-field');
      const isHigh = e.target.value === 'HIGH';
      timeLimitField.classList.toggle('disabled-field', isHigh);
      timeLimitField.querySelector('input').disabled = isHigh;
      timeLimitField.title = isHigh ? 'HIGH priority tasks always use the short auto-reassign timer instead.' : '';
      return;
    }

    if (e.target.matches('.list-type-select')) {
      setAddWindowFieldsVisibility(e.target.closest('form'));
    }
  });

  document.querySelector('.lists').addEventListener('click', async e => {
    if (e.target.matches('[data-edit-id]')) {
      const task = taskCache.get(String(e.target.dataset.editId));
      if (task) openTaskEditor(task);
      return;
    }
    if (e.target.matches('[data-del-id]')) {
      const taskId = e.target.dataset.delId;
      if (!confirm('Delete this task?')) return;
      try {
        await jsonFetch(appUrl('api/tasks'), {
          method: 'POST',
          body: JSON.stringify({ action: 'delete', task_id: taskId }),
        });
        await loadTasks();
      } catch (err) {
        alert(err.message);
      }
      return;
    }

    if (e.target.matches('[data-start-btn]')) {
      const listType = e.target.closest('.period-pill').dataset.listType;
      const isRunning = e.target.dataset.running === '1';
      const action = isRunning ? 'stop' : 'start';
      e.target.disabled = true;
      try {
        const result = await jsonFetch(appUrl('api/tasks'), {
          method: 'POST',
          body: JSON.stringify({ action, list_type: listType }),
        });
        if (result.started === false || result.stopped === false) {
          alert(result.reason || `Could not ${action} this list.`);
        }
        await Promise.all([loadTasks(), loadLeaderboard()]);
      } catch (err) {
        alert(err.message);
      } finally {
        e.target.disabled = false;
      }
    }
  });

  document.querySelectorAll('[data-close-edit]').forEach(button => {
    button.addEventListener('click', () => document.getElementById('task-edit-dialog').close());
  });
  document.getElementById('task-edit-form').addEventListener('change', e => {
    const form = e.currentTarget;
    if (e.target.matches('.edit-assigned-type-select')) {
      form.querySelector('.edit-specific-user-field').hidden = e.target.value !== 'SPECIFIC_USER';
    }
    if (e.target.matches('.edit-priority-select')) {
      const isHigh = e.target.value === 'HIGH';
      form.querySelector('.edit-time-limit-field').classList.toggle('disabled-field', isHigh);
      form.elements.time_limit_minutes.disabled = isHigh;
    }
  });
  document.getElementById('task-edit-form').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    try { await saveTaskEdit(form); } catch (err) { alert(err.message); }
  });
}

async function refreshAll() {
  await Promise.all([loadTasks(), loadLeaderboard(), loadBanner()]);
}

bindListEvents();
setupPushNotifications().catch(() => {});
refreshAll();
setInterval(refreshAll, 30000);
