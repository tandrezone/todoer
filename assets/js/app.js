const LIST_TYPES = ['daily', 'weekly', 'monthly'];
const PRIORITY_LABEL = { HIGH: 'High', MODERATE: 'Moderate', LOW: 'Low' };
const STATUS_LABEL = { unassigned: 'Unassigned', open: 'In progress', done: 'Done', expired: 'Missed' };

let knownUserCount = 0;

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

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
// month. Storage-wise these are still full datetimes (see includes/period.php) -- this only
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

function priorityBadgeHtml(priority) {
  const label = PRIORITY_LABEL[priority] || priority;
  return `<span class="chip priority-badge priority-${(priority || '').toLowerCase()}">${escapeHtml(label)}</span>`;
}

function populateUserSelects(users) {
  document.querySelectorAll('.specific-user-select').forEach(select => {
    select.innerHTML = users.map(u => `<option value="${u.id}">${escapeHtml(u.username)}</option>`).join('');
  });
}

async function loadTasks() {
  const data = await jsonFetch('api/tasks.php');
  renderNotifications(data.notifications || []);

  if (data.users.length !== knownUserCount) {
    knownUserCount = data.users.length;
    populateUserSelects(data.users);
  }

  LIST_TYPES.forEach(type => {
    const card = document.querySelector(`.list-card[data-list-type="${type}"]`);
    const info = data.tasks[type];
    card.querySelector('[data-period-label]').textContent = info.label;

    // Running locks the card down to just the task list + checkboxes: no adding, no editing,
    // no team board -- see the .is-running rules in style.css. Stopped (or never started)
    // shows the full add/assign/options UI instead.
    card.classList.toggle('is-running', info.running);

    const startBtn = card.querySelector('[data-start-btn]');
    startBtn.textContent = info.running ? `Stop ${type}` : `Start ${type}`;
    startBtn.dataset.running = info.running ? '1' : '0';

    const hint = card.querySelector('[data-unassigned-hint]');
    if (!info.running && info.unassigned_count > 0) {
      hint.hidden = false;
      hint.textContent = `${info.unassigned_count} task${info.unassigned_count === 1 ? '' : 's'} waiting to be assigned — click "${startBtn.textContent}".`;
    } else {
      hint.hidden = true;
    }

    const listEl = card.querySelector('[data-task-list]');
    listEl.innerHTML = '';
    if (info.items.length === 0) {
      listEl.innerHTML = info.running
        ? '<li class="empty-hint">Nothing assigned to you for this one.</li>'
        : '<li class="empty-hint">Nothing assigned to you yet — add a task or ask someone to run Start.</li>';
    }
    info.items.forEach(task => {
      const li = document.createElement('li');
      li.className = 'task-item' + (task.status === 'done' ? ' done' : '');
      const meta = [];
      if (task.window_start || task.window_end) {
        const startStr = formatWindow(task.window_start, type);
        const endStr = formatWindow(task.window_end, type);
        meta.push(`<span class="task-window">${escapeHtml(startStr)}${endStr ? ' &rarr; ' + escapeHtml(endStr) : ''}</span>`);
      }
      if (task.priority === 'HIGH' && task.status === 'open') {
        meta.push('<span class="timer-hint">⏱ short timer</span>');
      } else if (task.time_limit_minutes && task.status === 'open') {
        meta.push(`<span class="timer-hint">⏱ ${task.time_limit_minutes}m</span>`);
      }
      li.innerHTML = `
        <input type="checkbox" ${task.status === 'done' ? 'checked' : ''} data-task-id="${task.id}">
        <span class="task-body">
          <span class="task-title">${escapeHtml(task.title)}</span>
          ${meta.length ? `<span class="task-meta">${meta.join(' &middot; ')}</span>` : ''}
        </span>
        ${priorityBadgeHtml(task.priority)}
        <span class="chip task-points">+${task.points}</span>
        <button class="task-del" data-del-id="${task.id}" title="Delete">&times;</button>
      `;
      listEl.appendChild(li);
    });

    const boardList = card.querySelector('[data-board-list]');
    const boardCount = card.querySelector('[data-board-count]');
    boardCount.textContent = `(${info.board.length})`;
    boardList.innerHTML = '';
    if (info.board.length === 0) {
      boardList.innerHTML = '<li class="empty-hint">No tasks in this period yet.</li>';
    }
    info.board.forEach(task => {
      const li = document.createElement('li');
      li.className = 'board-task-row';
      const holder = task.holder_username
        ? `<span class="dot" style="background:${escapeHtml(task.holder_color)}"></span>${escapeHtml(task.holder_username)}`
        : (task.assigned_type === 'SPECIFIC_USER' ? '<em>locked, not yet assigned</em>' : '<em>unassigned</em>');
      const boardStartStr = formatWindow(task.window_start, type);
      const boardEndStr = formatWindow(task.window_end, type);
      const windowHtml = (boardStartStr || boardEndStr)
        ? `<span class="task-window">${escapeHtml(boardStartStr)}${boardEndStr ? ' &rarr; ' + escapeHtml(boardEndStr) : ''}</span>`
        : '';
      li.innerHTML = `
        <span class="chip status-chip status-${task.status}">${STATUS_LABEL[task.status] || task.status}</span>
        <span class="task-title">${escapeHtml(task.title)}</span>
        ${windowHtml}
        ${priorityBadgeHtml(task.priority)}
        <span class="board-holder">${holder}</span>
      `;
      boardList.appendChild(li);
    });
  });
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
  jsonFetch('api/tasks.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'notifications_read',
      ids: notifications.map(notification => notification.id),
    }),
  }).catch(() => {});
}

