// Push service worker.
//
// Every URL here is resolved against the worker's own scope rather than hard-coded to "/", so the
// app keeps working when it isn't served from the domain root (e.g. http://nas.local/todoer/) --
// absolute paths silently broke both the notification icon and the click-through there.
const scopeUrl = (path) => new URL(path, self.registration.scope).href;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (error) {}
  event.waitUntil(self.registration.showNotification(data.title || 'Todoer', {
    body: data.body || 'You have a new Todoer notification.',
    icon: scopeUrl('assets/icon-192.png'),
    badge: scopeUrl('assets/icon-192.png'),
    // A tag would collapse notifications into one; these are distinct events (a task taken, a
    // deadline closing in), so each gets its own entry.
    data: { url: scopeUrl('index.php') },
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = event.notification.data?.url || scopeUrl('index.php');
  // Focus a tab that already has Todoer open instead of piling up new windows.
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
        await client.focus();
        if ('navigate' in client && client.url !== target) {
          try { await client.navigate(target); } catch (error) { /* focusing is enough */ }
        }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});

// Browsers occasionally rotate a subscription on their own (Chrome does this when the push
// service reissues an endpoint). Without this handler the old endpoint just goes dead and the
// user silently stops receiving anything until they happen to reload the page.
self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil((async () => {
    try {
      const res = await fetch(scopeUrl('api/notifications.php'), { credentials: 'include' });
      const config = await res.json();
      if (!config.public_key) return;
      const subscription = await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(config.public_key),
      });
      await fetch(scopeUrl('api/notifications.php'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf_token || '' },
        body: JSON.stringify({ action: 'subscribe', subscription }),
      });
    } catch (error) {
      // Nothing useful to do here; the page-side setup in app.js re-subscribes on next load.
    }
  })());
});

function urlBase64ToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
}
