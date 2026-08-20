const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

const form = document.getElementById('import-json-form');
const status = document.getElementById('import-json-status');

form.addEventListener('submit', async e => {
  e.preventDefault();
  const fileInput = document.getElementById('import-json-file');
  if (!fileInput.files.length) return;

  const fd = new FormData();
  fd.append('file', fileInput.files[0]);

  status.textContent = 'Importing…';

  try {
    const res = await fetch('api/import_json.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF_TOKEN },
      body: fd,
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      status.textContent = data.error || 'Import failed.';
      return;
    }
    status.textContent = `Imported ${data.created} task(s)` + (data.skipped ? `, skipped ${data.skipped} invalid entr${data.skipped === 1 ? 'y' : 'ies'}.` : '.');
    form.reset();
  } catch (err) {
    status.textContent = 'Import failed: ' + err.message;
  }
});
