export interface Job {
  source?: string
  phase?: string
  processed?: number
  total?: number
  per_second?: number
  saved?: number
  skipped?: number
  errors?: number
  eta_seconds?: number
  started_at_ts?: number
  mode?: string
  history?: { t: number; v: number }[]
  [k: string]: unknown
}

export interface ValidationEvent {
  ts?: string
  source?: string
  title?: string
  errors?: Record<string, string[]>
}

export interface HealthCheck {
  status?: string
  timestamp?: string
  checks?: {
    mongodb?: { healthy?: boolean; latency_ms?: number; details?: string; error?: string }
    external_apis?: { healthy?: boolean; all_reachable?: boolean; endpoints?: Record<string, { healthy?: boolean; reachable?: boolean; latency_ms?: number; status_code?: number; note?: string; error?: string }> }
    rate_limiter?: { healthy?: boolean; limiters?: Record<string, { tokens_available?: number; max_tokens?: number; circuit_state?: string; failures?: number }> }
    disk_space?: { healthy?: boolean; used_percent?: number; free_gb?: number; total_gb?: number }
    memory?: { healthy?: boolean; used_percent?: number; current_mb?: number; peak_mb?: number; limit?: string }
  }
}

export interface DbStats {
  dataSize?: number
  indexSize?: number
  storageSize?: number
  totalDocuments?: number
  collections?: Record<string, number>
}

export interface BackupInfo {
  exists?: boolean
  count?: number
  last?: { file?: string; mtime?: number; size?: number }
}

export interface CombineStats {
  strategy?: string
  base?: string
  merges?: number
  threshold?: number
  scores?: { avg?: number; min?: number; max?: number }
  score_histogram?: { p70_80?: number; p80_90?: number; p90_100?: number }
  completed_at?: string
}

export interface CoverageRow {
  total?: number
  [shorthand: string]: number | undefined
}
export interface AlertItem {
  level?: string
  type?: string
  source?: string
  msg?: string
}
export interface BenchmarkRow {
  strategy?: string
  wall_s?: number
  merges?: number
  organized?: number
  speed?: number
}

export interface StatusPayload {
  timestamp: string
  counts: Record<string, number>
  jobs: Record<string, Job>
  health: HealthCheck
  validation: ValidationEvent[]
  combine: CombineStats
  db?: DbStats
  backup?: BackupInfo
  controls?: Record<string, { paused?: boolean }>
  cache?: { requests?: number; enabled?: boolean; host?: string }
  coverage?: Record<string, CoverageRow>
  alerts?: AlertItem[]
  slow?: SlowEntry[]
  schedule?: ScheduleInfo
}

export async function fetchStatus(): Promise<StatusPayload> {
  const r = await fetch('/api/status')
  if (!r.ok) throw new Error(`status ${r.status}`)
  return r.json()
}

export interface ActionResponse {
  job?: string
  cmd?: string
  control?: { paused?: boolean }
  scheduled?: boolean
  error?: string
}

export type ActionCmd = 'pause' | 'resume' | 'retry' | 'start' | 'runnow'

export async function postAction(job: string, cmd: ActionCmd): Promise<ActionResponse> {
  const r = await fetch(`/api/action?job=${encodeURIComponent(job)}&cmd=${cmd}`)
  return r.json() as Promise<ActionResponse>
}

export async function runBackup(): Promise<{ started?: boolean; archive?: string }> {
  const r = await fetch('/api/backup?run=1')
  return r.json() as Promise<{ started?: boolean; archive?: string }>
}

export async function fetchBenchmark(): Promise<{ seeded?: BenchmarkRow[]; latest?: unknown }> {
  const r = await fetch('/api/benchmark')
  if (!r.ok) throw new Error(`benchmark ${r.status}`)
  return r.json()
}

export async function fetchSample(collection: string, minConfidence = -1): Promise<{ sample?: Record<string, unknown> | null }> {
  const q = minConfidence >= 0 ? `&min=${minConfidence}` : ''
  const r = await fetch(`/api/sample?collection=${encodeURIComponent(collection)}${q}`)
  return r.json() as Promise<{ sample?: Record<string, unknown> | null }>
}

export async function runAction(job: string, cmd: ActionCmd): Promise<ActionResponse> {
  return postAction(job, cmd)
}

export interface ScheduleInfo {
  sources?: string
  interval_hours?: number
  next_in?: number | null
  method?: string
}
export interface SlowEntry {
  ts?: string
  url?: string
  ms?: number
  method?: string
}

