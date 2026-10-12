import { cn } from 'cn'
import type { HealthCheck } from '@/lib/api'

export interface SourceStatus { level: 'ok' | 'warn' | 'crit' | 'none'; label: string }

/** Map an ingestion source key to its API health + rate-limit state. */
export function sourceStatus(health: HealthCheck | undefined, key: string): SourceStatus {
  const apis = health?.checks?.external_apis?.endpoints ?? {}
  const base = key === 'anilist_manga' ? 'anilist' : key === 'kitsu_manga' ? 'kitsu' : key === 'myanimelist_manga' ? 'myanimelist' : key
  const ep = apis[base]
  const lim = health?.checks?.rate_limiter?.limiters?.[key]

  if (lim?.circuit_state === 'open') return { level: 'crit', label: 'circuit open' }

  if (!ep) return { level: 'none', label: '' }

  if (ep.reachable === false) return { level: 'crit', label: ep.note === 'unreachable' ? 'unreachable' : 'timeout' }
  if (ep.healthy === false) {
    const label = ep.note === 'rate_limited' ? 'rate-limited' : `HTTP ${ep.status_code ?? 'err'}`
    return { level: 'warn', label }
  }

  const lat = ep.latency_ms != null ? `${Math.round(ep.latency_ms)}ms` : 'ok'
  const tokenLow = lim && lim.tokens_available != null && lim.tokens_available < 5
  return { level: tokenLow ? 'warn' : 'ok', label: tokenLow ? `low tokens ${lim.tokens_available}` : lat }
}

const DOT: Record<SourceStatus['level'], string> = {
  ok: 'bg-emerald-500',
  warn: 'bg-amber-500 animate-pulse',
  crit: 'bg-red-500 animate-pulse',
  none: 'bg-muted',
}

export function SourceStatusBlip({ status, size = 'md' }: { status: SourceStatus; size?: 'sm' | 'md' }) {
  return (
    <span title={status.label} className={cn('inline-block shrink-0 rounded-full', DOT[status.level], size === 'sm' ? 'h-1.5 w-1.5' : 'h-2 w-2')} />
  )
}

export function SourceStatusLabel({ status }: { status: SourceStatus }) {
  if (status.level === 'ok') return <span className="text-[10px] text-muted-foreground">{status.label}</span>
  if (status.level === 'none') return null
  const cls = status.level === 'crit' ? 'text-red-400' : 'text-amber-400'
  return <span className={cn('text-[10px] font-medium', cls)}>● {status.label}</span>
}
