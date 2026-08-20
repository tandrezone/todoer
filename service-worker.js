self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (error) {}
  event.waitUntil(self.registration.showNotification(data.title || 'Todoer', {
    body: data.body || 'You have a new Todoer notification.',
    icon: '/assets/icon-192.png',
    badge: '/assets/icon-192.png',
    data: { url: '/index.php' },
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data?.url || '/index.php'));
});