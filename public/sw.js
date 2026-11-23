/*
 * Service worker of the single installable app (REQ-0271, REQ-0480). Caches the app shell so the technician
 * interface opens without network in basements (REQ-0495); queued operations are replayed by offline-queue.js.
 * iPhone: web push works only after "Add to Home Screen" — the app shows a guidance message on first visit (REQ-0496).
 */
const CACHE = 'app-shell-v1';
const SHELL = ['/tech', '/manifest.webmanifest'];
self.addEventListener('install', (e) => e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).catch(() => null)));
self.addEventListener('activate', (e) => e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))));
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  if (new URL(req.url).pathname.startsWith('/api/')) {
    e.respondWith(fetch(req).then((res) => { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); return res; }).catch(() => caches.match(req)));
    return;
  }
  e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
});
self.addEventListener('sync', (e) => { if (e.tag === 'tech-ops') e.waitUntil(self.clients.matchAll().then((cs) => cs.forEach((c) => c.postMessage('flush-queue')))); });
