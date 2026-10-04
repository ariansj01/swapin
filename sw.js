importScripts('/sw-core.js');

(function () {
  'use strict';

  // SW v2 — align with sw-core 1.0.1 (fixed defaultFetchResponse returning undefined)
  const CACHE_NAME = 'swaapin-cache-v2';

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
