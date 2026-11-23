const CACHE_NAME = 'sshhh-pwa-v2.0.0';
const PRECACHE_ASSETS = [
    './offline.html',
    './manifest.webmanifest',
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

    // API, webhooks, alert decisions and streamed downloads always go to the network
    const scopePath = new URL(self.registration.scope).pathname;
    const rel = url.pathname.startsWith(scopePath) ? url.pathname.slice(scopePath.length) : url.pathname;
    if (/^(api\/|api\.php|webhook|alert|logout|login|setup)/.test(rel)) {
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
        url.pathname.includes('/build/') ||
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


// ---------------------------------------------------------------------------
// Push notifications: error alerts with Proceed / Ignore
// ---------------------------------------------------------------------------
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'SSHhh', body: event.data ? event.data.text() : '' };
    }
    const options = {
        body: data.body || '',
        tag: data.tag || 'sshhh',
        renotify: true,
        requireInteraction: !!data.requireInteraction,
        icon: './assets/img/icon-192.png',
        badge: './assets/img/favicon-32.png',
        data: { url: data.url || './', token: data.token || null },
        actions: Array.isArray(data.actions) ? data.actions : []
    };
    event.waitUntil(self.registration.showNotification(data.title || 'SSHhh', options));
});

self.addEventListener('notificationclick', (event) => {
    const n = event.notification;
    const d = n.data || {};
    n.close();

    if ((event.action === 'proceed' || event.action === 'ignore') && d.token) {
        event.waitUntil(
            fetch(new URL('alert/decide', self.registration.scope), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ token: d.token, choice: event.action, via: 'push' })
            })
                .then((res) => res.json())
                .then((r) => self.registration.showNotification(
                    event.action === 'proceed' ? (r.ok ? '🤖 Sent to Claude Code' : '❌ Could not send to Claude') : 'Alert ignored',
                    { body: r.message || r.error || '', tag: n.tag, icon: './assets/img/icon-192.png', data: { url: r.session_url || d.url } }
                ))
                .catch(() => self.registration.showNotification('Could not reach SSHhh', {
                    body: 'Open the alert to choose again.', tag: n.tag, data: { url: d.url }
                }))
        );
        return;
    }

    // Tapping the notification body opens the alert page (also the fallback where action buttons are not shown, e.g. iPhone)
    const target = new URL(d.url || './', self.registration.scope).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const w of wins) {
                if (w.url === target && 'focus' in w) return w.focus();
            }
            return self.clients.openWindow(target);
        })
    );
});
