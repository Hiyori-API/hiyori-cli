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
    external_apis?: { healthy?: boolean; endpoints?: Record<string, { healthy?: boolean; latency_ms?: number; status_code?: number; error?: string }> }
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

export interface StatusPayload { /* extended below */ }
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
  error?: string
}

export async function postAction(job: string, cmd: 'pause' | 'resume' | 'retry'): Promise<ActionResponse> {
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

export async function fetchSample(collection: string): Promise<{ sample?: Record<string, unknown> | null }> {
  const r = await fetch(`/api/sample?collection=${encodeURIComponent(collection)}`)
  return r.json() as Promise<{ sample?: Record<string, unknown> | null }>
}

export async function runAction(job: string, cmd: 'pause' | 'resume' | 'retry'): Promise<ActionResponse> {
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