// ---- Dashboard v2 API ----

export interface ExploreRow { [k: string]: unknown }
export interface ExploreParams {
  collection?: string
  page?: number
  per?: number
  q?: string
  sort?: string
  dir?: 'asc' | 'desc'
  filter?: string
  /** Regex search flag — sent as regex=1 when true (backend support optional). */
  regex?: boolean
}
export interface ExploreResponse {
  collection?: string
  page?: number
  per?: number
  total?: number
  docs?: ExploreRow[]
  fields?: string[]
  error?: string
}

export async function fetchExplore(p: ExploreParams): Promise<ExploreResponse> {
  const qs = new URLSearchParams()
  Object.entries(p).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return
    qs.set(k, k === 'regex' ? (v ? '1' : '') : String(v))
  })
  const r = await fetch(`/api/explore?${qs.toString()}`)
  return r.json() as Promise<ExploreResponse>
}

export async function deleteDocs(collection: string, ids: string[]): Promise<{ deleted?: number; error?: string }> {
  const r = await fetch('/api/explore/delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ collection, ids }),
  })
  return r.json() as Promise<{ deleted?: number; error?: string }>
}

export interface MetaCollection { count?: number; fields?: string[] }
export async function fetchMeta(): Promise<{ collections?: Record<string, MetaCollection> }> {
  const r = await fetch('/api/meta')
  return r.json() as Promise<{ collections?: Record<string, MetaCollection> }>
}

export interface UiSettings {
  prefs?: {
    widgets?: string[]
    refresh?: number
    tz?: string
    theme?: string
    density?: string
    accent?: string
    hiddenCollections?: string[]
    weights?: Record<string, number>
    acks?: string[]
    realtime?: boolean
    /** Server-persisted Explorer views (POST /api/settings → ui.json). */
    explorer_views?: ServerSavedView[]
  }
  limits?: { max?: number; window?: number }
  [k: string]: unknown
}

/** Full Explorer view state captured for a saved view (B5). */
export interface SavedViewState {
  collection: string
  q: string
  field: string
  filters: string[]
  per: number
  sort: string
  dir: 'asc' | 'desc'
  cols: string[]
}

/** A view persisted server-side under ui.json prefs → `explorer_views`. */
export interface ServerSavedView {
  name: string
  state: SavedViewState
}
export interface SettingsResponse {
  settings?: UiSettings
  env?: { token_present?: boolean; webhook?: string; rate_max?: number; rate_window?: number }
  token?: string
  note?: string
  webhook?: string
  reset?: boolean
}

async function jsonPost(url: string, body: object): Promise<unknown> {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  return r.json()
}

export async function fetchSettings(): Promise<SettingsResponse> {
  const r = await fetch('/api/settings')
  return r.json() as Promise<SettingsResponse>
}
export async function postSettings(patch: object): Promise<SettingsResponse> {
  return jsonPost('/api/settings', patch) as Promise<SettingsResponse>
}

export interface BackupItem { file?: string; mtime?: number; size?: number }
export async function fetchBackups(): Promise<{ backups?: BackupItem[] }> {
  const r = await fetch('/api/backups')
  return r.json() as Promise<{ backups?: BackupItem[] }>
}
export async function restoreBackup(file: string): Promise<{ started?: boolean; error?: string }> {
  return jsonPost('/api/restore', { file }) as Promise<{ started?: boolean; error?: string }>
}

export async function fetchFailures(): Promise<{ failures?: ValidationEvent[] }> {
  const r = await fetch('/api/failures')
  return r.json() as Promise<{ failures?: ValidationEvent[] }>
}

export interface LimitsResp { limits?: { max?: number; window?: number }; applied?: string }
export async function fetchLimits(): Promise<LimitsResp> {
  const r = await fetch('/api/limits')
  return r.json() as Promise<LimitsResp>
}
export async function postLimits(patch: { max?: number; window?: number }): Promise<LimitsResp> {
  return jsonPost('/api/limits', patch) as Promise<LimitsResp>
}

export interface AuditItem { ts?: string; action?: string; ip?: string; detail?: Record<string, unknown> }
export async function fetchAudit(): Promise<{ audit?: AuditItem[] }> {
  const r = await fetch('/api/audit')
  return r.json() as Promise<{ audit?: AuditItem[] }>
}

export interface HistoryPayload { states?: Record<string, Job>; runs?: Array<Record<string, unknown>> }
export async function fetchHistory(): Promise<HistoryPayload> {
  const r = await fetch('/api/history')
  return r.json() as Promise<HistoryPayload>
}

