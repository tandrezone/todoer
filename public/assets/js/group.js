// Request URLs are built from the base path the server rendered into the page, so the app works
// whether it is served from https://example.com/ or http://nas.local/todoer/ -- and the endpoints
// are clean paths handled by the front controller (public/index.php) rather than .php files.
const BASE_PATH = document.querySelector('meta[name="base-path"]')?.content || '/';
const appUrl = (path) => BASE_PATH + String(path).replace(/^\/+/, '');

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
  div.textContent = str == null ? '' : str;
  return div.innerHTML;
}

function showMessage(text, isError = false) {
  const slot = document.getElementById('group-message');
  slot.hidden = !text;
  slot.textContent = text || '';
  slot.classList.toggle('is-error', isError);
}

function render(data) {
  const group = data.group;
  const members = data.members;

  document.getElementById('group-title').textContent = group.name;
  document.querySelector('#rename-form input[name=name]').value = group.name;
  document.getElementById('member-count').textContent = `(${members.length})`;
  // The invite code (and everything that hands out access) is admin-only; the API omits it for
  // members, so the whole panel is simply absent for them rather than shown-but-disabled.
  document.getElementById('admin-tools').hidden = !group.is_admin;
  document.getElementById('invite-code').textContent = group.invite_code || '—';

  const list = document.getElementById('member-list');
  list.innerHTML = '';
  members.forEach(member => {
    const li = document.createElement('li');
    li.className = 'member-row';
    const tags = [];
    if (member.role === 'admin') tags.push('<span class="chip role-admin">Admin</span>');
    if (member.is_me) tags.push('<span class="chip role-me">You</span>');
    const actions = [];
    if (group.is_admin && !member.is_me) {
      if (member.role !== 'admin') {
        actions.push(`<button type="button" class="link-btn" data-promote="${member.id}">Make admin</button>`);
      }
      actions.push(`<button type="button" class="link-btn danger" data-remove="${member.id}" data-name="${escapeHtml(member.username)}">Remove</button>`);
    }
    li.innerHTML = `
      <span class="dot" style="background:${escapeHtml(member.color)}"></span>
      <span class="name">${escapeHtml(member.username)}</span>
      ${tags.join(' ')}
      <span class="member-actions">${actions.join(' ')}</span>
    `;
    list.appendChild(li);
  });
}

async function post(payload, successFallback) {
  const data = await jsonFetch(appUrl('api/group'), { method: 'POST', body: JSON.stringify(payload) });
  render(data);
  showMessage(data.message || successFallback || '');
  return data;
}

async function load() {
  render(await jsonFetch(appUrl('api/group')));
}

function bindForm(id, buildPayload, afterSuccess) {
  document.getElementById(id).addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const payload = buildPayload(form);
    if (!payload) return;
    form.querySelectorAll('input, button').forEach(el => el.disabled = true);
    try {
      await post(payload);
      if (afterSuccess) afterSuccess(form);
    } catch (err) {
      showMessage(err.message, true);
    } finally {
      form.querySelectorAll('input, button').forEach(el => el.disabled = false);
    }
  });
}

bindForm('add-member-form', form => ({
  action: 'add_member',
  username: form.elements.username.value.trim(),
}), form => form.reset());

bindForm('create-member-form', form => ({
  action: 'create_member',
  username: form.elements.username.value.trim(),
  password: form.elements.password.value,
}), form => form.reset());

bindForm('rename-form', form => ({
  action: 'rename',
  name: form.elements.name.value.trim(),
}));

bindForm('join-form', form => {
  const code = form.elements.invite_code.value.trim();
  if (!code) return null;
  if (!confirm('Joining that group moves you out of this one. Continue?')) return null;
  return { action: 'join', invite_code: code };
}, form => form.reset());

document.getElementById('regenerate-code').addEventListener('click', async () => {
  if (!confirm('Generate a new invite code? Anyone still holding the old one will not be able to join.')) return;
  try { await post({ action: 'regenerate_code' }); } catch (err) { showMessage(err.message, true); }
});

document.getElementById('leave-group').addEventListener('click', async () => {
  if (!confirm('Leave this group? You keep your account and get a private list of your own, but you lose access to this group\'s tasks, standings and prizes.')) return;
  try { await post({ action: 'leave' }); } catch (err) { showMessage(err.message, true); }
});

document.getElementById('member-list').addEventListener('click', async e => {
  const removeId = e.target.dataset?.remove;
  const promoteId = e.target.dataset?.promote;
  try {
    if (removeId) {
      if (!confirm(`Remove ${e.target.dataset.name} from the group? They keep their account, but lose access to this group's tasks and standings.`)) return;
      await post({ action: 'remove_member', user_id: Number(removeId) });
    } else if (promoteId) {
      await post({ action: 'set_role', user_id: Number(promoteId), role: 'admin' });
    }
  } catch (err) {
    showMessage(err.message, true);
  }
});

load().catch(err => showMessage(err.message, true));
