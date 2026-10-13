/**
 * Doc helpers shared across the Explorer UI: id/title/ref extraction,
 * image/link detection and source-brand colours.
 */

import type { ExploreRow } from '@/lib/api'

export function stringId(d: ExploreRow | null | undefined): string {
  if (!d) return ''
  const id = d._id as Record<string, unknown> | string | undefined
  if (id && typeof id === 'object') return String((id as { $oid?: unknown }).$oid ?? id)
  return String(id ?? '')
}

export function titleOf(d: ExploreRow | null | undefined): string {
  const t = d?.title
  if (typeof t === 'string') return t
  if (t && typeof t === 'object') {
    const o = t as Record<string, unknown>
    return String(o.romaji ?? o.english ?? o.native ?? '')
  }
  return ''
}

/** reference_ids as [shorthand, value] pairs with truthy values. */
export function refsOf(d: ExploreRow | null | undefined): Array<[string, string]> {
  const r = d?.reference_ids
  if (!r || typeof r !== 'object') return []
  return Object.entries(r as Record<string, unknown>).filter(([, v]) => v) as Array<[string, string]>
}

export function fieldOf(d: ExploreRow, key: string): unknown {
  if (key === 'title') return titleOf(d)
  const val = d[key]
  if (typeof val === 'object' && val !== null) return JSON.stringify(val)
  return val
}

export function isMissing(v: unknown): boolean {
  return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)
}

const IMAGE_RE = /\.(jpe?g|png|webp|gif|avif)(\?.*)?$/i
const IMAGE_HOST_RE = /(anilist\.co|kitsu\.app|kitsu\.io|sakugabooru|myanimelist|image\.tmdb|media\.kitsu|cdn\.anilist)/i

/** Best-effort: is this value an image URL (or an object/array containing one)? */
export function isImageUrl(v: unknown): boolean {
  return typeof v === 'string' && (IMAGE_RE.test(v) || IMAGE_HOST_RE.test(v))
}

/** Extract the largest image URL from a string / {extraLarge,large,medium} / array. */
export function longestImage(v: unknown): string | null {
  if (typeof v === 'string') return isImageUrl(v) ? v : null
  if (v === null || typeof v !== 'object') return null
  if (Array.isArray(v)) {
    for (const item of v) {
      const u = longestImage(item)
      if (u) return u
    }
    return null
  }
  const o = v as Record<string, unknown>
  const order = ['large', 'extraLarge', 'medium', 'small', 'original', 'url', 'image']
  for (const k of order) {
    const u = longestImage(o[k])
    if (u) return u
  }
  return null
}

