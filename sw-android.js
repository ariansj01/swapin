importScripts('/sw-core.js');

(function () {
  'use strict';

  const CACHE_NAME = 'swaapin-cache-android-v1';
  const EXTRA_PRECACHE = [
    '/src/js/pwa/pwa-android.js',
    '/src/img/fav_icon/site-android.webmanifest',
  ];

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: EXTRA_PRECACHE,

    onInstall: function (cacheName, event) {
      console.debug('[SW-Android] installing cache=', cacheName);
    },

    onActivate: function (cacheName, event) {
      console.debug('[SW-Android] activated cache=', cacheName);
    },

    onFetch: function (event, cacheName) {
      const req = event.request;
      if (req.mode !== 'navigate') return false;
      const url = new URL(req.url);
      const isAdmin = self.SwaapinSWCore.isAdminRoute(url);
      if (!isAdmin) return false;
      event.respondWith(
        fetch(req).catch(() => caches.match(req).then((r) => r || caches.match(self.SwaapinSWCore.OFFLINE_URL)))
      );
      return true;
    },

    onMessage: function (event, cacheName) {
      if (event.data?.type === 'ANDROID_BADGE') {
        // reserved for future badge sync
      }
    },
  });
})();
