const CACHE_NAME = 'sshhh-pwa-v1.3.0';
const PRECACHE_ASSETS = [
    './offline.html',
    './manifest.webmanifest',
    './assets/css/app.css?v=1.3.0',
    './assets/js/common.js?v=1.3.0',
    './assets/js/dashboard.js?v=1.3.0',
    './assets/js/project.js?v=1.3.0',
    './assets/img/icon.svg',
    './assets/img/logo.svg',
    './assets/img/icon-192.png',
    './assets/img/icon-512.png',
    './assets/img/apple-touch-icon.png',
    './assets/img/favicon-32.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key.startsWith('sshhh-pwa-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Never intercept non-GET requests or API / streaming SSH execution calls
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.endsWith('/api.php') || url.searchParams.has('logout')) {
        return;
    }

    // HTML document navigations: Network-first with offline fallback
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(req).catch(() => caches.match('./offline.html'))
        );
        return;
    }

    // Static assets (CSS, JS, SVG, PNG, manifest): Stale-while-revalidate
    if (
        url.pathname.includes('/assets/') ||
        url.pathname.endsWith('manifest.webmanifest')
    ) {
        event.respondWith(
            caches.open(CACHE_NAME).then(async (cache) => {
                const cached = await cache.match(req);
                const networkFetch = fetch(req)
                    .then((res) => {
                        if (res && res.ok) {
                            cache.put(req, res.clone());
                        }
                        return res;
                    })
                    .catch(() => cached);

                return cached || networkFetch;
            })
        );
    }
});

