/* Aike PWA boot script (precached, loaded with `defer`).
 *
 * Responsibilities on a normally-loaded page:
 *   1. Register the service worker (failure is swallowed - it must never block the app).
 *   2. Rotate the splash feature messages while the initial-load splash is visible.
 *   3. Handle "was loaded, then went offline": show a slim, non-blocking banner and let the
 *      page's own AJAX pollers self-heal on reconnect. We do NOT reload the whole page here.
 *
 * The full-screen offline splash for a *fresh* load with no network is offline.html, served
 * by the service worker's navigation fallback - not this script.
 */
(function () {
  'use strict';

  var CFG = window.AIKE_PWA || {};
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------- service worker ------ */
  if ('serviceWorker' in navigator && CFG.swUrl) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(CFG.swUrl).catch(function (err) {
        // Registration failing (unsupported, blocked, HTTP, quota) must not affect the app.
        if (window.console && console.info) { console.info('Aike SW registration skipped:', err && err.message); }
      });
    });
  }

  /* ---------------------------------------------------------------- message rotation ---- */
  (function rotateMessages() {
    var el = document.getElementById('aike-splash-msg');
    var messages = Array.isArray(CFG.messages) ? CFG.messages : [];
    if (!el || messages.length < 2) { return; }
    var i = 0;
    function tick() {
      // Stop once the splash has been removed (page is ready).
      if (CFG.splashHidden) { return; }
      i = (i + 1) % messages.length;
      if (reduceMotion) {
        el.textContent = messages[i];
      } else {
        el.classList.add('aike-msg-out');
        setTimeout(function () {
          el.textContent = messages[i];
          el.classList.remove('aike-msg-out');
        }, 300);
      }
      setTimeout(tick, 2600);
    }
    setTimeout(tick, 2600);
  })();

  /* ---------------------------------------------------------------- connectivity -------- */
  // navigator.onLine is a hint only; confirm real reachability with a tiny same-origin probe
  // before declaring the connection restored.
  var checking = false;
  function confirmOnline() {
    if (!CFG.pingUrl) { return Promise.resolve(navigator.onLine); }
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 3500);
    return fetch(CFG.pingUrl + (CFG.pingUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now(), {
      method: 'GET',
      cache: 'no-store',
      credentials: 'omit',
      signal: controller.signal
    }).then(function (res) {
      clearTimeout(timer);
      return res && (res.ok || res.status === 204);
    }).catch(function () {
      clearTimeout(timer);
      return false;
    });
  }

  var banner = null;
  function getBanner() {
    if (banner) { return banner; }
    banner = document.createElement('div');
    banner.id = 'aike-net-banner';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.innerHTML = '<span class="aike-dot" aria-hidden="true"></span><span class="aike-net-text"></span>';
    document.body.appendChild(banner);
    return banner;
  }
  function showBanner(kind, text) {
    var b = getBanner();
    b.querySelector('.aike-net-text').textContent = text;
    b.classList.remove('aike-offline', 'aike-online');
    b.classList.add(kind === 'offline' ? 'aike-offline' : 'aike-online', 'aike-show');
  }
  function hideBanner() {
    if (banner) { banner.classList.remove('aike-show'); }
  }

  function dispatch(online) {
    try { window.dispatchEvent(new CustomEvent('aike:connectivity', { detail: { online: online } })); } catch (e) {}
  }

  // Short, controlled retry while we believe we are offline. Backoff, capped; no page reload.
  var retryTimer = null;
  var retryDelays = [3000, 5000, 8000, 12000, 15000];
  var retryIndex = 0;
  function stopRetry() { if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; } retryIndex = 0; }
  function scheduleRetry() {
    if (retryTimer) { return; }
    var delay = retryDelays[Math.min(retryIndex, retryDelays.length - 1)];
    retryIndex++;
    retryTimer = setTimeout(function () {
      retryTimer = null;
      runCheck();
    }, delay);
  }

  var wasOffline = false;
  function runCheck() {
    if (checking) { return; }
    checking = true;
    confirmOnline().then(function (online) {
      checking = false;
      if (online) {
        stopRetry();
        if (wasOffline) {
          wasOffline = false;
          showBanner('online', 'Back online');
          dispatch(true);
          setTimeout(hideBanner, 2500);
        } else {
          hideBanner();
        }
      } else {
        if (!wasOffline) {
          wasOffline = true;
          dispatch(false);
        }
        showBanner('offline', "You're offline — some features may pause until you reconnect.");
        scheduleRetry();
      }
    });
  }

  window.addEventListener('online', runCheck);
  window.addEventListener('offline', function () {
    wasOffline = true;
    dispatch(false);
    showBanner('offline', "You're offline — some features may pause until you reconnect.");
    scheduleRetry();
  });

  // If the browser already reports offline at load time on an otherwise-rendered page.
  if (navigator.onLine === false) {
    wasOffline = true;
    showBanner('offline', "You're offline — some features may pause until you reconnect.");
    scheduleRetry();
  }
})();
