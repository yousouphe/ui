// Aike Logistics service worker. Every push we receive is an empty "wake up" signal
// (see config/push.php for why) - the actual notification content is fetched from the
// server over an authenticated same-origin request.
self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  event.waitUntil(
    fetch('notifications/ajax_fetch_pending.php', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.title) {
          return self.registration.showNotification(data.title, {
            body: data.body || '',
            data: { url: data.url || './' },
          });
        }
      })
      .catch(function () {})
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(self.clients.openWindow(url));
});
