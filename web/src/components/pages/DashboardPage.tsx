import { useEffect, useMemo, useRef, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  Activity, ChevronDown, CircleDot, Database, Gauge, Hash, Layers, Loader2,
  Play, Pause, RotateCcw, Timer,
} from 'lucide-react'
import { Pie, PieChart, Cell, ResponsiveContainer, Tooltip } from 'recharts'
import { cn } from 'cn'
import { ago, fmt, fmtSec } from '@/lib/format'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { useChartColors, useSeriesColors } from '../charts'
import { isIngestJob, isJobLive, jobPhase, postAction, type HealthCheck, type Job } from '@/lib/api'
import { ChangePulse, PageHead, Section, StatusDot, StatStrip } from '../widgets'
import { SourceStatusBlip, SourceStatusLabel, sourceStatus, type SourceStatus } from '../source-status'
import { alignSeries, CompositionStream, SeriesChartCard } from '../chartcard'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'

const MAX = 300

/** Collections written by the ingesters — the input side of the pipeline. */
const SOURCE_COLLECTIONS = ['anilist', 'anilist_manga', 'myanimelist', 'myanimelist_manga', 'kitsu', 'kitsu_manga']

/** Collections shown individually in the dataset donut before the tail is folded. */
const DATASET_TOP_N = 7

const WINDOWS = [
  { label: '1m', value: 20 },
  { label: '5m', value: 100 },
  { label: '15m', value: 300 },
]

/** Compact big counts (≥10k → "12K"); smaller values fall back to fmt. */
function cx(n?: number | null): string {
  return n != null && !Number.isNaN(n) && Math.abs(n) >= 10_000
    ? n.toLocaleString('en', { notation: 'compact' })
    : fmt(n)
}