export async function fetchHistogram(collection = 'organized'): Promise<{ histogram?: Record<string, number>; bins?: number[]; collection?: string }> {
  const r = await fetch(`/api/histogram?collection=${encodeURIComponent(collection)}`)
  return r.json() as Promise<{ histogram?: Record<string, number>; bins?: number[] }>
}

export async function downloadExport(collection: string, format: 'jsonl' | 'csv'): Promise<void> {
  const a = document.createElement('a')
  a.href = `/api/export?collection=${encodeURIComponent(collection)}&format=${format}`
  a.download = `${collection}.${format}`
  a.click()
}

/** Open the /api/events SSE stream for near-instant updates. */
export interface StreamEvent { counts?: Record<string, number>; jobs?: Record<string, Job>; timestamp?: string }
export function openStatusStream(onEvent: (e: StreamEvent) => void, onError?: (ev: Event) => void): EventSource {
  const es = new EventSource('/api/events')
  es.onmessage = (e) => {
    try {
      onEvent(JSON.parse(e.data) as StreamEvent)
    } catch { /* ignore malformed frames */ }
  }
  if (onError) es.onerror = onError
  return es
}

// ---- Environment variable manager ----
export interface EnvVar {
  key: string
  present?: boolean
  sensitive?: boolean
  masked?: string
  value?: string | null
}

export async function fetchEnv(reveal?: string): Promise<{ env: EnvVar[]; source?: string }> {
  const qs = reveal ? `?reveal=${encodeURIComponent(reveal)}` : ''
  const r = await fetch(`/api/env${qs}`)
  return r.json() as Promise<{ env: EnvVar[]; source?: string }>
}

export async function postEnv(patch: { key: string; value: string | null }): Promise<{ updated?: string; error?: string; env?: EnvVar[] }> {
  return jsonPost('/api/env', patch) as Promise<{ updated?: string; error?: string; env?: EnvVar[] }>
}

// ---- Env manager extras ----
export async function fetchEnvRaw(reveal?: string): Promise<{ raw?: string }> {
  const r = await fetch(`/api/env?raw=1${reveal ? `&reveal=${encodeURIComponent(reveal)}` : ''}`)
  return r.json() as Promise<{ raw?: string }>
}
export async function postEnvRaw(raw: string): Promise<{ raw_updated?: boolean; error?: string }> {
  return jsonPost('/api/env', { raw }) as Promise<{ raw_updated?: boolean; error?: string }>
}
export async function postEnvPatterns(patterns: string[]): Promise<{ patterns?: string[] }> {
  return jsonPost('/api/env', { patterns }) as Promise<{ patterns?: string[] }>
}

export async function fetchBenchmarkManga(): Promise<{ benchmark?: BenchmarkRow[]; source?: string }> {
  const r = await fetch('/api/benchmark-manga')
  return r.json() as Promise<{ benchmark?: BenchmarkRow[]; source?: string }>
}

// ---- Export & Publish ----

/** Package-layout profiles offered by the publish pipeline. */
export type ExportProfile = 'per-entry-json' | 'bulk-jsonl' | 'tables-csv'
export type ExportKind = 'anime' | 'manga'

export const EXPORT_PROFILES: Array<{ value: ExportProfile; label: string; hint: string }> = [
  { value: 'per-entry-json', label: 'Per-entry JSON', hint: 'one pretty file per entity — data/<kind>/<hiyori_id>-<slug>.json' },
  { value: 'bulk-jsonl', label: 'Bulk JSONL', hint: 'one JSON object per line under data/<kind>.jsonl' },
  { value: 'tables-csv', label: 'Tables CSV', hint: 'normalized anime.csv / manga.csv + lookup tables' },
]

/**
 * Field inclusion flags. `tags` / `genres` / `synopsis` / `score` are part of
 * schema v2 and are not populated yet — the UI renders them disabled.
 */
export interface ExportFieldFlags {
  titles?: boolean
  synonyms?: boolean
  references?: boolean
  links?: boolean
  /** schema v2 — not populated yet */
  tags?: boolean
  /** schema v2 — not populated yet */
  genres?: boolean
  /** schema v2 — not populated yet */
  synopsis?: boolean
  /** schema v2 — not populated yet */
  score?: boolean
  [k: string]: boolean | undefined
}

