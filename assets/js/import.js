function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

let candidates = []; // [{text, checked, source, include, list_type}]

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

const scanForm = document.getElementById('scan-form');
const scanStatus = document.getElementById('scan-status');
const preview = document.getElementById('preview');
const candidateList = document.getElementById('candidate-list');
const previewCount = document.getElementById('preview-count');
const commitStatus = document.getElementById('commit-status');

function renderCandidates() {
  candidateList.innerHTML = '';
  candidates.forEach((c, idx) => {
    const li = document.createElement('li');
    li.className = 'candidate-row' + (c.checked ? ' was-checked' : '');
    li.innerHTML = `
      <input type="checkbox" data-idx="${idx}" class="cand-include" ${c.include ? 'checked' : ''}>
      <span class="cand-text">${escapeHtml(c.text)}</span>
      <span class="cand-source" title="From Keep note">${escapeHtml(c.source)}</span>
      <select data-idx="${idx}" class="cand-list">
        <option value="daily" ${c.list_type === 'daily' ? 'selected' : ''}>Daily</option>
        <option value="weekly" ${c.list_type === 'weekly' ? 'selected' : ''}>Weekly</option>
        <option value="monthly" ${c.list_type === 'monthly' ? 'selected' : ''}>Monthly</option>
      </select>
    `;
    candidateList.appendChild(li);
  });
  const selectedCount = candidates.filter(c => c.include).length;
  previewCount.textContent = `${selectedCount} of ${candidates.length} selected`;
}

scanForm.addEventListener('submit', async e => {
  e.preventDefault();
  const fileInput = document.getElementById('file-input');
  if (!fileInput.files.length) return;

  const fd = new FormData();
  for (const f of fileInput.files) fd.append('files[]', f);
  fd.append('skip_archived', document.getElementById('opt-skip-archived').checked ? '1' : '');
  fd.append('skip_trashed', document.getElementById('opt-skip-trashed').checked ? '1' : '');
  fd.append('include_checked', document.getElementById('opt-include-checked').checked ? '1' : '');
  fd.append('plain_note_mode', document.getElementById('opt-plain-mode').value);

  scanStatus.textContent = 'Scanning…';
  preview.hidden = true;
  commitStatus.textContent = '';

  try {
    const res = await fetch('api/import.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF_TOKEN },
      body: fd,
    });
    const data = await res.json();
    if (!res.ok || data.ok === false) throw new Error(data.error || 'Scan failed.');

    candidates = data.candidates.map(c => ({ ...c, include: true, list_type: 'daily' }));

    let msg = `Found ${data.notes_found} note(s), ${candidates.length} importable item(s).`;
    if (data.errors && data.errors.length) {
      msg += ' Issues: ' + data.errors.join(' ');
    }
    scanStatus.textContent = msg;

    if (candidates.length === 0) {
      preview.hidden = true;
      return;
    }
    preview.hidden = false;
    renderCandidates();
  } catch (err) {
    scanStatus.textContent = 'Error: ' + err.message;
  }
});

candidateList.addEventListener('change', e => {
  const idx = e.target.dataset.idx;
  if (idx === undefined) return;
  if (e.target.matches('.cand-include')) {
    candidates[idx].include = e.target.checked;
    const selectedCount = candidates.filter(c => c.include).length;
    previewCount.textContent = `${selectedCount} of ${candidates.length} selected`;
  } else if (e.target.matches('.cand-list')) {
    candidates[idx].list_type = e.target.value;
  }
});

document.getElementById('select-all').addEventListener('click', () => {
  candidates.forEach(c => c.include = true);
  renderCandidates();
});
document.getElementById('select-none').addEventListener('click', () => {
  candidates.forEach(c => c.include = false);
  renderCandidates();
});
document.getElementById('apply-bulk-list').addEventListener('click', () => {
  const listType = document.getElementById('bulk-list-type').value;
  candidates.forEach(c => { if (c.include) c.list_type = listType; });
  renderCandidates();
});

document.getElementById('commit-btn').addEventListener('click', async () => {
  const items = candidates.filter(c => c.include).map(c => ({ title: c.text, list_type: c.list_type }));
  if (items.length === 0) {
    commitStatus.textContent = 'Nothing selected.';
    return;
  }
  commitStatus.textContent = 'Importing…';
  try {
    const res = await fetch('api/import.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ action: 'commit', items }),
    });
    const data = await res.json();
    if (!res.ok || data.ok === false) throw new Error(data.error || 'Import failed.');
    commitStatus.innerHTML = `Imported ${data.created} task(s). <a href="index.php">Go to dashboard &rarr;</a>`;
    preview.hidden = true;
  } catch (err) {
    commitStatus.textContent = 'Error: ' + err.message;
  }
});
