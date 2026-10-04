importScripts('/sw-core.js');

(function () {
  'use strict';

  const CACHE_NAME = 'swaapin-cache-v1';

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: [],
    onInstall: function (cacheName) {
      console.debug('[SW-Fallback] installing cache=', cacheName);
    },
    onActivate: function (cacheName) {
      console.debug('[SW-Fallback] activated cache=', cacheName);
    },
  });
})();
