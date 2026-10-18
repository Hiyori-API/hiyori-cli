/* Hiyori dashboard PWA service worker.
 *
 *  - SECRETS AND WRITES ARE NEVER CACHED. /api/env* (raw/reveal) returns client
 *    secrets, and /api/action, /api/combine, /api/backup, /api/restore,
 *    /api/settings, /api/limits and /api/explore are mutating GETs. Both groups
 *    are bypassed entirely (see BYPASS), so they always hit the network and an
 *    offline request fails loudly instead of replaying a cached body.
 *  - /api/* GET: NETWORK-FIRST, cached only as an offline fallback. This used to
 *    be stale-while-revalidate, which meant every page load rendered the payload
 *    from the *previous* request. Polling surfaces hid that (the next 3s tick
 *    replaced it), but one-shot reads — /api/sample, /api/failures, /api/benchmark
 *    — kept serving the stale body for the whole session, so a click could return
 *    a result the server had already superseded.
 *  - /api/events: SSE, network only.
 *  - Navigation + hashed assets: cache-first (offline-capable, fast reloads).
 */
const CACHE = 'hiyori-v5'
const API_CACHE = 'hiyori-api-v5'

/* Paths that must never touch Cache Storage.
 *
 * /api/env answers with the raw environment and, with ?reveal=<key>, with the
 * decrypted value of a single secret — caching either would persist secrets in
 * Cache Storage and serve them back from disk later.
 *
 * The rest are GETs that mutate server state. Caching them let an offline or
 * flaky request be answered from cache with a success-shaped body, so the UI
 * reported a backup/restore/combine that never happened. They are bypassed
 * (not merely "not cached") so the offline failure surfaces.
 */
const BYPASS = [
  '/api/env',
  '/api/action',
  '/api/combine',
  '/api/backup',
  '/api/restore',
  '/api/settings',
  '/api/limits',
  '/api/explore',
  '/api/events', // SSE, network only
]

function isBypassed(pathname) {
  return BYPASS.some((p) => pathname === p || pathname.startsWith(p + '/'))
}

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

  // Secrets and mutating GETs: do not intercept at all, so there is no cached
  // body to fall back to. Keep SSE network-only for the same reason.
  if (isBypassed(url.pathname)) return

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
