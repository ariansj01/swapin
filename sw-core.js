/* =========================================================
 * Swaapin PWA — Service Worker Core (shared logic)
 * Imported by: sw-android.js, sw-ios.js, sw.js (fallback)
 * ========================================================= */

self.SWAAPIN_SW_CORE_VERSION = '1.0.0';
self.SWAAPIN_CACHE_BASE = 'swaapin-cache';

self.SwaapinSWCore = (function () {
  'use strict';

  const OFFLINE_URL = '/offline.html';

  const PRECACHE_URLS = [
    OFFLINE_URL,
    '/src/css/fonts.css',
    '/src/css/main.css',
    '/src/js/app.js',
    '/src/js/pwa/platform-detector.js',
    '/src/js/pwa/pwa-core.js',
    '/src/img/fav_icon/favicon.ico',
    '/src/img/fav_icon/apple-touch-icon.png',
    '/src/img/fav_icon/web-app-manifest-192x192.png',
    '/src/img/fav_icon/web-app-manifest-512x512.png',
    '/src/vendor/bootstrap-icons/bootstrap-icons.css',
    '/src/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
  ];

  function isAdminRoute(url) {
    const p = url.pathname;
    return (
      p.startsWith('/admin') ||
      p.startsWith('/dashboard') ||
      p.startsWith('/store') ||
      p.startsWith('/listings/my') ||
      p.startsWith('/listings/create') ||
      p.startsWith('/listings/edit') ||
      p.startsWith('/iso') ||
      p.startsWith('/messages') ||
      p.startsWith('/panel') ||
      p.startsWith('/profile') ||
      p.startsWith('/wallet') ||
      p.startsWith('/checkout') ||
      p.startsWith('/auth') ||
      p.startsWith('/login') ||
      p.startsWith('/register') ||
      p.startsWith('/user')
    );
  }

  function isStaticAsset(url) {
    return /\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|webp|ico|mp4|webm|ogg|mp3|wasm)$/i.test(url.pathname);
  }

  function installHandler(cacheName, extraUrls) {
    const urls = PRECACHE_URLS.concat(Array.isArray(extraUrls) ? extraUrls : []);
    return caches.open(cacheName).then((cache) =>
      cache.addAll(urls.map((u) => new Request(u, { cache: 'reload' }))).catch(() => {})
    );
  }

  function activateHandler(cacheName) {
    return caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k !== cacheName)
          .filter((k) => k.startsWith(self.SWAAPIN_CACHE_BASE))
          .map((k) => caches.delete(k))
      )
    );
  }

  function navigateResponse(req, cacheName) {
    return fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(req).then((r) => r || caches.match(OFFLINE_URL)));
  }

  function staticResponse(req, cacheName) {
    return caches.match(req).then((cached) =>
      cached ||
      fetch(req).then((res) => {
        if (res && res.status === 200) {
          const copy = res.clone();
          caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }).catch(() => cached)
    );
  }

  function defaultFetchResponse(req, cacheName) {
    return fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(req));
  }

  function defaultFetchHandler(event, cacheName, extensions) {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (isAdminRoute(url)) return;

    if (req.mode === 'navigate') {
      event.respondWith(navigateResponse(req, cacheName));
      return;
    }

    if (isStaticAsset(url)) {
      event.respondWith(staticResponse(req, cacheName));
      return;
    }

    event.respondWith(defaultFetchResponse(req, cacheName));
  }

  function defaultMessageHandler(event) {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
    if (event.data && event.data.type === 'GET_VERSION') {
      event.source?.postMessage({ type: 'SW_VERSION', value: self.SWAAPIN_SW_CORE_VERSION });
    }
  }

  function registerCoreLifecycle(cacheName, options) {
    const opts = options || {};
    const extraPrecache = opts.extraPrecacheUrls || [];
    const onInstall = typeof opts.onInstall === 'function' ? opts.onInstall : null;
    const onActivate = typeof opts.onActivate === 'function' ? opts.onActivate : null;
    const onFetch = typeof opts.onFetch === 'function' ? opts.onFetch : null;
    const onMessage = typeof opts.onMessage === 'function' ? opts.onMessage : null;

    self.addEventListener('install', (event) => {
      event.waitUntil(
        Promise.resolve()
          .then(() => installHandler(cacheName, extraPrecache))
          .then(() => (onInstall ? onInstall(cacheName, event) : undefined))
          .then(() => self.skipWaiting())
      );
    });

    self.addEventListener('activate', (event) => {
      event.waitUntil(
        Promise.resolve()
          .then(() => activateHandler(cacheName))
          .then(() => (onActivate ? onActivate(cacheName, event) : undefined))
          .then(() => self.clients.claim())
      );
    });

    self.addEventListener('fetch', (event) => {
      if (onFetch) {
        const handled = onFetch(event, cacheName);
        if (handled === true) return;
      }
      defaultFetchHandler(event, cacheName, opts.fetchExtensions);
    });

    self.addEventListener('message', (event) => {
      defaultMessageHandler(event);
      if (onMessage) onMessage(event, cacheName);
    });
  }

  return {
    PRECACHE_URLS,
    OFFLINE_URL,
    isAdminRoute,
    isStaticAsset,
    installHandler,
    activateHandler,
    navigateResponse,
    staticResponse,
    defaultFetchResponse,
    defaultFetchHandler,
    defaultMessageHandler,
    registerCoreLifecycle,
  };
})();
