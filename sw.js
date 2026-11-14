

const CACHE_NAME = 'kweek-shell-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'Kweek', body: event.data ? event.data.text() : 'You have a new notification.' };
  }

  const title = data.title || 'Kweek';
  const options = {
    body: data.body || '',
    icon: data.icon || '/assets/img/kweek-icon-192.png',
    badge: data.badge || '/assets/img/kweek-icon-32.png',
    tag: data.tag || 'kweek-notification',
    data: { url: data.url || '/dashboard.php' },
  
    renotify: true,
    requireInteraction: false,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) || '/dashboard.php';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // If a Kweek tab is already open, focus it and navigate there
      // instead of opening a duplicate tab.
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }
    })
  );
});
