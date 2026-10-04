/* =========================================================
 * Swaapin PWA — Service Worker Core (shared logic)
 * Imported by: sw-android.js, sw-ios.js, sw.js (fallback)
 * ========================================================= */

self.SWAAPIN_SW_CORE_VERSION = '1.0.2';
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

  function isApiRoute(url) {
    const p = url.pathname;
    return p.startsWith('/api/') || p.endsWith('.php');
  }

  function isStaticAsset(url) {
    return /\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|webp|ico|mp4|webm|ogg|mp3|wasm)$/i.test(url.pathname);
  }

  function emptyResponse(status) {
    return new Response('', { status: status || 503, statusText: 'Service Unavailable', headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
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
    try {
      return fetch(req)
        .then((res) => {
          if (res && res.ok) {
            try {
              const copy = res.clone();
              caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
            } catch (e) {}
          }
          return res;
        })
        .catch(() =>
          caches.match(req).then((r) => {
            if (r) return r;
            return caches.match(OFFLINE_URL).then((o) => o || emptyResponse(503));
          })
        );
    } catch (e) {
      return Promise.resolve(emptyResponse(503));
    }
  }

  function staticResponse(req, cacheName) {
    try {
      return caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req)
          .then((res) => {
            if (res && res.status === 200) {
              try {
                const copy = res.clone();
                caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
              } catch (e) {}
            }
            return res;
          })
          .catch(() => cached || emptyResponse(503));
      });
    } catch (e) {
      return Promise.resolve(emptyResponse(503));
    }
  }

  function defaultFetchResponse(req, cacheName) {
    try {
      return fetch(req)
        .then((res) => {
          if (res && res.ok) {
            try {
              const copy = res.clone();
              caches.open(cacheName).then((c) => c.put(req, copy)).catch(() => {});
            } catch (e) {}
          }
          return res;
        })
        .catch(() =>
          caches.match(req).then((r) => r || emptyResponse(503))
        );
    } catch (e) {
      return Promise.resolve(emptyResponse(503));
    }
  }

  function defaultFetchHandler(event, cacheName, extensions) {
    const req = event.request;
    if (!req || req.method !== 'GET') return;

    let url;
    try {
      url = new URL(req.url);
    } catch (e) {
      return;
    }

    if (url.origin !== location.origin) return;
    if (isAdminRoute(url)) return;

    if (isApiRoute(url)) return;

    try {
      if (req.mode === 'navigate') {
        event.respondWith(navigateResponse(req, cacheName));
        return;
      }

      if (isStaticAsset(url)) {
        event.respondWith(staticResponse(req, cacheName));
        return;
      }

      event.respondWith(defaultFetchResponse(req, cacheName));
    } catch (e) {
      // If respondWith already called or any other issue, ignore to avoid
      // "Failed to convert value to Response"
    }
  }

  function defaultMessageHandler(event) {
    try {
      if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
      if (event.data && event.data.type === 'GET_VERSION') {
        event.source?.postMessage({ type: 'SW_VERSION', value: self.SWAAPIN_SW_CORE_VERSION });
      }
    } catch (e) {}
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
          .catch(() => self.skipWaiting())
      );
    });

    self.addEventListener('activate', (event) => {
      event.waitUntil(
        Promise.resolve()
          .then(() => activateHandler(cacheName))
          .then(() => (onActivate ? onActivate(cacheName, event) : undefined))
          .then(() => self.clients.claim())
          .catch(() => self.clients.claim())
      );
    });

    self.addEventListener('fetch', (event) => {
      try {
        if (onFetch) {
          const handled = onFetch(event, cacheName);
          if (handled === true) return;
        }
        defaultFetchHandler(event, cacheName, opts.fetchExtensions);
      } catch (e) {}
    });

    self.addEventListener('message', (event) => {
      try {
        defaultMessageHandler(event);
        if (onMessage) onMessage(event, cacheName);
      } catch (e) {}
    });
  }

  return {
    PRECACHE_URLS,
    OFFLINE_URL,
    isAdminRoute,
    isApiRoute,
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