export function DashboardPage() {
  const { settings } = useSettings()
  const widgets = settings.prefs?.widgets
  const visible = (id: string) => !widgets || widgets.includes(id)
  const hiddenCollections = settings.prefs?.hiddenCollections ?? []
  const { data, error, intervalMs, realtime } = useStatus()
  const [focus, setFocus] = useState<string | null>(null)
  const [win, setWin] = useState(100)
  const startedAt = useRef(Date.now())

  const [rateSeries, setRateSeries] = useState<Record<string, number[]>>({})
  const [countSeries, setCountSeries] = useState<Record<string, number[]>>({})
  useEffect(() => {
    if (!data) return
    setRateSeries(s => {
      const next: Record<string, number[]> = {}
      Object.keys(data.jobs).forEach(k => {
        if (!isIngestJob(k, data.jobs[k] as Job)) return
        next[k] = [...(s[k] ?? []), (data.jobs[k] as Job).per_second ?? 0].slice(-MAX)
      })
      return next
    })
    setCountSeries(s => {
      const next: Record<string, number[]> = {}
      Object.keys(data.counts).forEach(k => {
        next[k] = [...(s[k] ?? []), data.counts[k] ?? 0].slice(-MAX)
      })
      return next
    })
  }, [data])

  const counts = data?.counts ?? {}
  const jobs = data?.jobs ?? {}
  const jobKeys = Object.keys(jobs).filter(k => isIngestJob(k, jobs[k] as Job)).sort()
  const activeJobs = jobKeys.filter(k => isJobLive(jobs[k] as Job))
  const totalDocs = useMemo(() => Object.values(counts).reduce((a, b) => a + b, 0), [counts])
  // Errors are only meaningful for the run you are watching. Summing every job
  // ever recorded — including finished and stale ones — produced a
  // monotonically climbing counter (295 and rising) that read as an active
  // alarm during a healthy ingest. Split the two numbers: failures on the live
  // run, and skips separately, since a skip is usually "already had it", not a
  // fault.
  const { liveErrors, liveSkipped } = useMemo(() => {
    let e = 0
    let s = 0
    for (const k of activeJobs) {
      const j = jobs[k] as Job
      e += j.errors ?? 0
      s += j.skipped ?? 0
    }
    return { liveErrors: e, liveSkipped: s }
  }, [jobs, activeJobs])
  // Only live jobs contribute to the combined rate; a stale run's last
  // per_second would otherwise keep the KPI non-zero forever.
  const activeRate = useMemo(() => jobKeys.reduce((a, k) => a + (isJobLive(jobs[k] as Job) ? ((jobs[k] as Job).per_second ?? 0) : 0), 0), [jobs, jobKeys])

  const COLORS = useChartColors()
  const dataset = useMemo(() => {
    const keys = Object.keys(counts).sort().filter(k => !hiddenCollections.includes(k))
    return keys.map((k, i) => ({ name: k, value: counts[k], color: COLORS[i % COLORS.length] }))
  }, [counts, hiddenCollections, COLORS])

  /**
   * The donut shows the largest collections plus an "other" slice.
   *
   * Every collection was listed individually, which made the card a 15-row
   * scroll area of mostly zeros — `_migrations (4)`, `embeddings (3)`,
   * `pair_rejections (0)` — competing with the three collections that actually
   * hold the data. The tail is folded into one slice and the full breakdown is
   * already available in the Explorer and Sources table.
   */
  const datasetTop = useMemo(() => {
    const sorted = [...dataset].sort((a, b) => b.value - a.value)
    const head = sorted.slice(0, DATASET_TOP_N)
    const tail = sorted.slice(DATASET_TOP_N)
    const tailTotal = tail.reduce((s, e) => s + e.value, 0)
    return tailTotal > 0
      ? [...head, { name: `other (${tail.length})`, value: tailTotal, color: COLORS[COLORS.length - 1], isOther: true }]
      : head
  }, [dataset, COLORS])

  const rateSeriesArr = useMemo(() => {
    const keys = focus ? jobKeys.filter(k => k.replace('ingest-', '') === focus || k === `ingest-${focus}`) : jobKeys
    return keys.map(k => ({ key: k.replace('ingest-', ''), color: COLORS[jobKeys.indexOf(k) % COLORS.length], data: (rateSeries[k] ?? []).slice(-win) }))
  }, [rateSeries, jobKeys, focus, win])

  const countSeriesArr = useMemo(() => {
    const keys = Object.keys(countSeries).sort().filter(k => !hiddenCollections.includes(k))
    return keys.map((k, i) => ({ key: k, color: COLORS[i % COLORS.length], data: (countSeries[k] ?? []).slice(-win) }))
  }, [countSeries, hiddenCollections, win])

  // Sample cadence: the SSE stream pushes every 3s, otherwise it follows the
  // smart-poll interval. Drives the x-axis labels and keeps MAX >= the largest
  // selectable window (15m) so that option can actually fill.
  const stepSec = realtime ? 3 : Math.max(1, Math.round(intervalMs / 1000))
  const ratesRows = useMemo(() => alignSeries(rateSeriesArr, stepSec), [rateSeriesArr, stepSec])
  const countsRows = useMemo(() => alignSeries(countSeriesArr, stepSec), [countSeriesArr, stepSec])

  const h = data?.health
  const health = h?.checks
  // Gauges mean something, so they take semantic colours rather than the next
  // categorical one: warn while there is headroom, bad only once it is gone.
  const SERIES = useSeriesColors()
  const gaugeColor = (pct: number) => (pct >= 90 ? SERIES.bad : pct >= 75 ? SERIES.warn : SERIES.good)
  const gauges = [
    { key: 'disk', label: 'Disk', value: health?.disk_space?.used_percent ?? 0 },
    { key: 'mem', label: 'Memory', value: health?.memory?.used_percent ?? 0 },
    { key: 'mongo', label: 'MongoDB', value: health?.mongodb?.healthy === false ? 0 : health?.mongodb?.latency_ms != null ? Math.min(100, Math.max(10, 100 - health.mongodb.latency_ms / 10)) : 0 },
  ].map(g => ({ ...g, color: g.key === 'mongo' ? SERIES.good : gaugeColor(g.value) }))
  const circuits = Object.entries(health?.rate_limiter?.limiters ?? {})
  const extApis = Object.entries(health?.external_apis?.endpoints ?? {})

  // Funnel over the pipeline itself: raw source collections in, combiner outputs
  // out. Internal `_`-prefixed collections (registry/counters/migrations) are
  // storage bookkeeping, so they are excluded rather than inflating "sources".
  const ingested = SOURCE_COLLECTIONS.reduce((a, k) => a + (counts[k] ?? 0), 0)
  const animeDocs = counts.organized ?? 0
  const mangaDocs = counts.organized_manga ?? 0
  const merged = animeDocs + mangaDocs
  const lastCombine = data?.combine?.strategy
    ? { strategy: data.combine.strategy, ago: ago(data.combine.completed_at) }
    : null

  const retry = async (src: string) => {
    await postAction(`ingest-${src}`, 'retry')
    toast(`${src} retry scheduled`)
  }
  const setPause = async (src: string, paused: boolean) => {
    await postAction(`ingest-${src}`, paused ? 'pause' : 'resume')
    toast(`${src} ${paused ? 'paused' : 'resumed'}`)
  }

  const dataReady = !!data || !!error

  return (
    <div className="space-y-4">
      <PageHead
        title="Dashboard"
        desc="Live telemetry — one view for the whole pipeline."
        actions={
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <Timer className="h-3.5 w-3.5" />
            {fmtSec((Date.now() - startedAt.current) / 1000)} uptime · {realtime ? 'live' : `poll ${Math.round(intervalMs / 1000)}s`}
            <StatusDot ok={!error} pulse /> {error ? 'offline' : (data?.health?.status ?? '…')}
            <SelectWindow win={win} setWin={setWin} />
          </div>
        }
      />

      {error && !data && (
        <div
          role="status"
          aria-live="polite"
          className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-500"
        >
          <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
          <span>Backend offline — reconnecting…</span>
        </div>
      )}

      {!dataReady && (
        <Skeleton className="h-[4.5rem] w-full rounded-lg" />
      )}

      {data && (
        <>
          {visible('kpi') && (
            <StatStrip
              items={[
                { label: 'Total docs', value: cx(totalDocs), icon: Database, sub: `${cx(activeJobs.length)} active` },
                { label: 'Ingest rate', value: `${activeRate.toFixed(1)}/s`, icon: Gauge, sub: 'entries/sec' },
                {
                  label: 'Errors',
                  value: cx(liveErrors),
                  icon: Hash,
                  // Tone follows the number: a clean live run should not render
                  // in the same ink as a run that is failing.
                  tone: liveErrors > 0 ? 'warn' : 'ok',
                  sub: activeJobs.length ? `on ${activeJobs.length} live` : 'no live source',
                },
              ]}
            />
          )}

          {visible('pipeline') && (
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-border bg-muted/20 px-3 py-1.5 text-xs text-muted-foreground">
              <span>sources</span>
              <span className="font-semibold tabular-nums text-foreground">{cx(ingested)}</span>
              <span className="text-muted-foreground/60">→</span>
              <span>merged</span>
              <span className="font-semibold tabular-nums text-foreground">{cx(merged)}</span>
              <span className="text-muted-foreground">
                ({cx(animeDocs)} anime · {cx(mangaDocs)} manga)
              </span>
              {liveSkipped > 0 && (
                <span className="text-muted-foreground">
                  · {cx(liveSkipped)} skipped (already stored)
                </span>
              )}
              {lastCombine && (
                <span className="ml-auto min-w-0 truncate">
                  · last combine <span className="font-medium text-foreground">{lastCombine.strategy}</span> {lastCombine.ago}
                </span>
              )}
            </div>
          )}

          {visible('fleet') && (
            <Section title="Sources" icon={Layers} badge={<Badge variant="outline">{jobKeys.length} tracked</Badge>}>
              {jobKeys.length === 0 ? (
                <EmptyState icon={Activity} title="No ingestion jobs" description="Start a source (Management → Sources) to see live progress, speed and ETA." />
              ) : (
                <SourcesTable
                  jobs={jobs}
                  health={data?.health}
                  focus={focus}
                  onToggleFocus={src => setFocus(focus === src ? null : src)}
                  onPause={src => void setPause(src, true)}
                  onResume={src => void setPause(src, false)}
                  onRetry={src => void retry(src)}
                />
              )}
            </Section>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {visible('rates') && <SeriesChartCard title="Ingestion rate" series={rateSeriesArr} data={ratesRows} emptyMsg="start an ingestion to see rates" />}
            {visible('counts') && <CompositionStream title="Collection share" data={countsRows} keys={countSeriesArr.map(s => s.key)} colors={countSeriesArr.map(s => s.color)} emptyMsg="no collection history yet" />}
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {visible('dataset') && (
              <Card>
                <CardHeader><CardTitle as="h2" className="text-sm uppercase tracking-wide text-muted-foreground">Dataset</CardTitle></CardHeader>
                <CardContent>
                  {dataset.length === 0 ? (
                    <EmptyState icon={Database} title="No collections" description="Ingest a source to build the dataset." />
                  ) : (
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                      <div className="h-40 w-full shrink-0 sm:w-40">
                        <ResponsiveContainer width="100%" height="100%">
                          <PieChart>
                            <Pie data={datasetTop} dataKey="value" nameKey="name" innerRadius={50} outerRadius={70} paddingAngle={2} strokeWidth={0} isAnimationActive={false}>
                              {datasetTop.map(e => <Cell key={e.name} fill={e.color} />)}
                            </Pie>
                            <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                          </PieChart>
                        </ResponsiveContainer>
                      </div>
                      <ScrollArea className="max-h-40 min-w-0 flex-1">
                        <div className="space-y-1 text-sm">
                          {datasetTop.map(e => (
                            <div key={e.name} className="flex items-center justify-between gap-2">
                              <span className="flex min-w-0 items-center gap-2 text-muted-foreground">
                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: e.color }} />
                                <span className="truncate">{e.name}</span>
                              </span>
                              <span className="tabular-nums">{cx(e.value)}</span>
                            </div>
                          ))}
                        </div>
                      </ScrollArea>
                    </div>
                  )}
                </CardContent>
              </Card>
            )}

            {visible('health') && (
              <HealthCard gauges={gauges} extApis={extApis} circuits={circuits} />
            )}
          </div>

          {!visible('rates') && !visible('counts') && !visible('dataset') && !visible('health') && !visible('pipeline') && !visible('fleet') && !visible('kpi') && (
            <EmptyState icon={Layers} title="Everything hidden" description="Re-enable widgets in Settings → Widgets." />
          )}
        </>
      )}
    </div>
  )
}

