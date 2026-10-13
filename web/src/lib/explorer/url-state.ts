/**
 * URL hash state for the Explorer — the single source of truth for
 * shareable, back/forward-friendly page state.
 *
 * Rendered as: `#collection=..&q=..&field=..&filter=a,b&sort=..&dir=..&page=..&per=..&cols=a,b&view=..&doc=..`
 * written with history.replaceState() so the URL stays copy/shareable
 * without spamming history entries. `hashchange`/`popstate` re-parse the
 * hash so manual edits and back/forward navigation are honoured.
 *
 * `doc` is a deep link: when present on load the detail sheet opens for
 * that id.
 */

export interface ExplorerUrlState {
  collection: string
  q: string
  /** Search scope: 'all' | 'title' | 'synonyms' | 'id' | 'refs' */
  field: string
  /** Active prebuilt filter ids (multi-filter chips), e.g. ['no_mal', 'has_mal'] */
  filters: string[]
  sort: string
  dir: 'asc' | 'desc'
  page: number
  per: number
  /** Visible columns in display order */
  cols: string[]
  /** view-id (saved/prebuilt view name) */
  view: string
  /** Deep-link id — opens the detail sheet for this doc on load */
  doc: string
  /** Range inputs (kept as strings from the inputs, sent when non-empty) */
  epsMin: string
  epsMax: string
  yearMin: string
  yearMax: string
}

export const URL_DEFAULTS: ExplorerUrlState = {
  collection: '',
  q: '',
  field: 'all',
  filters: [],
  sort: '',
  dir: 'asc',
  page: 1,
  per: 25,
  cols: [],
  view: '',
  doc: '',
  epsMin: '',
  epsMax: '',
  yearMin: '',
  yearMax: '',
}

/** URL param name ⇄ state key. `filter` (comma list) maps to the multi-chip `filters`. */
const PARAM_MAP: Array<[string, keyof ExplorerUrlState]> = [
  ['collection', 'collection'],
  ['q', 'q'],
  ['field', 'field'],
  ['filter', 'filters'],
  ['sort', 'sort'],
  ['dir', 'dir'],
  ['page', 'page'],
  ['per', 'per'],
  ['cols', 'cols'],
  ['view', 'view'],
  ['doc', 'doc'],
  ['epsMin', 'epsMin'],
  ['epsMax', 'epsMax'],
  ['yearMin', 'yearMin'],
  ['yearMax', 'yearMax'],
]

const LIST_KEYS = new Set<keyof ExplorerUrlState>(['filters', 'cols'])
const NUM_KEYS = new Set<keyof ExplorerUrlState>(['page', 'per'])

export function parseHash(hash: string): Partial<ExplorerUrlState> {
  const raw = hash.replace(/^#/, '')
  if (!raw.trim()) return {}
  let sp: URLSearchParams
  try {
    sp = new URLSearchParams(raw)
  } catch {
    return {}
  }
  const out: Partial<ExplorerUrlState> = {}
  for (const [param, key] of PARAM_MAP) {
    const v = sp.get(param)
    if (v === null || v === '') continue
    if (NUM_KEYS.has(key)) {
      const n = Number(v)
      if (Number.isFinite(n)) out[key] = n as never
    } else if (LIST_KEYS.has(key)) {
      out[key] = v.split(',').filter(Boolean) as never
    } else {
      out[key] = v as never
    }
  }
  if (out.dir !== 'asc' && out.dir !== 'desc') out.dir = 'asc'
  if (out.field && !['all', 'title', 'synonyms', 'id', 'refs'].includes(out.field)) out.field = 'all'
  return out
}

export function serializeHash(s: Partial<ExplorerUrlState>): string {
  const sp = new URLSearchParams()
  for (const [param, key] of PARAM_MAP) {
    const v = s[key]
    if (v === undefined || v === null || v === '') continue
    if (Array.isArray(v)) {
      if (v.length > 0) sp.set(param, v.join(','))
    } else if (typeof v === 'number') {
      if (key === 'page' && v === 1) continue
      if (key === 'per' && v === 25) continue
      sp.set(param, String(v))
    } else if (v !== (URL_DEFAULTS[key] as string)) {
      sp.set(param, String(v))
    }
  }
  return sp.toString()
}

/** Build the full URL (path + query + hash) for a state. */
export function urlFor(s: Partial<ExplorerUrlState>): string {
  const qs = serializeHash(s)
  return window.location.pathname + window.location.search + (qs ? `#${qs}` : '')
}