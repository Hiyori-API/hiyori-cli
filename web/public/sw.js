/* Hiyori dashboard PWA service worker.
 *
 *  - /api/* GET: NETWORK-FIRST, cached only as an offline fallback. This used to
 *    be stale-while-revalidate, which meant every page load rendered the payload
 *    from the *previous* request. Polling surfaces hid that (the next 3s tick
 *    replaced it), but one-shot reads — /api/sample, /api/failures, /api/benchmark
 *    — kept serving the stale body for the whole session, so a click could return
 *    a result the server had already superseded.
 *  - /api/events: SSE, network only.
 *  - Navigation + hashed assets: cache-first (offline-capable, fast reloads).
 */
const CACHE = 'hiyori-v4'
const API_CACHE = 'hiyori-api-v4'

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE && k !== API_CACHE).map((k) => caches.delete(k))),
    ).then(() => self.clients.claim()),
  )
})

async function networkFirst(request) {
  try {
    const res = await fetch(request)
    if (res && res.ok) {
      const clone = res.clone()
      caches.open(API_CACHE).then((c) => c.put(request, clone))
    }
    return res
  } catch (err) {
    const hit = await caches.match(request)
    if (hit) return hit
    throw err
  }
}

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url)
  if (e.request.method !== 'GET') return
  if (url.origin !== self.location.origin) return

  if (url.pathname === '/api/events') return

  if (url.pathname.startsWith('/api/') || url.pathname === '/metrics') {
    e.respondWith(networkFirst(e.request))
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
