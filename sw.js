importScripts('/sw-core.js');

(function () {
  'use strict';

  // SW v3 — align with sw-core 1.0.2 (skip all API/.php routes from SW handling + full try/catch)
  const CACHE_NAME = 'swaapin-cache-v3';

  self.SwaapinSWCore.registerCoreLifecycle(CACHE_NAME, {
    extraPrecacheUrls: [],
    onInstall: function (cacheName) {
      try { console.debug('[SW-Fallback] installing cache=', cacheName); } catch (e) {}
    },
    onActivate: function (cacheName) {
      try { console.debug('[SW-Fallback] activated cache=', cacheName); } catch (e) {}
    },
  });
})();