function SelectWindow({ win, setWin }: { win: number; setWin: (n: number) => void }) {
  return (
    <label className="flex items-center gap-1.5">
      <span className="hidden sm:inline">window</span>
      <select value={win} onChange={e => setWin(Number(e.target.value))} aria-label="Chart window" className="h-7 rounded-md border bg-transparent px-1 text-xs">
        {WINDOWS.map(w => <option key={w.value} value={w.value}>{w.label}</option>)}
      </select>
    </label>
  )
}

interface SourceRow {
  key: string
  job: Job
  src: string
  status: SourceStatus
  pct: number
  pending: number
}

/** Source / Phase / Progress / Speed·ETA / Actions — pending + done~ live in the row tooltip. */
function SourcesTable({
  jobs, health, focus, onToggleFocus, onPause, onResume, onRetry,
}: {
  jobs: Record<string, Job>
  health: HealthCheck | undefined
  focus: string | null
  onToggleFocus: (src: string) => void
  onPause: (src: string) => void
  onResume: (src: string) => void
  onRetry: (src: string) => void
}) {
  const rows = useMemo<SourceRow[]>(
    () =>
      Object.keys(jobs)
        .filter(k => isIngestJob(k, jobs[k] as Job))
        .sort()
        .map(k => {
          const job = jobs[k] as Job
          const src = String(job.source ?? k)
          const total = job.total ?? 0
          const processed = job.processed ?? 0
          const pct = total ? Math.min(100, (processed / total) * 100) : 0
          const pending = Math.max(0, total - processed)
          return { key: k, job, src, status: sourceStatus(health, src), pct, pending }
        }),
    [jobs, health],
  )

  const columns = useMemo<ColumnDef<DataTableFeatures, SourceRow, any>[]>(
    () => [
      {
        id: 'source',
        header: 'Source',
        accessorFn: r => r.src,
        sortingFn: 'alphanumeric',
        // Capped so the table fits a narrow viewport: the name truncates with a
        // title tooltip instead of stretching and shoving the pinned Actions
        // column over Progress.
        size: 260,
        minSize: 180,
        meta: { align: 'left' } as const,
        cell: ({ row }) => {
          const { src, status } = row.original
          const isFocused = focus === src
          const tip = rowTooltip(row.original)
          return (
            <span className={cn('flex min-w-0 items-center gap-2 rounded-md', isFocused && 'bg-primary/10')} title={tip}>
              {status.level !== 'none' && <SourceStatusBlip status={status} />}
              <CircleDot className={cn('h-3.5 w-3.5 shrink-0', isFocused ? 'text-primary' : 'text-muted-foreground')} />
              <span className={cn('truncate font-medium', isFocused && 'text-primary')}>{src}</span>
              <SourceStatusLabel status={status} />
              <span className="truncate text-xs text-muted-foreground">{row.original.job.mode ?? ''}</span>
            </span>
          )
        },
      },
      {
        id: 'phase',
        header: 'Phase',
        accessorFn: r => jobPhase(r.job),
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: ({ row }) => {
          const phase = jobPhase(row.original.job)
          return (
            <Badge variant={phase === 'done' ? 'secondary' : phase === 'stale' ? 'ghost' : 'outline'}>
              {phase}
            </Badge>
          )
        },
      },
      {
        id: 'progress',
        header: 'Progress',
        accessorFn: r => r.pct,
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: ({ row }) => {
          const { job, pct } = row.original
          return (
            <span className="flex min-w-0 items-center gap-2">
              <span className="h-1.5 w-14 shrink-0 overflow-hidden rounded-full bg-muted">
                <span className="block h-full bg-primary transition-all" style={{ width: `${pct.toFixed(1)}%` }} />
              </span>
              <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">
                {pct.toFixed(0)}% · {cx(job.processed)}/{job.total ? cx(job.total) : '?'}
              </span>
            </span>
          )
        },
      },
      {
        id: 'speed',
        header: 'Speed·ETA',
        accessorFn: r => r.job.per_second ?? 0,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => {
          const job = row.original.job
          // A stale or finished run's rate/ETA are frozen at their last value;
          // show nothing rather than a speed the job is no longer achieving.
          if (!isJobLive(job)) {
            return <span className="text-xs text-muted-foreground">—</span>
          }
          const speed = job.per_second != null ? `${job.per_second.toFixed(1)}/s` : '—'
          const eta = job.eta_seconds != null && Number.isFinite(job.eta_seconds) ? `${fmtSec(job.eta_seconds)} left` : null
          return (
            <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">
              <ChangePulse value={speed} />{eta ? ` · ${eta}` : ''}
            </span>
          )
        },
      },
      {
        id: 'actions',
        header: 'Actions',
        enableSorting: false,
        meta: { align: 'right', stickyRight: true } as const,
        cell: ({ row }) => {
          const { src, job } = row.original
          // Only offer the action that applies to this job's phase. Rendering
          // Pause/Resume/Retry on every row let you pause a job that already
          // finished and resume one that never stopped — both no-ops that read
          // as broken controls.
          const phase = jobPhase(job)
          const live = isJobLive(job)
          return (
            <span className="flex items-center justify-end gap-1">
              {live ? (
                <>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    title={`Pause ${src}`}
                    aria-label={`Pause ingestion of ${src}`}
                    onClick={() => onPause(src)}
                  >
                    <Pause className="h-3.5 w-3.5" />
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    title={`Retry ${src}`}
                    aria-label={`Retry ingestion of ${src}`}
                    onClick={() => onRetry(src)}
                  >
                    <RotateCcw className="h-3.5 w-3.5" />
                  </Button>
                </>
              ) : (
                <>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    title={`Resume ${src}`}
                    aria-label={`Resume ingestion of ${src}`}
                    onClick={() => onResume(src)}
                  >
                    <Play className="h-3.5 w-3.5" />
                  </Button>
                  {phase === 'done' && (
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      title={`Retry ${src}`}
                      aria-label={`Retry ingestion of ${src}`}
                      onClick={() => onRetry(src)}
                    >
                      <RotateCcw className="h-3.5 w-3.5" />
                    </Button>
                  )}
                </>
              )}
            </span>
          )
        },
      },
    ],
    [focus, onPause, onResume, onRetry],
  )

  return (
    <DataTable<SourceRow>
      columns={columns}
      data={rows}
      getRowId={r => r.key}
      onRowClick={row => onToggleFocus(row.src)}
      initialSorting={[{ id: 'source', desc: false }]}
      initialPageSize={8}
      pageSizeOptions={[8, 10, 25, 50]}
      sortDescFirst
      maxHeight="22rem"
      ariaLabel="Ingestion sources"
      footerLeft={`${rows.length} sources`}
    />
  )
}

