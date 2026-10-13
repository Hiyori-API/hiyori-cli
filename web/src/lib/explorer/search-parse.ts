/**
 * Search-term parsing and lightweight duplicate detection (Jaro–Winkler).
 */

import type { ExploreRow } from '@/lib/api'
import { titleOf } from './doc-utils'

export type SearchField = 'all' | 'title' | 'synonyms' | 'id' | 'refs'

export const SEARCH_FIELDS: Array<{ value: SearchField; label: string }> = [
  { value: 'all', label: 'All fields' },
  { value: 'title', label: 'Title' },
  { value: 'synonyms', label: 'Synonyms' },
  { value: 'id', label: 'ID' },
  { value: 'refs', label: 'References' },
]

export interface ParsedTerm { field: SearchField; term: string }

const PREFIXES: Array<[string, SearchField]> = [
  ['title:', 'title'],
  ['synonyms:', 'synonyms'],
  ['id:', 'id'],
  ['refs:', 'refs'],
  ['all:', 'all'],
]

/** Parse a `scope:term` prefix typed into the search box (title:steins…). */
export function parseQueryTerm(raw: string): ParsedTerm {
  const s = raw.trim()
  for (const [p, f] of PREFIXES) {
    if (s.toLowerCase().startsWith(p)) {
      return { field: f, term: s.slice(p.length).trimStart() }
    }
  }
  return { field: 'all', term: s }
}

// ---- Jaro–Winkler similarity (client-side dup scan) ----

function jaro(a: string, b: string): number {
  if (a === b) return 1
  const lenA = a.length
  const lenB = b.length
  if (lenA === 0 || lenB === 0) return 0
  const matchDist = Math.max(lenA, lenB) / 2 - 1
  const aMatches = new Array<boolean>(lenA).fill(false)
  const bMatches = new Array<boolean>(lenB).fill(false)
  let matches = 0
  for (let i = 0; i < lenA; i++) {
    const lo = Math.max(0, i - matchDist)
    const hi = Math.min(i + matchDist + 1, lenB)
    for (let j = lo; j < hi; j++) {
      if (bMatches[j] || a[i] !== b[j]) continue
      aMatches[i] = true
      bMatches[j] = true
      matches++
      break
    }
  }
  if (matches === 0) return 0
  let t = 0
  let k = 0
  for (let i = 0; i < lenA; i++) {
    if (!aMatches[i]) continue
    while (!bMatches[k]) k++
    if (a[i] !== b[k]) t++
    k++
  }
  const m = matches
  return (m / lenA + m / lenB + (m - t / 2) / m) / 3
}

function winkler(a: string, b: string, j: number): number {
  const prefixLen = Math.min(4, a.length, b.length)
  let l = 0
  while (l < prefixLen && a[l].toLowerCase() === b[l].toLowerCase()) l++
  return j + l * 0.1 * (1 - j)
}

export function jaroWinkler(a: string, b: string): number {
  const sim = jaro(a.toLowerCase(), b.toLowerCase())
  return winkler(a.toLowerCase(), b.toLowerCase(), sim)
}

export interface DupPair { a: ExploreRow; b: ExploreRow; score: number }

/** Normalise a title for comparison (punctuation becomes a word separator). */
function normTitle(s: string): string {
  return s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim()
}

/**
 * Client-side duplicate scan: every row in `targets` vs every row in `rows`
 * (which usually includes the targets). Returns pairs scoring >= threshold,
 * deduped (a < b ordering by id) and excluding identical ids.
 */
export function findDupes(targets: ExploreRow[], rows: ExploreRow[], threshold = 0.86): DupPair[] {
  const out: DupPair[] = []
  const seen = new Set<string>()
  const idOf = (r: ExploreRow): string => {
    const id = r._id
    if (id && typeof id === 'object') return String((id as { $oid?: unknown }).$oid ?? id)
    return String(id ?? '')
  }
  for (const t of targets) {
    const ta = normTitle(titleOf(t))
    if (!ta) continue
    for (const r of rows) {
      const ra = normTitle(titleOf(r))
      if (!ra) continue
      const idA = idOf(t)
      const idB = idOf(r)
      if (idA && idA === idB) continue
      const key = idA && idB ? [idA, idB].sort().join('|') : ''
      if (key && seen.has(key)) continue
      const score = jaroWinkler(ta, ra)
      if (score >= threshold) {
        if (key) seen.add(key)
        out.push({ a: t, b: r, score })
      }
    }
  }
  return out.sort((x, y) => y.score - x.score)
}