const CACHE = 'ayo-static-v1';

const PRECACHE_URLS = [
  '/css/app.css',
  '/css/backoffice.css',
  '/js/api.js',
  '/js/auth.js',
  '/js/cart.js',
  '/js/address.js',
  '/js/notifications.js',
  '/js/format.js',
  '/assets/logo.png',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Jamais toucher aux requêtes qui modifient des données, ni à un domaine externe
  // (l'API vit sur un sous-domaine séparé — ses réponses ne doivent jamais être mises en cache).
  if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    // Pages HTML : toujours la version la plus fraîche en priorité, le cache sert de secours hors-ligne.
    event.respondWith(
      fetch(request).catch(() => caches.match(request).then((cached) => cached ?? caches.match('/index.html')))
    );

    return;
  }

  // Assets statiques (CSS/JS/images) : cache en priorité, réseau en secours + mise à jour silencieuse.
  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request).then((response) => {
        if (response.ok) {
          caches.open(CACHE).then((cache) => cache.put(request, response.clone()));
        }

        return response;
      }).catch(() => cached);

      return cached ?? network;
    })
  );
});
