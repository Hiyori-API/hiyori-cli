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

export async function postAction(job: string, cmd: 'pause' | 'resume'): Promise<ActionResponse> {
  const r = await fetch(`/api/action?job=${encodeURIComponent(job)}&cmd=${cmd}`)
  return r.json() as Promise<ActionResponse>
}

export async function runBackup(): Promise<{ started?: boolean; archive?: string }> {
  const r = await fetch('/api/backup?run=1')
  return r.json() as Promise<{ started?: boolean; archive?: string }>
}
