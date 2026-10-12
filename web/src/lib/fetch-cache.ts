import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'
import type { ValidationEvent } from './api'

/** Shared GET cache: dedupes identical /api requests for TTL ms (module-wide). */
const TTL = 5000
const cache = new Map<string, { at: number; promise: Promise<unknown> }>()

export function cachedFetch<T>(url: string, opts: { force?: boolean } = {}): Promise<T> {
  const now = Date.now()
  if (!opts.force) {
    const hit = cache.get(url)
    if (hit && now - hit.at < TTL) return hit.promise as Promise<T>
  }
  const promise = fetch(url).then(async r => {
    if (!r.ok) throw new Error(`${url} ${r.status}`)
    return r.json() as Promise<T>
  })
  cache.set(url, { at: now, promise })
  if (cache.size > 64) {
    for (const [k, v] of cache) if (Date.now() - v.at > TTL) cache.delete(k)
  }
  return promise
}

export interface CachedFetchState<T> {
  data: T | null
  error: string | null
  loading: boolean
}

/**
 * Fetch a JSON endpoint once, then serve from the shared 5s cache on re-renders.
 * `bypassKey` forces a fresh request whenever it changes (used by refresh buttons).
 * A per-effect sequence counter discards stale responses (race guard).
 */
export function useCachedFetch<T>(url: string, bypassKey?: unknown): CachedFetchState<T> {
  const [state, setState] = useState<CachedFetchState<T>>({ data: null, error: null, loading: true })
  const seq = useRef(0)
  const prevKey = useRef<unknown>(bypassKey)

  useEffect(() => {
    const id = ++seq.current
    const force = prevKey.current !== bypassKey
    prevKey.current = bypassKey
    setState(s => ({ ...s, error: null, loading: true }))
    cachedFetch<T>(url, { force })
      .then(d => { if (seq.current === id) setState({ data: d, error: null, loading: false }) })
      .catch(e => { if (seq.current === id) setState({ data: null, error: String(e), loading: false }) })
    return () => { seq.current++ }
  }, [url, bypassKey])

  return state
}

// ---- shared validation-failures store ---------------------------------------
// Management, Quality and Logs all render /api/failures. Instead of each page
// mounting its own fetch+state, they subscribe to one module-level snapshot that
// is refetched at most once per 5s (via the shared cache above).

let failuresCache: ValidationEvent[] = []
const failuresListeners = new Set<() => void>()

const subscribeFailures = (l: () => void) => {
  failuresListeners.add(l)
  return () => { failuresListeners.delete(l) }
}
const getFailuresSnapshot = () => failuresCache

/** Re-fetch validation failures (deduped by cachedFetch) and notify subscribers. */
export async function refreshFailures(): Promise<ValidationEvent[]> {
  const r = await cachedFetch<{ failures?: ValidationEvent[] }>('/api/failures')
  failuresCache = r.failures ?? []
  ;[...failuresListeners].forEach(l => l())
  return failuresCache
}

/** Live, shared view of validation failures + a refresh() that busts the cache. */
export function useFailures(): { failures: ValidationEvent[]; refresh: () => Promise<void> } {
  const failures = useSyncExternalStore(subscribeFailures, getFailuresSnapshot)
  const refresh = useCallback(() => refreshFailures().then(() => undefined), [])
  useEffect(() => { void refresh() }, [refresh])
  return { failures, refresh }
}
