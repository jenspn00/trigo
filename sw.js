// =================================================================
//  Trigo service worker — v5
//
//  Strategi:
//    • HTML (navigations-requests): NETWORK-FIRST med cache-fallback.
//      Så får telefoner altid nyeste app-version, men PWA'en virker
//      stadig offline (viser sidst kendte version).
//    • Alt andet GET: cache-first med baggrunds-opdatering.
//    • POST m.m. røres ikke (save_data.php osv.).
//    • v5: PHP-endpoints (fx fetch_log.php) og andre domæner
//      (kort-tiles, Leaflet-CDN, Nominatim) går altid direkte til
//      nettet. Før blev fetch_log.php cachet, så dashboardet altid
//      viste data én poll for gammelt, og cachen voksede med
//      hver eneste kort-tile.
// =================================================================
const CACHE_NAME = 'trigo-app-v5';
const urlsToCache = ['/', 'index.html', 'manifest.json', 'icon-192-192.png', 'icon-512-512.png'];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;   // tiles, CDN, Nominatim
  if (url.pathname.endsWith('.php')) return;         // live data — aldrig cache

  const isHTML = event.request.mode === 'navigate' ||
                 (event.request.headers.get('accept') || '').includes('text/html');

  if (isHTML) {
    // Network-first: friske deploys slår igennem med det samme
    event.respondWith(
      fetch(event.request)
        .then(resp => {
          if (resp.ok) {   // cache ikke 404/500-sider som "sidst kendte version"
            const clone = resp.clone();
            caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
          }
          return resp;
        })
        .catch(() => caches.match(event.request).then(r => r || caches.match('index.html')))
    );
    return;
  }

  // Øvrige GET: cache-first
  event.respondWith(
    caches.match(event.request).then(cached => {
      const network = fetch(event.request).then(resp => {
        if (resp.ok) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then(c => c.put(event.request, clone));
        }
        return resp;
      }).catch(() => cached);
      return cached || network;
    })
  );
});