/** Pending + estimated completion for the row tooltip (was the Pending / Done~ columns). */
function rowTooltip(r: SourceRow): string {
  const parts = [`pending ${cx(r.pending)}`]
  const eta = r.job.eta_seconds
  if (eta != null && Number.isFinite(eta)) {
    const doneAt = new Date(Date.now() + eta * 1000)
    parts.push(`done ~ ${doneAt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`)
  }
  return parts.join(' · ')
}

function HealthCard({ gauges, extApis, circuits }: {
  gauges: { key: string; label: string; value: number; color: string }[]
  extApis: Array<[string, { healthy?: boolean; latency_ms?: number }]>
  circuits: Array<[string, { circuit_state?: string }]>
}) {
  const [open, setOpen] = useState(false)
  return (
    <Card>
      <CardHeader><CardTitle as="h2" className="text-sm uppercase tracking-wide text-muted-foreground">Health</CardTitle></CardHeader>
      <CardContent className="p-4">
        <div className="flex items-center justify-around">
          {gauges.map(g => (
            <div key={g.key} className="flex flex-col items-center">
              <div className="relative h-20 w-20">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={[{ value: g.value }]} dataKey="value" startAngle={225} endAngle={-45} innerRadius="70%" outerRadius="100%" cornerRadius={20} strokeWidth={0} isAnimationActive={false}>
                      <Cell fill={g.color} />
                    </Pie>
                  </PieChart>
                </ResponsiveContainer>
                <div className="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums">{Math.round(g.value)}%</div>
              </div>
              <span className="mt-1 text-xs text-muted-foreground">{g.label}</span>
            </div>
          ))}
        </div>
        <div className="mt-3 border-t pt-2">
          <button
            type="button"
            onClick={() => setOpen(o => !o)}
            aria-expanded={open}
            className="flex w-full items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground hover:text-foreground"
          >
            <ChevronDown className={cn('h-3.5 w-3.5 transition-transform', open && 'rotate-180')} />
            Endpoints & circuits
            <span className="ml-auto tabular-nums">{extApis.length + circuits.length}</span>
          </button>
          {open && (
            <ScrollArea className="mt-2 max-h-40">
              <div className="space-y-1 text-xs">
                {extApis.length === 0 && circuits.length === 0 ? (
                  <p className="text-muted-foreground">no external endpoints or circuits</p>
                ) : (
                  <>
                    {extApis.map(([name, e]) => (
                      <div key={name} className="flex items-center gap-2">
                        <StatusDot ok={e.healthy !== false} />
                        <span className="flex-1 truncate text-muted-foreground">{name}</span>
                        <span className="tabular-nums">{e.latency_ms != null ? `${e.latency_ms}ms` : '—'}</span>
                      </div>
                    ))}
                    {circuits.length > 0 && (
                      <div className="border-t pt-1 text-[10px] text-muted-foreground">
                        circuits: {circuits.map(([src, s]) => `${src}:${s.circuit_state === 'open' ? 'OPEN' : 'closed'}`).join(' · ')}
                      </div>
                    )}
                  </>
                )}
              </div>
            </ScrollArea>
          )}
        </div>
      </CardContent>
    </Card>
  )
}