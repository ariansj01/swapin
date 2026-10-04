importScripts('/sw-core.js');

(function () {
  'use strict';

  // SW v2 — align with sw-core 1.0.1 (fixed defaultFetchResponse returning undefined)
  const CACHE_NAME = 'swaapin-cache-ios-v2';
  const EXTRA_PRECACHE = [
    '/src/js/pwa/pwa-ios.js',
    '/src/img/fav_icon/site-ios.webmanifest',
  ];

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: EXTRA_PRECACHE,

    onInstall: function (cacheName, event) {
      console.debug('[SW-iOS] installing cache=', cacheName);
    },

    onActivate: function (cacheName, event) {
      console.debug('[SW-iOS] activated cache=', cacheName);
    },

    onFetch: function (event, cacheName) {
      const req = event.request;
      const url = new URL(req.url);

      if (url.origin !== location.origin) return false;
      if (req.method !== 'GET') return false;
      if (self.SwaapinSWCore.isAdminRoute(url)) return false;

      if (req.mode === 'navigate') {
        event.respondWith(
          caches.match(req).then((cached) => {
            const networkFetch = fetch(req)
              .then((res) => {
                if (res && res.status === 200) {
                  const copy = res.clone();
                  caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
                }
                return res;
              })
              .catch(() =>
                cached ||
                caches.match(self.SwaapinSWCore.OFFLINE_URL).then((o) => o || new Response('', { status: 503 }))
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
    },

    onMessage: function (event, cacheName) {
      if (event.data?.type === 'IOS_CLEAR_NAV_CACHE') {
        caches.open(cacheName).then(async (cache) => {
          const keys = await cache.keys();
          keys.forEach((k) => {
            if (k.mode === 'navigate' || (!k.url.includes('.'))) cache.delete(k);
          });
        });
      }
    },
  });
})();
