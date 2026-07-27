<?php
// Progressive-web-app boot partial: an instant, lightweight splash overlay plus the tags
// that register the service worker and load the offline/connectivity behaviour.
//
// Design goals (see the feature brief):
//  - Paint a branded splash the moment the <body> starts parsing, with ZERO extra network
//    requests on the critical path (inline critical CSS + inline markup).
//  - Guarantee the splash is removed once the page is ready, even if boot.js never loads
//    (no infinite-loading trap).
//  - Enhance progressively: splash.css (fuller animations) and boot.js (SW registration,
//    message rotation, connectivity banner) are precached and loaded async/deferred.
//
// Usage: include this file once (functions.php is expected to already be loaded for
// base_url()/e()), then call pwa_boot_tags() immediately after the opening <body> tag.

if (!function_exists('pwa_boot_tags')) {

    // Short, user-friendly feature messages shown one at a time on the splash. Kept in sync
    // with the copy inlined in offline.html (which is static and cannot read PHP).
    function pwa_feature_messages(): array {
        return [
            'Request a rider in just a few taps.',
            'Set your pickup and drop-off in seconds.',
            'Compare riders by distance, vehicle and price.',
            'Track your delivery live, every step of the way.',
            'Chat or call your rider without leaving the app.',
            'Riders: get delivery requests when you are online.',
            'Riders: review the trip details before you accept.',
            'Riders: navigate to pickup and drop-off with ease.',
            'Riders: watch your earnings grow with every trip.',
            'Stay connected, even on a shaky network.',
        ];
    }

    function pwa_boot_tags(): void {
        $base = base_url();
        $prefix = ($base === '' ? '' : $base) . '/';
        // e()-escaped values for HTML attribute contexts (href/src).
        $splashCss = e($prefix . 'assets/pwa/splash.css');
        $bootJs = e($prefix . 'assets/pwa/boot.js');
        // JSON-encoded values for the inline <script> (JS context). JSON_HEX_TAG neutralises
        // any "</script>" and keeps the literal valid; HTML-escaping here would break the JS.
        $jsFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $baseJs = json_encode($prefix, $jsFlags);
        $swUrlJs = json_encode($prefix . 'sw.js', $jsFlags);
        $pingUrlJs = json_encode($prefix . 'ping.php', $jsFlags);
        $messagesJson = json_encode(pwa_feature_messages(), $jsFlags);
        $firstMessage = e(pwa_feature_messages()[0]);
        ?>
<!-- Aike PWA splash (initial-load). Removed automatically once the page is ready. -->
<style id="aike-splash-critical">
#aike-splash{position:fixed;inset:0;z-index:2147483000;display:flex;flex-direction:column;
align-items:center;justify-content:center;gap:1.25rem;padding:1.5rem;text-align:center;
background:linear-gradient(180deg,#eaf5ff,#dbeeff 42%,#eef8ff);color:#0f2c44;
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
opacity:1;transition:opacity .35s ease}
#aike-splash[hidden]{display:none}
#aike-splash.aike-hide{opacity:0;pointer-events:none}
#aike-splash .aike-wordmark{font-weight:800;font-size:clamp(2.4rem,9vw,3.4rem);letter-spacing:.14em;
line-height:1;color:#0b6ec9;text-transform:uppercase}
#aike-splash .aike-spinner{width:38px;height:38px;border-radius:50%;
border:4px solid rgba(11,110,201,.22);border-top-color:#0b6ec9;animation:aike-spin 900ms linear infinite}
#aike-splash .aike-loading{font-size:.8rem;letter-spacing:.16em;text-transform:uppercase;color:#5c7a91}
#aike-splash .aike-msg{min-height:2.6em;max-width:22rem;font-size:1rem;font-weight:500;color:#0f2c44}
@keyframes aike-spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){#aike-splash .aike-spinner{animation-duration:0s;animation:none}
#aike-splash{transition:none}}
</style>
<div id="aike-splash" role="status" aria-live="polite" aria-label="Aike is loading">
  <div class="aike-wordmark">Aike</div>
  <div class="aike-illus" aria-hidden="true"></div>
  <div class="aike-spinner" aria-hidden="true"></div>
  <div class="aike-loading">Loading&hellip;</div>
  <p class="aike-msg" id="aike-splash-msg"><?= $firstMessage ?></p>
</div>
<link rel="stylesheet" href="<?= $splashCss ?>">
<script>
// Minimal inline launcher. Runs before boot.js so the splash is guaranteed to be removed
// even if boot.js fails to download (slow/broken network) - never trap the user on it.
(function(){
  window.AIKE_PWA = {
    base: <?= $baseJs ?>,
    swUrl: <?= $swUrlJs ?>,
    pingUrl: <?= $pingUrlJs ?>,
    messages: <?= $messagesJson ?>,
    maxSplashMs: 6000
  };
  var splash = document.getElementById('aike-splash');
  var removed = false;
  function hideSplash(){
    if(removed || !splash){ return; }
    removed = true;
    window.AIKE_PWA.splashHidden = true;
    splash.classList.add('aike-hide');
    setTimeout(function(){ if(splash){ splash.setAttribute('hidden',''); } }, 400);
  }
  window.AIKE_PWA.hideSplash = hideSplash;
  // Whichever comes first: full load, or a hard cap so a hung resource can't strand us.
  window.addEventListener('load', function(){ setTimeout(hideSplash, 250); });
  setTimeout(hideSplash, window.AIKE_PWA.maxSplashMs);
})();
</script>
<script src="<?= $bootJs ?>" defer></script>
<!-- /Aike PWA splash -->
        <?php
    }
}
