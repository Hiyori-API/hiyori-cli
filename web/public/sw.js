/* Hiyori dashboard PWA service worker.
 *
 *  - /api/* GET: STALE-WHILE-REVALIDATE — a reload returns the last-known
 *    payload instantly, then refreshes in the background.
 *  - /api/events: SSE, network only.
 *  - Navigation + hashed assets: cache-first (offline-capable, fast reloads).
 */
const CACHE = 'hiyori-v3'
const API_SWR_CACHE = 'hiyori-api-v3'

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE && k !== API_SWR_CACHE).map((k) => caches.delete(k))),
    ).then(() => self.clients.claim()),
  )
})

async function staleWhileRevalidate(request) {
  const cached = await caches.match(request)
  const network = fetch(request)
    .then((r) => {
      if (r && r.ok) {
        const clone = r.clone()
        caches.open(API_SWR_CACHE).then((c) => c.put(request, clone))
      }
      return r
    })
    .catch(() => cached || Response.error())
  return cached || network
}

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url)
  if (e.request.method !== 'GET') return
  if (url.origin !== self.location.origin) return

  if (url.pathname === '/api/events') return

  if (url.pathname.startsWith('/api/') || url.pathname === '/metrics') {
    e.respondWith(staleWhileRevalidate(e.request))
    return
  }

  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request)
        .then((r) => {
          const clone = r.clone()
          caches.open(CACHE).then((c) => c.put(e.request, clone))
          return r
        })
        .catch(() => caches.match(e.request).then((hit) => hit || caches.match('/'))),
    )
    return
  }

  e.respondWith(
    caches.match(e.request).then(
      (hit) =>
        hit ||
        fetch(e.request).then((r) => {
          if (r && r.ok && r.status < 400) {
            const clone = r.clone()
            caches.open(CACHE).then((c) => c.put(e.request, clone))
          }
          return r
        }),
    ),
  )
})
