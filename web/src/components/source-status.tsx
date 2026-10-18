import { cn } from 'cn'
import { jobPhase, type HealthCheck, type Job } from '@/lib/api'

export type SourceLevel = 'ok' | 'warn' | 'crit' | 'none' | 'done' | 'paused'
export interface SourceStatus { level: SourceLevel; label: string }

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

/**
 * Row status: the source's own run phase, overridden by API health.
 *
 * The blip used to report API health alone, which made every source look
 * identical — a finished ingest and a live one both rendered green, because
 * "the API answered" says nothing about whether the job is doing anything. The
 * dot now answers the question the row is actually asking: does this source
 * need me?
 *
 *   crit  API unreachable, circuit open, or HTTP failure — needs attention
 *   warn  API degraded, low tokens, or a run that claims to be going but has
 *         not written state recently (stale)
 *   ok    running and healthy
 *   done  finished successfully — nothing to do, so deliberately not green
 *   none  no health signal for this source
 */
export function rowStatus(
  job: Job | undefined,
  health: HealthCheck | undefined,
  key: string,
  paused = false,
): SourceStatus {
  const api = sourceStatus(health, key)
  // An API failure outranks everything: a stopped circuit is the reason a run
  // is not progressing, whatever phase the job claims.
  if (api.level === 'crit') return api

  // Pause outranks the phase. A paused worker still reports `running` in its
  // state file, so without this the blip kept showing the green "working" dot
  // after Pause — it was the only signal on the row that did not react, and it
  // contradicted the Status column beside it.
  if (paused) return { level: 'paused', label: 'paused' }

  const phase = jobPhase(job)
  if (phase === 'done') return { level: 'done', label: api.label }
  if (phase === 'stale') return { level: 'warn', label: api.label ? `stale · ${api.label}` : 'stale' }
  return api
}

const DOT: Record<SourceLevel, string> = {
  ok: 'bg-emerald-500',
  // A finished run is deliberately not green: green here means "working", and
  // a completed job is at rest.
  done: 'bg-sky-500',
  warn: 'bg-warn animate-pulse',
  crit: 'bg-red-500 animate-pulse',
  // Held, not working and not broken: a deliberate stop should not read as
  // either health or failure.
  paused: 'bg-muted-foreground',
  none: 'bg-muted',
}

export function SourceStatusBlip({ status, size = 'md' }: { status: SourceStatus; size?: 'sm' | 'md' }) {
  return (
    <span title={status.label} aria-label={status.label || 'status'} role="img" className={cn('inline-block shrink-0 rounded-full', DOT[status.level], size === 'sm' ? 'h-1.5 w-1.5' : 'h-2 w-2')} />
  )
}

export function SourceStatusLabel({ status }: { status: SourceStatus }) {
  if (status.level === 'ok' || status.level === 'done' || status.level === 'paused') {
    return <span className="text-[10px] text-muted-foreground">{status.label}</span>
  }
  if (status.level === 'none') return null
  const cls = status.level === 'crit' ? 'text-red-400' : 'text-warn'
  return <span className={cn('text-[10px] font-medium', cls)}>● {status.label}</span>
}

/**
 * Source identity marks.
 *
 * Manga sources inherit their parent API's mark (anilist_manga is AniList),
 * which is the truth: they hit the same GraphQL endpoint.
 *
 * The favicons are *vendored* under public/brand rather than hotlinked. That was
 * not the first attempt: pointing at each service's CDN failed immediately —
 * AniList's own `logo.png` path returns an HTML page with a 200 status, which
 * the browser cannot decode as an image, and Kitsu's logo path 404s. A local
 * operator dashboard should not depend on a third party's CDN being up, on its
 * path staying valid, or on not being rate-limited while you are ingesting.
 * The monogram fallback below still renders if an image ever fails, so a row
 * never loses its identity.
 */
const BRAND: Record<string, { name: string; logo: string; tint: string }> = {
  anilist:     { name: 'AniList',     logo: '/brand/anilist.ico',     tint: '#02a9ff' },
  kitsu:       { name: 'Kitsu',       logo: '/brand/kitsu.ico',       tint: '#e15e1a' },
  myanimelist: { name: 'MyAnimeList', logo: '/brand/myanimelist.ico', tint: '#2e51a2' },
}

/** Manga sources scrape the same endpoint as their anime parent. */
function brandFor(source: string) {
  const base = source.endsWith('_manga') ? source.slice(0, -'_manga'.length) : source
  return BRAND[base] ?? null
}

export function SourceLogo({ source, size = 16 }: { source: string; size?: number }) {
  const brand = brandFor(source)
  if (!brand) return null
  const initial = brand.name.charAt(0)
  return (
    <span
      className="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-sm bg-muted"
      style={{ width: size, height: size }}
      title={brand.name}
    >
      <img
        src={brand.logo}
        alt={`${brand.name} logo`}
        width={size}
        height={size}
        loading="lazy"
        decoding="async"
        referrerPolicy="no-referrer"
        className="h-full w-full object-contain"
        style={{ color: brand.tint }}
        onError={(e) => {
          // Swap in the monogram rather than leaving a broken-image glyph.
          const el = e.currentTarget
          el.style.display = 'none'
          const parent = el.parentElement
          if (parent && !parent.querySelector('[data-fallback]')) {
            const span = document.createElement('span')
            span.setAttribute('data-fallback', '1')
            span.textContent = initial
            span.style.fontSize = `${Math.round(size * 0.62)}px`
            span.style.fontWeight = '600'
            span.style.color = brand.tint
            span.style.lineHeight = '1'
            parent.appendChild(span)
          }
        }}
      />
    </span>
  )
}
