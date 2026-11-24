/* Service worker for the admin: shows Web Push notifications even when no admin tab is open. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { title: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'แจ้งเตือนใหม่', {
    body: d.body || '',
    tag: d.tag || undefined,
    renotify: !!d.tag,
    data: { url: d.url || './notifications.php' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || './notifications.php';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.includes('/admin/') && 'focus' in c) { c.navigate(url); return c.focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
