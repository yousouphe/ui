// Aike Logistics service worker.
//
// Two jobs:
//   1. Offline resilience (added): precache the self-contained splash/offline page and its
//      static assets, and serve that page as the fallback whenever a navigation fails because
//      the device is offline. This is what lets the splash load entirely from cache on an
//      offline visit.
//   2. Web push (existing): every push we receive is an empty "wake up" signal (see
//      config/push.php) - the actual notification content is fetched from the server over an
//      authenticated same-origin request.
//
// Caching policy is intentionally conservative for privacy and correctness:
//   - We ONLY cache a small, versioned set of static, non-sensitive assets (the offline page,
//     splash CSS/JS). We never cache HTML pages, API/AJAX responses, auth responses, or any
//     private data - those always go to the network.
//   - The cache name is versioned; bumping CACHE_VERSION invalidates and re-primes everything
//     on the next activate, so stale assets can't break a future deployment.

var CACHE_VERSION = 'v1';
var CACHE_NAME = 'aike-static-' + CACHE_VERSION;

// Paths are relative to this script's location, so they resolve correctly whatever sub-path
// the app is deployed under (e.g. "/", "/app/", "/nasfat/app/").
var BASE = self.location.pathname.replace(/sw\.js$/, '');
var OFFLINE_URL = BASE + 'offline.html';
var ASSET_PREFIX = BASE + 'assets/pwa/';

var PRECACHE_URLS = [
  OFFLINE_URL,
  ASSET_PREFIX + 'splash.css',
  ASSET_PREFIX + 'boot.js'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // Fetch fresh copies (bypass HTTP cache) so a new SW version always primes new assets.
      return cache.addAll(PRECACHE_URLS.map(function (u) {
        return new Request(u, { cache: 'reload' });
      }));
    }).catch(function () {
      // A failed precache must not abort installation - the SW should still install and the
      // app must still work online. Offline fallback simply won't be available this time.
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        // Drop any of our old versioned caches; leave unrelated caches untouched.
        if (key.indexOf('aike-static-') === 0 && key !== CACHE_NAME) {
          return caches.delete(key);
        }
        return null;
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;

  // Only ever touch same-origin GETs. POSTs (forms), non-GET verbs, and cross-origin requests
  // (CDN styles, Mapbox tiles, etc.) pass straight through to the network, untouched.
  if (req.method !== 'GET') { return; }
  var url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) { return; }

  // Page navigations: network-first (so auth, redirects and live data always come from the
  // server), falling back to the cached offline splash only when the network is unavailable.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return caches.match(OFFLINE_URL, { ignoreSearch: true }).then(function (cached) {
          return cached || new Response(
            'You are offline.',
            { status: 503, headers: { 'Content-Type': 'text/plain' } }
          );
        });
      })
    );
    return;
  }

  // Our own precached static splash assets: cache-first (they are versioned and non-sensitive).
  if (url.pathname === OFFLINE_URL || url.pathname.indexOf(ASSET_PREFIX) === 0) {
    event.respondWith(
      caches.match(req).then(function (cached) { return cached || fetch(req); })
    );
    return;
  }

  // Everything else same-origin (AJAX/API, images, uploads): straight to network, no caching.
});

// --- Web push (unchanged behaviour) --------------------------------------------------------

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
