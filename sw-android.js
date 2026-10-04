importScripts('/sw-core.js');

(function () {
  'use strict';

  // SW v3 — align with sw-core 1.0.2 (skip all API/.php routes from SW handling + full try/catch)
  const CACHE_NAME = 'swaapin-cache-android-v3';
  const EXTRA_PRECACHE = [
    '/src/js/pwa/pwa-android.js',
    '/src/img/fav_icon/site-android.webmanifest',
  ];

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: EXTRA_PRECACHE,

    onInstall: function (cacheName, event) {
      try { console.debug('[SW-Android] installing cache=', cacheName); } catch (e) {}
    },

    onActivate: function (cacheName, event) {
      try { console.debug('[SW-Android] activated cache=', cacheName); } catch (e) {}
    },

    onFetch: function (event, cacheName) {
      try {
        const req = event.request;
        if (req.mode !== 'navigate') return false;
        const url = new URL(req.url);
        if (!self.SwaapinSWCore.isAdminRoute(url)) return false;
        if (self.SwaapinSWCore.isApiRoute(url)) return false;
        event.respondWith(
          fetch(req).catch(() =>
            caches.match(req).then((r) => {
              if (r) return r;
              return caches.match(self.SwaapinSWCore.OFFLINE_URL)
                .then((o) => o || new Response('', { status: 503, statusText: 'Service Unavailable' }));
            })
          )
        );
        return true;
      } catch (e) {
        return false;
      }
    },

    onMessage: function (event, cacheName) {
      try {
        if (event.data?.type === 'ANDROID_BADGE') {
          // reserved for future badge sync
        }
      } catch (e) {}
    },
  });
})();
