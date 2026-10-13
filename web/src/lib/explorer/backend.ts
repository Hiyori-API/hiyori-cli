/**
 * Backend access for the Explorer.
 *
 * Wraps the *existing* /api/explore contract (pagination/sort/search/filter)
 * and adds defensive calls for the newer endpoints (update / bulk / undo /
 * restore / import / facets / ranges) that may or may not exist yet on the
 * backend. Every call degrades gracefully: a 404 or 5xx returns `{ error }`
 * instead of throwing, so the caller can fall back to existing behaviour.
 */

import { fetchMeta as apiFetchMeta, deleteDocs as apiDeleteDocs, type ExploreRow } from '@/lib/api'

export interface ExploreReq {
  collection: string
  page: number
  per: number
  q?: string
  /** Search scope: all|title|synonyms|id|refs — sent through, backend may ignore. */
  field?: string
  /** Legacy single `filter` value (first prebuilt chip — best-effort today). */
  filter?: string
  /** Full active filter list (newer contract — may be ignored by older backends). */
  filters?: string
  format?: string
  status?: string
  sort?: string
  dir?: 'asc' | 'desc'
  /** Comma list of facet axes to request, e.g. 'format,status'. */
  facets?: string
  /** Regex search flag — sent as regex=1 (backend support optional). */
  regex?: boolean
  epsMin?: string
  epsMax?: string
  yearMin?: string
  yearMax?: string
}

export interface ExploreResp {
  collection?: string
  page?: number
  per?: number
  total?: number
  docs?: ExploreRow[]
  fields?: string[]
  /** Present only when the backend honours `facets=...`. */
  facets?: Record<string, Array<{ v: string; n: number }>>
  error?: string
}

export async function explore(p: ExploreReq): Promise<ExploreResp> {
  const qs = new URLSearchParams()
  const entries: Array<[string, string | number | undefined]> = [
    ['collection', p.collection],
    ['page', p.page],
    ['per', p.per],
    ['q', p.q],
    ['field', p.field],
    ['filter', p.filter],
    ['filters', p.filters],
    ['format', p.format],
    ['status', p.status],
    ['sort', p.sort],
    ['dir', p.dir],
    ['facets', p.facets],
    ['regex', p.regex ? 1 : undefined],
    ['episodes_min', p.epsMin],
    ['episodes_max', p.epsMax],
    ['year_min', p.yearMin],
    ['year_max', p.yearMax],
  ]
  for (const [k, v] of entries) {
    if (v !== undefined && v !== null && v !== '') qs.set(k, String(v))
  }
  const r = await fetch(`/api/explore?${qs.toString()}`)
  if (!r.ok) {
    try {
      return (await r.json()) as ExploreResp
    } catch {
      return { error: `explore ${r.status}` }
    }
  }
  return (await r.json()) as ExploreResp
}

async function postJson<T>(url: string, body: object): Promise<T> {
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
    if (!r.ok) {
      try {
        const j = await r.json()
        return { ...(j as object), error: (j as { error?: string }).error ?? `POST ${r.status}` } as T
      } catch {
        return { error: `POST ${r.status}` } as T
      }
    }
    return (await r.json()) as T
  } catch (e) {
    return { error: String(e) } as T
  }
}

export interface UpdateResp { ok?: boolean; doc?: ExploreRow; error?: string }
/** POST /api/explore/update — scalar field edit (audited server-side). */
export async function updateDoc(collection: string, id: string, field: string, value: string): Promise<UpdateResp> {
  return postJson<UpdateResp>('/api/explore/update', { collection, id, field, value })
}

export interface BulkResp { ok?: boolean; count?: number; error?: string }
/** POST /api/explore/bulk — e.g. { action: 'set', key, value } on many ids. */
export async function bulkAction(collection: string, ids: string[], action: string, key?: string, value?: string): Promise<BulkResp> {
  return postJson<BulkResp>('/api/explore/bulk', { collection, ids, action, ...(key !== undefined ? { key } : {}), ...(value !== undefined ? { value } : {}) })
}

export async function undoExplore(): Promise<BulkResp> {
  return postJson<BulkResp>('/api/explore/undo', {})
}

export async function restoreExplore(file: string): Promise<BulkResp> {
  return postJson<BulkResp>('/api/explore/restore', { file })
}

export async function importExplore(collection: string, docs: unknown[]): Promise<BulkResp> {
  return postJson<BulkResp>('/api/explore/import', { collection, docs })
}

export { apiFetchMeta, apiDeleteDocs }

export interface ValidationEvent { ts?: string; source?: string; title?: string; errors?: Record<string, string[]> }

/** GET /api/validate — tail of validation events (best-effort for the sheet). */
export async function fetchValidation(): Promise<ValidationEvent[]> {
  try {
    const r = await fetch('/api/validate')
    if (!r.ok) return []
    const j = (await r.json()) as { recent?: ValidationEvent[] }
    return j.recent ?? []
  } catch {
    return []
  }
}