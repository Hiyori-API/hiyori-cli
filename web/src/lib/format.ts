/** Shared formatting helpers (single source of truth across pages). */
import { tzStr, type Tz } from './time'

export function fmt(n?: number | null): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  return n.toLocaleString()
}

/** Compact thousand/million notation, e.g. 18.3K / 1.2M (G4). */
export function fmtCompact(n?: number | null): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  return n.toLocaleString('en', { notation: 'compact', maximumFractionDigits: 1 })
}

export function ago(ts?: number | string): string {
  if (!ts) return '—'
  const t = typeof ts === 'string' ? Date.parse(ts) / 1000 : ts
  if (!t || Number.isNaN(t)) return '—'
  const s = Math.max(0, Math.floor(Date.now() / 1000 - t))
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)}m`
  if (s < 86400) return `${Math.floor(s / 3600)}h`
  return `${Math.floor(s / 86400)}d`
}

export function fmtSec(n?: number | null): string {
  if (n === undefined || n === null || !Number.isFinite(n)) return '—'
  if (n < 90) return `${Math.round(n)}s`
  if (n < 5400) return `${Math.round(n / 60)}m`
  return `${Math.round(n / 3600)}h ${Math.round((n % 3600) / 60)}m`
}

export function mb(n?: number | null): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  return (n / 1048576).toFixed(1) + ' MB'
}

export function timeStr(ts?: string, tz: Tz = 'local'): string {
  // Delegate to the tz-aware formatter so pages honoring prefs.tz stay in sync.
  return tzStr(ts, tz)
}