/** Extract a clickable http(s) URL from a value that can hold one. */
export function linkUrl(v: unknown): string | null {
  if (typeof v === 'string' && /^https?:\/\//i.test(v)) return v
  if (v === null || typeof v !== 'object') return null
  const o = v as Record<string, unknown>
  for (const k of ['url', 'siteUrl', 'large', 'extraLarge', 'medium']) {
    const u = linkUrl(o[k])
    if (u) return u
  }
  return null
}

/**
 * Collect every image URL worth showing in a gallery (F2): deduped, with known
 * cover/banner/image/pictures fields first, then any other string URL that
 * looks like an image or object carrying url/medium/extraLarge-style keys.
 */
export function collectImages(d: ExploreRow | null | undefined): string[] {
  if (!d) return []
  const out: string[] = []
  const seen = new Set<string>()
  const push = (u: unknown): void => {
    if (typeof u === 'string' && isImageUrl(u) && !seen.has(u)) {
      seen.add(u)
      out.push(u)
    }
  }
  const walk = (v: unknown, depth: number): void => {
    if (typeof v === 'string') { push(v); return }
    if (v === null || typeof v !== 'object' || depth > 8) return
    if (Array.isArray(v)) {
      for (const item of v) walk(item, depth + 1)
      return
    }
    const o = v as Record<string, unknown>
    // Image-shorthand object ({extraLarge, large, medium, url, …}) → best single URL.
    for (const k of ['extraLarge', 'large', 'medium', 'small', 'original', 'url']) {
      const u = o[k]
      if (typeof u === 'string' && isImageUrl(u)) { push(u); return }
    }
    for (const k of Object.keys(o)) walk(o[k], depth + 1)
  }
  for (const f of ['coverImage', 'posterImage', 'bannerImage', 'cover', 'images', 'pictures']) walk(d[f], 0)
  walk(d, 0)
  return out
}

export function describeField(f: string): string {
  const desc: Record<string, string> = {
    title: 'Primary title (romaji/english/native)',
    _id: 'MongoDB document id',
    reference_ids: 'External reference ids (mal/kitsu/anilist…)',
    references: 'Source URLs gathered during ingest',
    synonyms: 'Alternate titles used for matching',
    format: 'Type: TV, Movie, OVA, Manga…',
    type: 'Anime/manga type (legacy alias of format)',
    episodes: 'Episode count',
    chapters: 'Chapter count',
    volumes: 'Volume count',
    status: 'Publishing status',
    season: 'Broadcast season',
    year: 'Broadcast/publish year',
    start_date: 'Start date',
    end_date: 'End date',
    genres: 'Genre tags',
    tags: 'Content tags',
    images: 'Image URLs (covers, screenshots)',
    coverImage: 'Cover image object',
    score: 'Average score (0–100)',
    popularity: 'Popularity rank',
    favorites: 'Favourites count',
    source: 'Ingest source',
    siteUrl: 'Canonical page URL',
  }
  return desc[f] ?? (f.startsWith('reference_ids.') ? 'External reference id' : '')
}

export interface Brand { label: string; color: string; bg: string; dim: string }

const BRANDS: Record<string, Brand> = {
  anilist: { label: 'AN', color: '#02a9ff', bg: 'rgba(2,169,255,0.16)', dim: 'rgba(2,169,255,0.5)' },
  kitsu: { label: 'KI', color: '#ff6473', bg: 'rgba(255,100,115,0.16)', dim: 'rgba(255,100,115,0.5)' },
  myanimelist: { label: 'MA', color: '#2e51a2', bg: 'rgba(46,81,162,0.16)', dim: 'rgba(46,81,162,0.5)' },
  organized: { label: 'OR', color: '#8b5cf6', bg: 'rgba(139,92,246,0.16)', dim: 'rgba(139,92,246,0.5)' },
  manga_organized: { label: 'MO', color: '#f59e0b', bg: 'rgba(245,158,11,0.16)', dim: 'rgba(245,158,11,0.5)' },
  anime_organized: { label: 'AO', color: '#22c55e', bg: 'rgba(34,197,94,0.16)', dim: 'rgba(34,197,94,0.5)' },
  manga: { label: 'MG', color: '#f59e0b', bg: 'rgba(245,158,11,0.16)', dim: 'rgba(245,158,11,0.5)' },
}

/** Best-effort brand lookup for a collection name. */
export function brandOf(collection: string): Brand | null {
  const c = collection.toLowerCase()
  if (c.includes('manga')) return BRANDS.manga // manga variants first (before organized/mal match)
  if (c.includes('myanimelist') || c.includes('mal')) return BRANDS.myanimelist
  if (c.includes('anilist')) return BRANDS.anilist
  if (c.includes('kitsu')) return BRANDS.kitsu
  if (c.includes('organized')) return BRANDS.organized
  return BRANDS[c] ?? null
}

/** Shorthand → canonical external URL (best-effort). */
export function externalUrl(shorthand: string, value: string): string | null {
  const v = value.trim()
  if (!v) return null
  if (/^https?:\/\//i.test(v)) {
    // Keep the URL but drop tracking-ish params? Just return it.
    return v
  }
  const id = v.split('/').filter(Boolean).pop() ?? v
  switch (shorthand) {
    case 'al': return `https://anilist.co/anime/${id}`
    case 'mal': return `https://myanimelist.net/anime/${id}`
    case 'k': return `https://kitsu.io/anime/${id}`
    case 'ann': return `https://www.animenewsnetwork.com/encyclopedia/anime.php?id=${id}`
    case 'wpen': return `https://en.wikipedia.org/wiki/${encodeURIComponent(id)}`
    case 'wpja': return `https://ja.wikipedia.org/wiki/${encodeURIComponent(id)}`
    case 'cr': return `https://www.crunchyroll.com/${id}`
    default: return null
  }
}

/** Map a shorthand to the collection that would own that source (for related-row nav). */
export function sourceCollection(shorthand: string): string | null {
  switch (shorthand) {
    case 'al': return 'anilist'
    case 'mal': return 'myanimelist'
    case 'k': return 'kitsu'
    default: return null
  }
}

/** Is this value an inline-editable scalar (string/number/boolean)? */
export function isScalar(v: unknown): boolean {
  return v === null || v === undefined || ['string', 'number', 'boolean'].includes(typeof v)
}