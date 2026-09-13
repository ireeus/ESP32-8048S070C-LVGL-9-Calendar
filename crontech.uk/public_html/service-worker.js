self.addEventListener('install', (e) => {
  e.waitUntil(caches.open('calendar-cache').then((cache) => cache.addAll(['/'])));
});

self.addEventListener('fetch', (e) => {
  // UWAGA: Ignoruj wszystkie zapytania inne niż GET (np. POST z formularzy)
  if (e.request.method !== 'GET') {
    return; 
  }

  e.respondWith(
    caches.match(e.request).then((response) => response || fetch(e.request))
  );
});