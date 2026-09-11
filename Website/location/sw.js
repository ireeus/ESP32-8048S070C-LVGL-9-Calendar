const CACHE_NAME = 'location-tracker-v1';
const STATIC_ASSETS = [
    '/local.html',
    'https://cdn.tailwindcss.com'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method === 'GET' && url.pathname.endsWith('receive.php')) {
        if (!navigator.onLine) {
            event.respondWith(
                Promise.resolve(new Response(JSON.stringify({ status: 'offline' }), {
                    headers: { 'Content-Type': 'application/json' }
                }))
            );
        } else {
            event.respondWith(fetch(event.request));
        }
        return;
    }
    event.respondWith(
        caches.match(event.request).then(response => response || fetch(event.request))
    );
});