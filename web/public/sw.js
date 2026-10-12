/* Hiyori dashboard PWA service worker: app-shell cache + network-first API */
const CACHE = 'hiyori-v1'
const SHELL = ['/', '/fur-sw.js'] // content hashed by build; cache at runtime
self.addEventListener('install', (e) => { self.skipWaiting() })
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url)
  if (e.request.method !== 'GET') return
  if (url.pathname.startsWith('/api/') || url.pathname === '/metrics') {
    // network-first for live data; fall back to cache on failure
    e.respondWith(fetch(e.request).then((r) => {
      const c = r.clone(); caches.open(CACHE).then((x) => x.put(e.request, c))
      return r
    }).catch(() => caches.match(e.request)))
    return
  }
  // app-shell / assets: cache-first
  e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request).then((r) => {
    const c = r.clone(); caches.open(CACHE).then((x) => x.put(e.request, c))
    return r
  })))
})
