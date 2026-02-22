const CACHE_NAME = 'greenfield-v2';
const APP_SHELL = [
  '/greenfield/public/assets/css/app.css',
  '/greenfield/public/assets/js/app.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const isStatic = url.origin === self.location.origin
    && (url.pathname.startsWith('/greenfield/public/assets/')
      || url.pathname.endsWith('.css')
      || url.pathname.endsWith('.js')
      || url.pathname.endsWith('.png')
      || url.pathname.endsWith('.svg')
      || url.pathname.endsWith('.ico'));

  if (!isStatic || event.request.method !== 'GET') {
    return;
  }

  event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
});