async function loadLeaderboard() {
  const data = await jsonFetch('api/leaderboard.php');
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
  const data = await jsonFetch('api/prizes.php');
  const mine = data.awards.filter(a => a.is_mine && !a.claimed);
  const slot = document.getElementById('banner-slot');
  slot.innerHTML = '';
  if (mine.length === 0) return;
  const latest = mine[0];
  const div = document.createElement('div');
  div.className = 'banner';
  div.innerHTML = `
    <span>🎉 You won ${escapeHtml(latest.period_label)} (${escapeHtml(latest.list_type)})! Prize: ${escapeHtml(latest.prize)}</span>
    <a href="prizes.php"><button type="button">View prizes</button></a>
  `;
  slot.appendChild(div);
}

function bindListEvents() {
  document.querySelectorAll('.add-task-form').forEach(form => {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const titleInput = form.querySelector('input[name=title]');
      const title = titleInput.value.trim();
      if (!title) return;
      const listType = form.closest('.list-card').dataset.listType;

      const assignedType = form.querySelector('select[name=assigned_type]').value;
      const payload = {
        action: 'add',
        list_type: listType,
        title,
        assigned_type: assignedType,
        priority: form.querySelector('select[name=priority]').value,
        time_limit_minutes: form.querySelector('input[name=time_limit_minutes]').value || null,
      };
      if (assignedType === 'SPECIFIC_USER') {
        payload.assigned_user_id = form.querySelector('select[name=assigned_user_id]').value;
      }
      // Window fields are named differently per list type (see index.php) since each list's
      // window is captured in whatever grain fits its cadence -- a time-of-day, a weekday, or
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
        await jsonFetch('api/tasks.php', {
          method: 'POST',
          body: JSON.stringify(payload),
        });
        form.reset();
        form.querySelector('.specific-user-field').hidden = true;
        form.querySelector('.time-limit-field').classList.remove('disabled-field');
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
        await jsonFetch('api/tasks.php', {
          method: 'POST',
          body: JSON.stringify({ action, task_id: taskId }),
        });
        await Promise.all([loadTasks(), loadLeaderboard(), loadBanner()]);
      } catch (err) {
        alert(err.message);
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
    }
  });

  document.querySelector('.lists').addEventListener('click', async e => {
    if (e.target.matches('[data-del-id]')) {
      const taskId = e.target.dataset.delId;
      if (!confirm('Delete this task?')) return;
      try {
        await jsonFetch('api/tasks.php', {
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
      const listType = e.target.closest('.list-card').dataset.listType;
      const isRunning = e.target.dataset.running === '1';
      const action = isRunning ? 'stop' : 'start';
      e.target.disabled = true;
      try {
        const result = await jsonFetch('api/tasks.php', {
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
}

async function refreshAll() {
  await Promise.all([loadTasks(), loadLeaderboard(), loadBanner()]);
}

bindListEvents();
refreshAll();
setInterval(refreshAll, 30000);
