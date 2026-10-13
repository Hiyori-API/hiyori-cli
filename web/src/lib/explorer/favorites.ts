/**
 * Favorites, recent searches and first-visit onboarding flag — all keyed in
 * localStorage. Favorites are per-collection so ids don't collide across
 * sources (anilist #1 ≠ mal #1).
 */

const favKey = (collection: string) => `hiyori-explorer-favorites:${collection}`

export function loadFavs(collection: string): Set<string> {
  try {
    const arr = JSON.parse(localStorage.getItem(favKey(collection)) ?? '[]') as unknown
    if (Array.isArray(arr)) return new Set(arr.filter((x): x is string => typeof x === 'string'))
  } catch {
    /* corrupted storage — start fresh */
  }
  return new Set()
}

export function saveFavs(collection: string, favs: Set<string>): void {
  try {
    localStorage.setItem(favKey(collection), JSON.stringify([...favs]))
  } catch {
    /* storage full/unavailable — favourites just won't persist this run */
  }
}

/** Toggle a favourite. Returns the new Set so the caller can store it. */
export function toggleFav(collection: string, id: string, current: ReadonlySet<string>): Set<string> {
  const next = new Set(current)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  saveFavs(collection, next)
  return next
}

// ---- recent searches ----
export interface RecentSearch { q: string; field: string; ts: number }

const RECENTS_KEY = 'hiyori-explorer-recents'
const MAX_RECENTS = 8

export function loadRecents(): RecentSearch[] {
  try {
    const arr = JSON.parse(localStorage.getItem(RECENTS_KEY) ?? '[]') as unknown
    if (Array.isArray(arr)) return arr.filter((x): x is RecentSearch => !!(x && typeof x === 'object' && typeof (x as RecentSearch).q === 'string'))
  } catch {
    /* ignore */
  }
  return []
}

export function addRecent(q: string, field: string): RecentSearch[] {
  const cur = loadRecents().filter(r => !(r.q === q && r.field === field))
  const next = [{ q, field, ts: Date.now() }, ...cur].slice(0, MAX_RECENTS)
  try {
    localStorage.setItem(RECENTS_KEY, JSON.stringify(next))
  } catch {
    /* ignore */
  }
  return next
}

// ---- onboarding ----
export const ONBOARD_KEY = 'hiyori-explorer-onboarded'

export function isOnboarded(): boolean {
  try {
    return localStorage.getItem(ONBOARD_KEY) === '1'
  } catch {
    return true // no storage — don't nag
  }
}

export function markOnboarded(): void {
  try {
    localStorage.setItem(ONBOARD_KEY, '1')
  } catch {
    /* ignore */
  }
}