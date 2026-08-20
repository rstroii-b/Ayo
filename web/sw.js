const CACHE = 'ayo-static-v5';

const PRECACHE_URLS = [
  '/css/app.css',
  '/css/backoffice.css',
  '/js/api.js',
  '/js/auth.js',
  '/js/cart.js',
  '/js/address.js',
  '/js/notifications.js',
  '/js/push.js',
  '/js/format.js',
  '/assets/logo.png',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  // cache:'no-cache' force une revalidation réseau — sans ça, un asset sans Cache-Control
  // explicite peut être servi depuis le cache HTTP heuristique du navigateur (déjà périmé)
  // au lieu du fichier réellement déployé.
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.all(
        PRECACHE_URLS.map((url) => fetch(url, { cache: 'no-cache' }).then((res) => cache.put(url, res)))
      ))
      .then(() => self.skipWaiting())
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
    // Pages HTML : toujours la version la plus fraîche en priorité (cache:'no-cache' force la
    // revalidation avec le serveur — même raison que plus bas), le cache sert de secours hors-ligne.
    event.respondWith(
      fetch(request, { cache: 'no-cache' }).catch(() => caches.match(request).then((cached) => cached ?? caches.match('/index.html')))
    );

    return;
  }

  // Assets statiques (CSS/JS/images) : cache en priorité, réseau en secours + mise à jour silencieuse.
  // cache:'no-cache' sur la requête réseau — même raison que install() ci-dessus.
  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request, { cache: 'no-cache' }).then((response) => {
        if (response.ok) {
          caches.open(CACHE).then((cache) => cache.put(request, response.clone()));
        }

        return response;
      }).catch(() => cached);

      return cached ?? network;
    })
  );
});

self.addEventListener('push', (event) => {
  if (!event.data) return;

  const data = event.data.json();

  event.waitUntil(
    self.registration.showNotification(data.title ?? 'Ayo', {
      body: data.body ?? '',
      icon: '/assets/icons/icon-192.png',
      badge: '/assets/icons/icon-192.png',
      data: { url: data.url ?? '/index.html' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url ?? '/index.html';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      const existing = clients.find((c) => new URL(c.url).pathname === new URL(url, self.location.origin).pathname);

      if (existing) {
        return existing.focus();
      }

      return self.clients.openWindow(url);
    })
  );
});
