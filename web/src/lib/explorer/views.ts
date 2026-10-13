/**
 * Server-side saved views for the Explorer (B5).
 *
 * Views live on the server in ui.json prefs → `explorer_views` (an array of
 * { name, state }) written through the existing POST /api/settings, with a
 * localStorage mirror so they keep working when the server is offline. Cloud
 * views are the source of truth; "local" entries are views saved while the
 * server was unreachable. Save/delete push the cloud subset and re-tag as
 * local on failure.
 */

import { fetchSettings, postSettings, type SavedViewState, type ServerSavedView, type UiSettings } from '@/lib/api'

export type { SavedViewState, ServerSavedView }

/** A view in the merged (cloud + local) list shown in the Views menu. */
export interface SavedView extends ServerSavedView {
  source: 'cloud' | 'local'
}

const VIEWS_KEY = 'hiyori-explorer-views'
const LEGACY_KEY = 'hiyori-saved-queries'

const EMPTY_STATE: SavedViewState = { collection: '', q: '', field: 'all', filters: [], per: 25, sort: '', dir: 'asc', cols: [] }

function isNamedView(v: unknown): v is { name: unknown; state: unknown; source?: unknown } {
  return !!v && typeof v === 'object' && typeof (v as { name?: unknown }).name === 'string'
}

function normalizeState(st: Partial<SavedViewState>): SavedViewState {
  return {
    collection: typeof st.collection === 'string' ? st.collection : '',
    q: typeof st.q === 'string' ? st.q : '',
    field: typeof st.field === 'string' ? st.field : 'all',
    filters: Array.isArray(st.filters) ? st.filters.filter((x): x is string => typeof x === 'string') : [],
    per: typeof st.per === 'number' && st.per > 0 ? st.per : EMPTY_STATE.per,
    sort: typeof st.sort === 'string' ? st.sort : '',
    dir: st.dir === 'desc' ? 'desc' : 'asc',
    cols: Array.isArray(st.cols) ? st.cols.filter((x): x is string => typeof x === 'string') : [],
  }
}

/** Read the localStorage mirror, falling back to the legacy saved-query shape. */
export function loadLocalViews(): SavedView[] {
  try {
    const raw = JSON.parse(localStorage.getItem(VIEWS_KEY) ?? 'null') as unknown
    if (Array.isArray(raw)) {
      return raw
        .filter(isNamedView)
        .filter(v => !!v.state && typeof v.state === 'object')
        .map(v => ({
          name: String(v.name),
          state: normalizeState(v.state as Partial<SavedViewState>),
          source: v.source === 'cloud' ? 'cloud' : 'local',
        }))
    }
  } catch { /* corrupt — fall through to legacy */ }
  // Legacy shape: { name, collection, q, filters }
  try {
    const raw = JSON.parse(localStorage.getItem(LEGACY_KEY) ?? '[]') as unknown
    if (Array.isArray(raw)) {
      return raw.filter(isNamedView).map(v => ({
        name: String(v.name),
        state: normalizeState({
          collection: typeof (v as { collection?: unknown }).collection === 'string' ? String((v as { collection?: unknown }).collection) : '',
          q: typeof (v as { q?: unknown }).q === 'string' ? String((v as { q?: unknown }).q) : '',
          filters: Array.isArray((v as { filters?: unknown }).filters) ? ((v as { filters?: unknown[] }).filters ?? []).filter((x): x is string => typeof x === 'string') : [],
        }),
        source: 'local' as const,
      }))
    }
  } catch { /* ignore */ }
  return []
}

export function persistLocalViews(views: SavedView[]): void {
  try {
    localStorage.setItem(VIEWS_KEY, JSON.stringify(views))
  } catch { /* storage unavailable — the server mirror still holds cloud views */ }
}

/** Merge server (cloud) + local mirrors; cloud wins on name collisions. */
export function mergeViews(cloud: ServerSavedView[], local: SavedView[]): SavedView[] {
  const byName = new Map<string, SavedView>()
  for (const v of cloud) {
    if (!v || typeof v.name !== 'string' || !v.name) continue
    byName.set(v.name, { name: v.name, state: normalizeState(v.state), source: 'cloud' })
  }
  for (const v of local) {
    if (!v || typeof v.name !== 'string' || !v.name) continue
    if (!byName.has(v.name)) {
      byName.set(v.name, { name: v.name, state: normalizeState(v.state), source: v.source === 'cloud' ? 'cloud' : 'local' })
    }
  }
  return [...byName.values()]
}

/** Fetch cloud views plus a fresh prefs snapshot (used to merge on push). */
export async function fetchServerViews(): Promise<{ cloud: ServerSavedView[]; prefs?: UiSettings['prefs'] } | null> {
  try {
    const res = await fetchSettings()
    return { cloud: res.settings?.prefs?.explorer_views ?? [], prefs: res.settings?.prefs }
  } catch {
    return null
  }
}

/**
 * Push the cloud subset to the server via POST /api/settings. The backend
 * replaces `prefs` wholesale, so we send the full prefs object (+ our key) and
 * return the fresh prefs snapshot on success. Offline → { ok: false }.
 */
export async function pushViewsToServer(views: SavedView[], basePrefs?: UiSettings['prefs']): Promise<{ ok: boolean; prefs?: UiSettings['prefs'] }> {
  const cloud = views.filter(v => v.source === 'cloud').map(({ name, state }) => ({ name, state }))
  try {
    const res = await postSettings({ prefs: { ...(basePrefs ?? {}), explorer_views: cloud } })
    return { ok: true, prefs: res.settings?.prefs }
  } catch {
    return { ok: false }
  }
}