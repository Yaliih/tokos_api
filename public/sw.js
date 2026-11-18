/*
 * Sosa : service worker (application installable, page hors connexion, fichiers du site en cache).
 * - Pages : toujours le réseau d'abord ; sans réseau, la dernière version vue, sinon la page « hors connexion ».
 * - CSS, JS, icônes, polices : servis depuis le cache et mis à jour en arrière-plan.
 * - Photos : gardées en cache (200 au plus) pour ne pas les retélécharger.
 * - Vidéos, audios, admin, envois de formulaires : jamais mis en cache.
 */
const VERSION = 'sosa-v10';
const STATIC = VERSION + '-static';
const PAGES = VERSION + '-pages';
const IMAGES = VERSION + '-images';
const PRECACHE = ['/offline.html', '/icons/icon-192.png', '/favicon.svg', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

async function trim(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) {
    // Polices Google : en cache aussi
    if (/fonts\.(googleapis|gstatic)\.com$/.test(url.hostname)) {
      event.respondWith(caches.open(STATIC).then(async (cache) => {
        const hit = await cache.match(req);
        const net = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => hit);
        return hit || net;
      }));
    }
    return;
  }
  if (url.pathname.startsWith('/admin') || url.pathname.startsWith('/livewire') || url.pathname.startsWith('/api/') && !url.pathname.startsWith('/api/get-media/')) return;
  if (req.headers.get('range')) return; // lecture vidéo / audio par morceaux

  // Pages
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const res = await fetch(req);
        if (res.ok && res.type === 'basic') {
          const cache = await caches.open(PAGES);
          cache.put(req, res.clone());
          trim(PAGES, 30);
        }
        return res;
      } catch (e) {
        return (await caches.match(req)) || (await caches.match('/offline.html'));
      }
    })());
    return;
  }

  // Fichiers du site
  if (/^\/(css|js|icons|fonts|vendor)\//.test(url.pathname) || url.pathname === '/favicon.svg') {
    event.respondWith(caches.open(STATIC).then(async (cache) => {
      const hit = await cache.match(req);
      const net = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }

  // Photos (pas les vidéos ni les audios)
  if ((url.pathname.startsWith('/api/get-media/') || url.pathname.startsWith('/storage/')) && /,(jpe?g|png|webp|gif)$|\.(jpe?g|png|webp|gif)$/i.test(url.pathname)) {
    event.respondWith(caches.open(IMAGES).then(async (cache) => {
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) { cache.put(req, res.clone()); trim(IMAGES, 200); }
      return res;
    }));
  }
});

// Notifications push : afficher, puis ouvrir la bonne page au toucher
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Sosa', {
    body: data.body || '',
    icon: data.icon || '/icons/icon-192.png',
    badge: '/icons/badge-96.png',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    data: { url: data.url || '/notifications' },
    lang: 'fr',
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (new URL(w.url).origin === location.origin && 'focus' in w) { await w.focus(); return w.navigate(url); }
    }
    return self.clients.openWindow(url);
  })());
});
