importScripts('/sw-core.js');

(function () {
  'use strict';

  // SW v3 — align with sw-core 1.0.2 (skip all API/.php routes from SW handling + full try/catch)
  const CACHE_NAME = 'swaapin-cache-ios-v3';
  const EXTRA_PRECACHE = [
    '/src/js/pwa/pwa-ios.js',
    '/src/img/fav_icon/site-ios.webmanifest',
  ];

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: EXTRA_PRECACHE,

    onInstall: function (cacheName, event) {
      try { console.debug('[SW-iOS] installing cache=', cacheName); } catch (e) {}
    },

    onActivate: function (cacheName, event) {
      try { console.debug('[SW-iOS] activated cache=', cacheName); } catch (e) {}
    },

    onFetch: function (event, cacheName) {
      try {
        const req = event.request;
        const url = new URL(req.url);

        if (url.origin !== location.origin) return false;
        if (req.method !== 'GET') return false;
        if (self.SwaapinSWCore.isAdminRoute(url)) return false;
        if (self.SwaapinSWCore.isApiRoute(url)) return false;

        if (req.mode === 'navigate') {
          event.respondWith(
            caches.match(req).then((cached) => {
              const networkFetch = fetch(req)
                .then((res) => {
                  try {
                    if (res && res.status === 200) {
                      const copy = res.clone();
                      caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
                    }
                  } catch (e) {}
                  return res;
                })
                .catch(() =>
                  cached ||
                  caches.match(self.SwaapinSWCore.OFFLINE_URL)
                    .then((o) => o || new Response('', { status: 503, statusText: 'Service Unavailable' }))
                );
              return cached || networkFetch;
            })
          );
          return true;
        }

        if (/\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|webp|ico)$/i.test(url.pathname)) {
          event.respondWith(
            caches.match(req).then((cached) => cached || self.SwaapinSWCore.staticResponse(req, cacheName))
          );
          return true;
        }

        return false;
      } catch (e) {
        return false;
      }
    },

    onMessage: function (event, cacheName) {
      try {
        if (event.data?.type === 'IOS_CLEAR_NAV_CACHE') {
          caches.open(cacheName).then(async (cache) => {
            const keys = await cache.keys();
            keys.forEach((k) => {
              try {
                if (k.mode === 'navigate' || (!k.url.includes('.'))) cache.delete(k);
              } catch (e) {}
            });
          }).catch(() => {});
        }
      } catch (e) {}
    },
  });
})();
