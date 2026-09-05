const CACHE_NAME = 'swaapin-cache-v1';
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
  OFFLINE_URL,
  '/src/css/fonts.css',
  '/src/css/main.css',
  '/src/js/app.js',
  '/src/img/fav_icon/favicon.ico',
  '/src/img/fav_icon/apple-touch-icon.png',
  '/src/img/fav_icon/web-app-manifest-192x192.png',
  '/src/img/fav_icon/web-app-manifest-512x512.png',
  '/src/vendor/bootstrap-icons/bootstrap-icons.css',
  '/src/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
];

const isAdminRoute = (url) => {
  const p = url.pathname;
  return p.startsWith('/admin') ||
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
         p.startsWith('/user');
};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  if (isAdminRoute(url)) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(req).then((r) => r || caches.match(OFFLINE_URL)))
    );
    return;
  }

  const isStatic = /\.(css|js|woff2?|ttf|eot|png|jpg|jpeg|gif|svg|webp|ico|mp4|webm|ogg|mp3)$/i.test(url.pathname);
  if (isStatic) {
    event.respondWith(
      caches.match(req).then((cached) =>
        cached ||
        fetch(req).then((res) => {
          if (res && res.status === 200) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          }
          return res;
        }).catch(() => cached)
      )
    );
    return;
  }

  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(req))
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
