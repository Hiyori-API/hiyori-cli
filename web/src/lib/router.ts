/**
 * Hash router for the dashboard shell.
 *
 * URL space: `#/<route>` with an optional query owned by the page, e.g.
 * `#/explorer?collection=anilist&sort=title&page=1`.
 *
 * The Explorer already used the hash for its own state (`#collection=…`).
 * Rather than fight it, the route owns the hash *path* and the page owns the
 * hash *query*: the Explorer keeps writing its parameters through
 * history.replaceState() on every filter change (no history entry, no
 * navigation, immediate URL update), and this router only ever emits and
 * observes the path segment. A page rewrite therefore cannot navigate the app
 * and a route change cannot be mistaken for page state.
 *
 * The default route is represented by the absence of a hash, so the landing
 * URL stays clean; `#/dashboard` is accepted as an alias.
 */

import { useSyncExternalStore } from 'react'

export const ROUTES = ['dashboard', 'explorer', 'settings'] as const

export type Route = (typeof ROUTES)[number]

export const DEFAULT_ROUTE: Route = 'dashboard'

export function isRoute(value: unknown): value is Route {
  return typeof value === 'string' && (ROUTES as readonly string[]).includes(value)
}

/**
 * Route encoded in a hash.
 *
 * `#/explorer?collection=anilist` → `explorer`. An absent, empty or unknown
 * path (`''`, `#`, `#/`, `#/nope`) falls back to the default route, so a stale
 * or mistyped link renders the landing page rather than nothing.
 *
 * A hash with a QUERY but no path is a pre-router Explorer deep link — the old
 * scheme was `#collection=anilist&sort=title`, written by the page itself and
 * also used for `#doc=<id>` links to a single record. Those are still out there
 * in bookmarks and in the docs, so a query-only hash resolves to Explorer
 * rather than falling through to the dashboard. It is distinguishable from an
 * unknown route: `#/nope` has a path segment, `#collection=x` does not.
 */
export function parseRoute(hash: string): Route {
  const raw = hash.replace(/^#/, '')
  const path = (raw.split('?')[0] ?? '').toLowerCase()
  const segment = path.split('/').find(s => s !== '') ?? ''
  if (isRoute(segment)) return segment
  if (isLegacyHash(hash)) return 'explorer'
  return DEFAULT_ROUTE
}

/**
 * The query half of a hash, without the leading `?` — the part a page owns.
 * `#/explorer?collection=x` → `collection=x`; `#/explorer` → `''`.
 */
/**
 * True when a hash predates the router.
 *
 * The old scheme was a *bare* query string with no `?` — `#collection=anilist&
 * sort=title` — because `urlFor` simply concatenated the serialised query onto
 * `#`. So the tell is a `key=value` pair with no route segment in front of it,
 * not a `?`. (`#/nope` has no `=` and stays an unknown route.)
 */
export function isLegacyHash(hash: string): boolean {
  const raw = hash.replace(/^#/, '')
  if (raw === '' || !raw.includes('=')) return false
  // No `?` in the old scheme, so the whole query lands in what looks like the
  // path segment — `collection=anilist&sort=title` is a single segment that
  // contains `=`. That, or the empty segment of `#?a=b`, is the tell.
  const segment = (raw.split('?')[0] ?? '').toLowerCase().split('/').find(s => s !== '') ?? ''
  return segment === '' || segment.includes('=')
}

export function hashQuery(hash: string): string {
  const raw = hash.replace(/^#/, '')
  // A pre-router hash has no route path and no `?` — the whole thing IS the
  // query. Without this the legacy shim restored the right page but dropped
  // its state, so an old `#collection=anilist&doc=12` link opened the Explorer
  // on the default collection instead of the record it named.
  if (isLegacyHash(hash)) return raw
  const i = raw.indexOf('?')
  return i === -1 ? '' : raw.slice(i + 1)
}

/**
 * Hash for a route plus an optional page query (leading `?` ignored).
 * The default route with no query maps to the empty hash.
 */
export function hashFor(route: Route, query = ''): string {
  const q = query.replace(/^\?/, '')
  if (route === DEFAULT_ROUTE && q === '') return ''
  return `#/${route}${q === '' ? '' : `?${q}`}`
}

const listeners = new Set<() => void>()

function emit(): void {
  // Copy first: a listener may unsubscribe while we are iterating.
  for (const listener of [...listeners]) listener()
}

/**
 * External-store subscription for useSyncExternalStore: both browser
 * navigations (Back/Forward, manual hash edits) and our own emit() calls.
 */
function subscribe(onChange: () => void): () => void {
  listeners.add(onChange)
  window.addEventListener('hashchange', onChange)
  window.addEventListener('popstate', onChange)
  return () => {
    listeners.delete(onChange)
    window.removeEventListener('hashchange', onChange)
    window.removeEventListener('popstate', onChange)
  }
}

function getSnapshot(): Route {
  return parseRoute(window.location.hash)
}

/**
 * Current route, kept in sync with Back/Forward, manual hash edits and
 * navigate(). The snapshot is the route alone — not the whole hash — so the
 * Explorer rewriting its query does not re-render the shell.
 */
export function useRoute(): Route {
  return useSyncExternalStore(subscribe, getSnapshot, () => DEFAULT_ROUTE)
}

/**
 * Change the route.
 *
 * Crossing routes pushes a history entry, so Back/Forward traverse pages.
 * Staying on the current route keeps that page's query — the Explorer's
 * filters survive re-selecting its nav item — and a call that would not change
 * the URL is a no-op, so it never stacks duplicate history entries.
 */
export function navigate(route: Route, opts: { replace?: boolean } = {}): void {
  const current = window.location.pathname + window.location.search + window.location.hash
  const currentRoute = parseRoute(window.location.hash)
  const sameRoute = route === currentRoute
  // Re-selecting the active route must not strip the page's own state: the
  // Explorer's `?collection=…` is part of the URL, so carry it across.
  const query = sameRoute ? hashQuery(window.location.hash) : ''
  const target = window.location.pathname + window.location.search + hashFor(route, query)
  if (target === current) return
  try {
    if (opts.replace ?? sameRoute) history.replaceState(null, '', target)
    else history.pushState(null, '', target)
  } catch {
    // A cross-origin target can reject pushState/replaceState; assigning the
    // hash still fires hashchange, which the listeners above observe.
    window.location.hash = hashFor(route, query)
  }
  emit()
}
