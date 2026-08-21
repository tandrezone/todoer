// Request URLs are built from the base path the server rendered into the page, so the app works
// whether it is served from https://example.com/ or http://nas.local/todoer/ -- and the endpoints
// are clean paths handled by the front controller (public/index.php) rather than .php files.
const BASE_PATH = document.querySelector('meta[name="base-path"]')?.content || '/';
const appUrl = (path) => BASE_PATH + String(path).replace(/^\/+/, '');

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

const MEDALS = { daily: '☀️', weekly: '📅', monthly: '🌕' };

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function jsonFetch(url, options = {}) {
  const res = await fetch(url, { headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, ...options });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) throw new Error(data.error || 'Request failed');
  return data;
}

async function loadPrizes() {
  const data = await jsonFetch(appUrl('api/prizes'));
  const container = document.getElementById('prize-list');
  container.innerHTML = '';

  if (data.awards.length === 0) {
    container.innerHTML = '<p class="hint">No prizes awarded yet — finish tasks to win the first one when today, this week, or this month wraps up.</p>';
    return;
  }

  data.awards.forEach(a => {
    const div = document.createElement('div');
    div.className = 'prize-card' + (a.is_mine && !a.claimed ? ' unclaimed' : '');
    let action = '';
    if (a.is_mine && !a.claimed) {
      action = `<button class="claim-btn" data-claim-id="${a.id}">Mark claimed</button>`;
    } else if (a.claimed) {
      action = '<span class="claimed-badge">✓ Claimed</span>';
    }
    div.innerHTML = `
      <div class="medal">${MEDALS[a.list_type] || '🏆'}</div>
      <div class="body">
        <div class="who" style="color:${escapeHtml(a.color)}">${escapeHtml(a.username)}</div>
        <div class="desc">${escapeHtml(a.prize)}</div>
        <div class="meta">${escapeHtml(a.period_label)} &middot; ${a.points} pts</div>
      </div>
      ${action}
    `;
    container.appendChild(div);
  });
}

document.getElementById('prize-list').addEventListener('click', async e => {
  if (e.target.matches('[data-claim-id]')) {
    const awardId = e.target.dataset.claimId;
    try {
      await jsonFetch(appUrl('api/prizes'), {
        method: 'POST',
        body: JSON.stringify({ action: 'claim', award_id: awardId }),
      });
      await loadPrizes();
    } catch (err) {
      alert(err.message);
    }
  }
});

loadPrizes();
