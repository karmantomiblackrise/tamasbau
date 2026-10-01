/*
 * Tamás Bau service worker – v2
 * - /api/ válaszok, admin oldalak, tokenes oldalak és nem-GET kérések SOHA nem kerülnek cache-be.
 * - HTML: hálózat először, offline esetén mentett példány vagy offline.html.
 * - Statikus fájlok (/assets/, ikon, manifest): stale-while-revalidate.
 * - A mobil munkalap adatokat nem a service worker, hanem a mobile.js tárolja (felhasználónként).
 */
const CACHE_NAME = 'tamasbau-static-v2';
const STATIC_ASSETS = [
  '/index.html',
  '/mobile.html',
  '/offline.html',
  '/manifest.webmanifest',
  '/pwa-icon.svg',
  '/assets/css/app.css',
  '/assets/js/common.js',
  '/assets/js/mobile.js'
];
const NEVER_CACHE_PREFIXES = ['/api/', '/admin', '/review.html', '/sign.html', '/uploads/', '/logs/', '/database/', '/vendor/'];
const CACHEABLE_PAGES = ['/', '/index.html', '/mobile.html', '/offline.html', '/aszf.html', '/impresszum.html', '/adatkezelesi-tajekoztato.html', '/cookie-tajekoztato.html', '/elallasi-tajekoztato.html'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => Promise.all(STATIC_ASSETS.map((url) => cache.add(url).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function isStorable(response) {
  if (!response || response.status !== 200 || response.type !== 'basic') return false;
  const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
  return !cc.includes('no-store') && !cc.includes('private');
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (NEVER_CACHE_PREFIXES.some((p) => url.pathname.startsWith(p))) return;

  const isNavigation = request.mode === 'navigate' || request.destination === 'document';
  if (isNavigation) {
    const cacheable = CACHEABLE_PAGES.includes(url.pathname);
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (cacheable && isStorable(response)) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(url.pathname, copy));
          }
          return response;
        })
        .catch(() => caches.match(url.pathname).then((hit) => hit || caches.match('/offline.html')))
    );
    return;
  }

  const isStatic = url.pathname.startsWith('/assets/') || url.pathname === '/pwa-icon.svg' || url.pathname === '/manifest.webmanifest';
  if (!isStatic) return;
  event.respondWith(
    caches.open(CACHE_NAME).then((cache) => cache.match(request).then((cached) => {
      const network = fetch(request)
        .then((response) => {
          if (isStorable(response)) cache.put(request, response.clone());
          return response;
        })
        .catch(() => cached);
      return cached || network;
    }))
  );
});