export interface ExportEntityFlags {
  anime?: boolean
  manga?: boolean
  [k: string]: boolean | undefined
}

export interface ExportConfig {
  profile?: ExportProfile | string
  entities?: ExportEntityFlags
  /** Filename template; must contain `{hiyori_id}`. Default `{hiyori_id}-{slug}`. */
  filename_pattern?: string
  fields?: ExportFieldFlags
  /** Write a sorted index file alongside the data. */
  index?: boolean
  /** Pretty-print JSON output. */
  pretty?: boolean
  /** Emit a single bundle/archive instead of loose files. */
  bundle?: boolean
  /** Optional sub-directory under the publish root. */
  subdir?: string
  [k: string]: unknown
}

/** Server-side view of the export pipeline (lock state, last run, storage root). */
export interface ExportState {
  combine_running?: boolean
  export_running?: boolean
  last_dir?: string
  last_run?: string
  storage?: string
  [k: string]: unknown
}

export interface ExportConfigResponse {
  config?: ExportConfig
  template?: ExportConfig
  state?: ExportState
  error?: string
}

export interface ExportPreviewResponse {
  kind?: string
  profile?: string
  entry?: Record<string, unknown> | null
  json?: Record<string, unknown> | null
  text?: string
  error?: string
  [k: string]: unknown
}

export interface ExportDryRunResponse {
  files?: number
  by_kind?: Record<string, number>
  counts?: Record<string, number>
  samples?: string[]
  sample_files?: string[]
  bytes?: number
  size?: number
  dir?: string
  error?: string
  [k: string]: unknown
}

export interface ExportChangelogDeltas {
  added?: number
  changed?: number
  removed?: number
  [k: string]: unknown
}

export interface ExportRunResponse {
  dir?: string
  files?: number
  by_kind?: Record<string, number>
  counts?: Record<string, number>
  bytes?: number
  changelog?: ExportChangelogDeltas
  deltas?: ExportChangelogDeltas
  error?: string
  [k: string]: unknown
}

/**
 * HTTP error carrying the response status so callers can special-case the
 * endpoints that are still being built (404 → "backend not ready") and the
 * export lock (409 → a combine run is in progress). `status === 0` means the
 * request never reached the server.
 */
export class ExportApiError extends Error {
  readonly status: number
  constructor(status: number, message: string) {
    super(message)
    this.name = 'ExportApiError'
    this.status = status
  }
}

async function exportRequest<T>(url: string, init?: RequestInit): Promise<T> {
  let r: Response
  try {
    r = await fetch(url, init)
  } catch (e) {
    throw new ExportApiError(0, `network error: ${String(e)}`)
  }
  if (!r.ok) {
    let detail = ''
    try {
      const body = (await r.json()) as { error?: string; message?: string }
      detail = body.error ?? body.message ?? ''
    } catch { /* non-JSON body (e.g. plain 404) */ }
    throw new ExportApiError(r.status, detail || `${url} → HTTP ${r.status}`)
  }
  return r.json() as Promise<T>
}

/** GET /api/export-config → {config, template, state}. */
export function fetchExportConfig(): Promise<ExportConfigResponse> {
  return exportRequest<ExportConfigResponse>('/api/export-config')
}

/** POST /api/export-config — persist a config patch, or `{ reset: true }` to restore the template. */
export function saveExportConfig(patch: { config?: ExportConfig; reset?: boolean }): Promise<ExportConfigResponse> {
  return exportRequest<ExportConfigResponse>('/api/export-config', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(patch),
  })
}

/** POST /api/export-config {reset} — restore the server template. */
export function resetExportConfig(): Promise<ExportConfigResponse> {
  return saveExportConfig({ reset: true })
}

/** GET /api/export/preview?kind=&profile= — one rendered sample entry. */
export function previewExport(kind: ExportKind, profile?: string): Promise<ExportPreviewResponse> {
  const qs = new URLSearchParams({ kind })
  if (profile) qs.set('profile', profile)
  return exportRequest<ExportPreviewResponse>(`/api/export/preview?${qs.toString()}`)
}

/** GET /api/export/dry-run — file counts + sample filenames without writing. */
export function dryRunExport(): Promise<ExportDryRunResponse> {
  return exportRequest<ExportDryRunResponse>('/api/export/dry-run')
}

/** POST /api/export/run — write the dump to storage/publish. 409 while a combine runs. */
export function runExport(): Promise<ExportRunResponse> {
  return exportRequest<ExportRunResponse>('/api/export/run', { method: 'POST' })
}
