import { useMemo, useRef, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  Activity, ChevronDown, Database, Gauge, GitMerge, Hash, Layers, Loader2,
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
import { Sparkline, useChartColors } from '../charts'
import { isIngestJob, isJobLive, jobPhase, postAction, type BackupInfo, type CombineStats, type HealthCheck, type Job } from '@/lib/api'
import { ChangePulse, PageHead, Section, StatusDot, StatStrip } from '../widgets'
import { SourceLogo, SourceStatusBlip, SourceStatusLabel, rowStatus, type SourceStatus } from '../source-status'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'


/** Collections written by the ingesters — the input side of the pipeline. */
const SOURCE_COLLECTIONS = ['anilist', 'anilist_manga', 'myanimelist', 'myanimelist_manga', 'kitsu', 'kitsu_manga']

/**
 * Which pipeline source owns a collection, and whether it is the anime or the
 * manga side. Anything not listed here is infrastructure and folds into the
 * "system" group.
 */
type DatasetSeg = { name: string; value: number; kind: 'anime' | 'manga' | 'other'; color: string }
type DatasetGroup = { name: string; value: number; anime: number; manga: number; other: number; color: string }

const SOURCE_GROUPS: Record<string, { group: string; kind: 'anime' | 'manga' }> = {
  anilist: { group: 'AniList', kind: 'anime' },
  anilist_manga: { group: 'AniList', kind: 'manga' },
  kitsu: { group: 'Kitsu', kind: 'anime' },
  kitsu_manga: { group: 'Kitsu', kind: 'manga' },
  myanimelist: { group: 'MyAnimeList', kind: 'anime' },
  myanimelist_manga: { group: 'MyAnimeList', kind: 'manga' },
  organized: { group: 'Merged', kind: 'anime' },
  organized_manga: { group: 'Merged', kind: 'manga' },
}

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
  const startedAt = useRef(Date.now())


  const counts = data?.counts ?? {}
  const jobs = data?.jobs ?? {}
  const jobKeys = Object.keys(jobs).filter(k => isIngestJob(k, jobs[k] as Job)).sort()
  const activeJobs = jobKeys.filter(k => isJobLive(jobs[k] as Job))

  // Combined throughput across live sources, summed sample-for-sample. Each job
  // ships its own `history`; adding them pointwise gives one pipeline-wide
  // trend for the summary row.
  const combinedRate = useMemo(() => {
    let len = 0
    const series: number[][] = []
    for (const k of activeJobs) {
      const h = (jobs[k] as Job).history ?? []
      if (h.length) { series.push(h.map(p => p.v).filter(v => Number.isFinite(v))); len = Math.max(len, series[series.length-1].length) }
    }
    if (!len) return []
    const out: number[] = []
    for (let i = 0; i < len; i++) {
      out.push(series.reduce((sum, arr) => sum + (arr[arr.length - len + i] ?? 0), 0))
    }
    return out
  }, [jobs, activeJobs])
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
  /**
   * Dataset grouped the way the pipeline actually builds it.
   *
   * Every catalogue source ingests anime and manga into a pair of collections,
   * and the combiner writes a matching pair. Listing all 15 collections
   * individually was noise — it made the card a 15-row scroll area of mostly
   * zeros (`_migrations` 4, `embeddings` 3, `pair_rejections` 0) sitting beside
   * the entries that matter, and it hid the one shape worth seeing: how big
   * each source is, and how much of it is manga.
   *
   * So the donut is two rings. Outer = the source group, inner = that group's
   * anime/manga split, which lines up because Recharts lays slices out by value
   * in array order and the inner array is flattened in the outer array's order.
   * Anything that is not a catalogue pair — ids, labels, caches, counters —
   * folds into a single "system" group so it stops competing for attention.
   */
  const dataset = useMemo(() => {
    const keys = Object.keys(counts).filter(k => !hiddenCollections.includes(k) && counts[k] > 0)
    const byGroup = new Map<string, { total: number; anime: number; manga: number; other: number }>()
    for (const k of keys) {
      const spec = SOURCE_GROUPS[k]
      const group = spec?.group ?? 'System'
      const cur = byGroup.get(group) ?? { total: 0, anime: 0, manga: 0, other: 0 }
      cur.total += counts[k]
      if (spec?.kind === 'anime') cur.anime += counts[k]
      else if (spec?.kind === 'manga') cur.manga += counts[k]
      else cur.other += counts[k]
      byGroup.set(group, cur)
    }

    const outer = [...byGroup.entries()]
      .map(([name, v]) => ({ name, value: v.total, anime: v.anime, manga: v.manga, other: v.other }))
      .filter(g => g.value > 0)
      .sort((a, b) => b.value - a.value)

    const colorOf = new Map(outer.map((g, i) => [g.name, COLORS[i % COLORS.length]]))

    // Flattened in the outer array's order so the inner ring's angles land
    // exactly under their parent slice.
    const inner = outer.flatMap(g => {
      const color = colorOf.get(g.name) ?? COLORS[0]
      const segs: DatasetSeg[] = []
      if (g.anime > 0) segs.push({ name: g.name, value: g.anime, kind: 'anime', color })
      if (g.manga > 0) segs.push({ name: g.name, value: g.manga, kind: 'manga', color })
      if (g.other > 0) segs.push({ name: g.name, value: g.other, kind: 'other', color: COLORS[COLORS.length - 1] })
      return segs
    })

    const outerWithColor: DatasetGroup[] = outer.map(g => ({ ...g, color: colorOf.get(g.name) ?? COLORS[0] }))
    return { outer: outerWithColor, inner }
  }, [counts, hiddenCollections, COLORS])

  const h = data?.health
  const health = h?.checks
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
                {
                  label: 'Total docs',
                  value: cx(totalDocs),
                  icon: Database,
                  // The anime/manga split is the shape of this dataset; the
                  // active-source count belonged to the rate tile.
                  sub: `${cx(animeDocs)} anime · ${cx(mangaDocs)} manga`,
                },
                {
                  label: 'Ingest rate',
                  value: `${activeRate.toFixed(1)}/s`,
                  icon: Gauge,
                  // A trend answers "is it moving", which a bare number cannot.
                  // Combined across live sources, which is the question the
                  // per-row sparklines cannot answer on their own.
                  spark: combinedRate,
                  sub: activeJobs.length
                    ? `across ${activeJobs.length} live source${activeJobs.length === 1 ? '' : 's'}`
                    : 'no live source',
                },
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
            <Section title="Sources" icon={Layers} badge={<Badge variant="outline">{jobKeys.length}</Badge>}>
              {jobKeys.length === 0 ? (
                <EmptyState icon={Activity} title="No ingestion jobs" description="Start a source (Management → Sources) to see live progress, speed and ETA." />
              ) : (
                <SourcesTable
                  jobs={jobs}
                  health={data?.health}
                  onPause={src => void setPause(src, true)}
                  onResume={src => void setPause(src, false)}
                  onRetry={src => void retry(src)}
                />
              )}
            </Section>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {visible('dataset') && (
              <Card>
                <CardHeader><CardTitle as="h2" className="text-sm uppercase tracking-wide text-muted-foreground">Dataset</CardTitle></CardHeader>
                <CardContent>
                  {dataset.outer.length === 0 ? (
                    <EmptyState icon={Database} title="No collections" description="Ingest a source to build the dataset." />
                  ) : (
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                      <div className="h-40 w-full shrink-0 sm:w-40">
                        <ResponsiveContainer width="100%" height="100%">
                          <PieChart>
                            {/* Outer ring: one slice per pipeline source. */}
                            <Pie data={dataset.outer} dataKey="value" nameKey="name" innerRadius="46%" outerRadius="98%" paddingAngle={1} strokeWidth={0} isAnimationActive={false}>
                              {dataset.outer.map(e => <Cell key={e.name} fill={e.color} />)}
                            </Pie>
                            {/* Inner ring: that source's anime/manga split. Manga
                                is drawn at reduced opacity so the pair reads as
                                one source first and two collections second. */}
                            <Pie data={dataset.inner} dataKey="value" nameKey="kind" innerRadius="20%" outerRadius="44%" paddingAngle={1} strokeWidth={0} isAnimationActive={false}>
                              {dataset.inner.map((e, i) => (
                                <Cell key={`${e.name}-${e.kind}-${i}`} fill={e.color} fillOpacity={e.kind === 'manga' ? 0.45 : 1} />
                              ))}
                            </Pie>
                            <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                          </PieChart>
                        </ResponsiveContainer>
                      </div>
                      <ScrollArea className="max-h-40 min-w-0 flex-1">
                        <div className="space-y-2 text-sm">
                          {dataset.outer.map(e => (
                            <div key={e.name} className="flex items-center justify-between gap-2">
                              <span className="flex min-w-0 items-center gap-2">
                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: e.color }} />
                                <span className="truncate font-medium">{e.name}</span>
                                {/* The split, inline, so the inner ring's two
                                    segments are readable as numbers too. */}
                                {(e.anime > 0 || e.manga > 0) && (
                                  <span className="truncate text-xs text-muted-foreground">
                                    {e.anime > 0 && `${cx(e.anime)} anime`}
                                    {e.anime > 0 && e.manga > 0 && ' · '}
                                    {e.manga > 0 && `${cx(e.manga)} manga`}
                                  </span>
                                )}
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
              <MergeCard combine={data?.combine} backup={data?.backup} extApis={extApis} circuits={circuits} />
            )}
          </div>

          {!visible('dataset') && !visible('health') && !visible('pipeline') && !visible('fleet') && !visible('kpi') && (
            <EmptyState icon={Layers} title="Everything hidden" description="Re-enable widgets in Settings → Widgets." />
          )}
        </>
      )}
    </div>
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
  jobs, health, onPause, onResume, onRetry,
}: {
  jobs: Record<string, Job>
  health: HealthCheck | undefined
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
          return { key: k, job, src, status: rowStatus(job, health, src), pct, pending }
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
          const tip = rowTooltip(row.original)
          return (
            <span className="flex min-w-0 items-center gap-2 rounded-md" title={tip}>
              {status.level !== 'none' && <SourceStatusBlip status={status} />}
              <SourceLogo source={src} />
              <span className="truncate font-medium">{src}</span>
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
          const { job, status } = row.original
          const history = (job.history ?? []).map(h => h.v).filter(v => Number.isFinite(v)) as number[]
          // A stale or finished run's rate is frozen at its last value, so the
          // numeric speed is suppressed — but the trend stays, dimmed, because
          // "how this source behaved" is still useful after it stops.
          const live = isJobLive(job)
          const tint = status.level === 'crit' ? 'var(--chart-5)'
            : status.level === 'warn' ? 'var(--chart-3)'
            : status.level === 'done' ? 'var(--chart-8)'
            : 'var(--chart-1)'
          return (
            <span className="flex items-center justify-end gap-2">
              <Sparkline
                points={history}
                color={tint}
                width={56}
                height={18}
                label={`${row.original.src} recent ingestion rate`}
              />
              <span className={cn(
                'whitespace-nowrap text-xs tabular-nums',
                live ? 'text-foreground' : 'text-muted-foreground',
              )}>
                {live
                  ? <ChangePulse value={job.per_second != null ? `${job.per_second.toFixed(1)}/s` : '—'} />
                  : <span className="text-muted-foreground">—</span>}
              </span>
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
    [onPause, onResume, onRetry],
  )

  return (
    <DataTable<SourceRow>
      columns={columns}
      data={rows}
      getRowId={r => r.key}
      initialSorting={[{ id: 'source', desc: false }]}
      sortDescFirst
      maxHeight="22rem"
      ariaLabel="Ingestion sources"
      showPagination={false}
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

/**
 * Merge confidence.
 *
 * This card used to be "Health": three gauges for disk, host memory and a
 * derived MongoDB score. None of that described the application — disk and RSS
 * belong to whatever box the CLI runs on, and the MongoDB score was an
 * invented number that read as precise without being meaningful. An operator
 * of a merge tool does not care about host memory; they care whether the last
 * combine was good and whether the output is recoverable.
 *
 * So it now reports the thing the tool exists to produce: how many pairs the
 * last run merged, under which strategy, and how confident those matches were.
 * The score histogram is the useful part — an average hides the problem, since
 * a run can average 96 while a fifth of its merges sit near the floor.
 */
function MergeCard({ combine, backup, extApis, circuits }: {
  combine: CombineStats | undefined
  backup: BackupInfo | undefined
  extApis: Array<[string, { healthy?: boolean; latency_ms?: number }]>
  circuits: Array<[string, { circuit_state?: string }]>
}) {
  const [open, setOpen] = useState(false)
  if (!combine) {
    return (
      <Card>
        <CardHeader><CardTitle as="h2" className="text-sm uppercase tracking-wide text-muted-foreground">Merge</CardTitle></CardHeader>
        <CardContent className="p-4">
          <EmptyState icon={GitMerge} title="Never combined" description="Run a combine strategy to see merge confidence here." />
        </CardContent>
      </Card>
    )
  }

  const bins = [
    { key: 'p70_80', label: '70–80', color: 'var(--chart-3)' },
    { key: 'p80_90', label: '80–90', color: 'var(--chart-1)' },
    { key: 'p90_100', label: '90–100', color: 'var(--chart-6)' },
  ] as const
  const counts = bins.map(b => Number(combine.score_histogram?.[b.key] ?? 0))
  const total = counts.reduce((a, b) => a + b, 0)

  return (
    <Card>
      <CardHeader><CardTitle as="h2" className="text-sm uppercase tracking-wide text-muted-foreground">Merge</CardTitle></CardHeader>
      <CardContent className="p-4">
        <div className="flex items-baseline gap-2">
          <span className="text-2xl font-medium tabular-nums">{cx(combine.merges)}</span>
          <span className="text-xs text-muted-foreground">
            pairs · {combine.strategy} · base {combine.base}
          </span>
        </div>

        {total > 0 && (
          <div className="mt-3">
            <div className="flex h-2 w-full overflow-hidden rounded-sm" role="img"
                 aria-label={`Match confidence: ${bins.map((b, i) => `${b.label} ${counts[i]}`).join(', ')}`}>
              {bins.map((b, i) => counts[i] > 0 && (
                <span key={b.key} style={{ width: `${(counts[i] / total) * 100}%`, background: b.color }} />
              ))}
            </div>
            <div className="mt-1.5 flex justify-between text-[10px] text-muted-foreground">
              {bins.map((b, i) => (
                <span key={b.key} className="flex items-center gap-1">
                  <span className="h-1.5 w-1.5 rounded-sm" style={{ background: b.color }} />
                  {b.label} {cx(counts[i])}
                </span>
              ))}
            </div>
          </div>
        )}

        <dl className="mt-3 space-y-1 text-xs">
          <div className="flex justify-between gap-2">
            <dt className="text-muted-foreground">mean score</dt>
            <dd className="tabular-nums">{combine.scores?.avg ?? '—'}</dd>
          </div>
          <div className="flex justify-between gap-2">
            <dt className="text-muted-foreground">accept floor</dt>
            <dd className="tabular-nums">{combine.threshold ?? '—'}</dd>
          </div>
          <div className="flex justify-between gap-2">
            <dt className="text-muted-foreground">last run</dt>
            <dd>{ago(combine.completed_at)}</dd>
          </div>
          {backup?.last?.mtime != null && (
            <div className="flex justify-between gap-2">
              <dt className="text-muted-foreground">last backup</dt>
              <dd>{ago(new Date(backup.last.mtime * 1000).toISOString())}</dd>
            </div>
          )}
        </dl>

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
            <ScrollArea className="mt-2 max-h-32">
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
