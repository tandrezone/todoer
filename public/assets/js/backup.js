// Request URLs are built from the base path the server rendered into the page, so the app works
// whether it is served from https://example.com/ or http://nas.local/todoer/ -- and the endpoints
// are clean paths handled by the front controller (public/index.php) rather than .php files.
const BASE_PATH = document.querySelector('meta[name="base-path"]')?.content || '/';
const appUrl = (path) => BASE_PATH + String(path).replace(/^\/+/, '');

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
    const res = await fetch(appUrl('api/import/tasks'), {
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
