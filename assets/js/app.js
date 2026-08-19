const LIST_TYPES = ['daily', 'weekly', 'monthly'];

async function jsonFetch(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json' },
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

async function loadTasks() {
  const data = await jsonFetch('api/tasks.php');
  LIST_TYPES.forEach(type => {
    const card = document.querySelector(`.list-card[data-list-type="${type}"]`);
    const info = data.tasks[type];
    card.querySelector('[data-period-label]').textContent = info.label;
    const listEl = card.querySelector('[data-task-list]');
    listEl.innerHTML = '';
    if (info.items.length === 0) {
      listEl.innerHTML = '<li class="empty-hint">No tasks yet — add one below.</li>';
    }
    info.items.forEach(task => {
      const li = document.createElement('li');
      li.className = 'task-item' + (task.status === 'done' ? ' done' : '');
      li.innerHTML = `
        <input type="checkbox" ${task.status === 'done' ? 'checked' : ''} data-task-id="${task.id}">
        <span class="task-title">${escapeHtml(task.title)}</span>
        <span class="task-points">+${task.points}</span>
        <button class="task-del" data-del-id="${task.id}" title="Delete">&times;</button>
      `;
      listEl.appendChild(li);
    });
  });
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
      const input = form.querySelector('input');
      const title = input.value.trim();
      if (!title) return;
      const listType = form.closest('.list-card').dataset.listType;
      input.disabled = true;
      try {
        await jsonFetch('api/tasks.php', {
          method: 'POST',
          body: JSON.stringify({ action: 'add', list_type: listType, title }),
        });
        input.value = '';
        await loadTasks();
      } catch (err) {
        alert(err.message);
      } finally {
        input.disabled = false;
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
        await Promise.all([loadTasks(), loadLeaderboard()]);
      } catch (err) {
        alert(err.message);
      }
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
    }
  });
}

async function refreshAll() {
  await Promise.all([loadTasks(), loadLeaderboard(), loadBanner()]);
}

bindListEvents();
refreshAll();
setInterval(refreshAll, 30000);
