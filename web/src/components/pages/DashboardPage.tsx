import { useEffect, useMemo, useRef, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  Activity, Database, Gauge, GitMerge, Hash, Layers, Loader2,
  Play, Pause, RotateCcw, Timer,
} from 'lucide-react'
import { Cell, Pie, PieChart, PolarAngleAxis, PolarGrid, PolarRadiusAxis, Radar, RadarChart, ResponsiveContainer, Tooltip } from 'recharts'
import { cn } from 'cn'
import { ago, fmt, fmtSec } from '@/lib/format'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Tooltip as Tip, TooltipContent as TipContent, TooltipProvider as TipProvider, TooltipTrigger as TipTrigger } from '@/components/ui/tooltip'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { Sparkline, useChartColors } from '../charts'
import { isIngestJob, isJobLive, jobPhase, postAction, type BackupInfo, type CombineStats, type HealthCheck, type Job } from '@/lib/api'
import { ChangePulse, PageHead, PanelHead, Section, StatusDot, StatStrip } from '../widgets'
import { SourceLogo, SourceStatusBlip, SourceStatusLabel, rowStatus, type SourceStatus } from '../source-status'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'


/** Collections written by the ingesters — the input side of the pipeline. */

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
  const liveErrors = useMemo(() => activeJobs.reduce((a, k) => a + ((jobs[k] as Job).errors ?? 0), 0), [jobs, activeJobs])
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

  // Funnel over the pipeline itself: raw source collections in, combiner outputs
  // out. Internal `_`-prefixed collections (registry/counters/migrations) are
  // storage bookkeeping, so they are excluded rather than inflating "sources".
  const animeDocs = counts.organized ?? 0
  const mangaDocs = counts.organized_manga ?? 0

  // Retry restarts a source from the beginning. It was unconfirmed here while
  // Management confirmed the same command, so the one place it was easiest to
  // hit was the one place it asked nothing.
  const [confirmRetry, setConfirmRetry] = useState<string | null>(null)

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
      <Dialog open={confirmRetry !== null} onOpenChange={o => { if (!o) setConfirmRetry(null) }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Full re-ingest of {confirmRetry}?</DialogTitle>
            <DialogDescription>
              Restarts {confirmRetry} from the beginning. Documents already stored are skipped, so this
              re-walks the source rather than duplicating it — expect it to take as long as the first run.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setConfirmRetry(null)}>Cancel</Button>
            <Button
              size="sm"
              variant="destructive"
              onClick={() => { const src = confirmRetry; setConfirmRetry(null); if (src) void retry(src) }}
            >
              <RotateCcw className="mr-1.5 h-3.5 w-3.5" />Re-ingest
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <PageHead
        title="Dashboard"
        desc="Live telemetry — one view for the whole pipeline."
        actions={
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <Timer className="h-3.5 w-3.5" />
            {fmtSec((Date.now() - startedAt.current) / 1000)} session · {realtime ? 'live' : `poll ${Math.round(intervalMs / 1000)}s`}
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


          {visible('fleet') && (
            <Section title="Sources" icon={Layers} badge={<Badge variant="outline">{jobKeys.length}</Badge>} collapsible={false}>
              {jobKeys.length === 0 ? (
                <EmptyState icon={Activity} title="No ingestion jobs" description="Start a source (Management → Sources) to see live progress, speed and ETA." />
              ) : (
                <SourcesTable
                  jobs={jobs}
                  health={data?.health}
                  controls={data?.controls ?? {}}
                  onPause={src => setPause(src, true)}
                  onResume={src => setPause(src, false)}
                  onRetry={src => setConfirmRetry(src)}
                />
              )}
            </Section>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {visible('dataset') && (
              <Card>
                <PanelHead title="Dataset" icon={Database} />
                <CardContent className="flex min-h-0 flex-1 flex-col">
                  {dataset.outer.length === 0 ? (
                    <EmptyState icon={Database} title="No collections" description="Ingest a source to build the dataset." />
                  ) : (
                    <div className="flex min-h-0 flex-1 flex-col gap-4 sm:flex-row sm:items-stretch">
                      <div className="h-40 w-full shrink-0 sm:h-auto sm:min-h-40 sm:w-40 sm:flex-1 sm:max-w-44">
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
                      <ScrollArea className="min-w-0 flex-1 self-center sm:max-h-56">
                        <div className="space-y-1.5 text-sm">
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
              <MergeCard combine={data?.combine} backup={data?.backup} coverage={data?.coverage ?? {}} />
            )}
          </div>

          {!visible('dataset') && !visible('fleet') && !visible('kpi') && !visible('health') && (
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
  run: RunState
}

/**
 * What is actually true of this run, reconciling what the worker writes with
 * what the numbers say.
 *
 * The table used to show Phase and Progress as independent columns, which
 * contradicted each other in real data: `myanimelist` sits at phase `done` with
 * 0% progress (total is unknown, so progress is meaningless), and `anilist`
 * sits at phase `running` with 100% progress (it finished but never wrote a
 * terminal phase). `myanimelist_manga` is genuinely live — the process exists
 * and holds the state file — at 0.03 docs/sec, so it read as "running" for
 * hours while doing nothing.
 *
 * This returns one answer to "what is this source doing", which is what the
 * operator is actually asking.
 */
/** Sort order for the Status column: what needs attention first. */
const RANK: Record<RunState['kind'], number> = {
  running: 0,
  stalled: 1,
  stale: 1,
  paused: 2,
  idle: 3,
  done: 4,
}

export type RunState =
  | { kind: 'done' }
  | { kind: 'running' }
  | { kind: 'stalled'; sinceSec: number }
  | { kind: 'stale'; agoSec: number }
  | { kind: 'paused' }
  | { kind: 'idle' }

/** Seconds since the worker last advanced `processed`, tracked across polls. */
export type ProgressClock = Record<string, { processed: number; since: number }>

/** Source / Phase / Progress / Speed·ETA / Actions — pending + done~ live in the row tooltip. */
function SourcesTable({
  jobs, health, controls, onPause, onResume, onRetry,
}: {
  jobs: Record<string, Job>
  health: HealthCheck | undefined
  controls: Record<string, { paused?: boolean }>
  onPause: (src: string) => Promise<void>
  onResume: (src: string) => Promise<void>
  onRetry: (src: string) => void
}) {
  // Which action is in flight for which source. A control that spawns a worker
  // and then looks unchanged for a poll cycle reads as broken, so the button
  // spins until the next run state arrives.
  const [pending, setPending] = useState<Record<string, string>>({})
  const fire = async (src: string, action: string, fn: () => Promise<void> | void) => {
    setPending(p => ({ ...p, [src]: action }))
    try { await fn() } finally {
      // The action spawns a detached worker; give the next poll a moment to
      // reflect it rather than clearing the spinner immediately.
      window.setTimeout(() => setPending(p => {
        const next = { ...p }; delete next[src]; return next
      }), 2500)
    }
  }
  // Track the last time each source actually advanced. A live phase alone
  // cannot distinguish "working" from "awake and doing nothing", and at
  // 0.03 docs/sec the difference matters.
  const progressClock = useRef<ProgressClock>({})
  useEffect(() => {
    const now = Date.now()
    const next: ProgressClock = {}
    for (const k of Object.keys(jobs)) {
      if (!isIngestJob(k, jobs[k] as Job)) continue
      const processed = (jobs[k] as Job).processed ?? 0
      const prev = progressClock.current[k]
      next[k] = prev && prev.processed === processed ? prev : { processed, since: now }
    }
    progressClock.current = next
  }, [jobs])

  const rows = useMemo<SourceRow[]>(() => {
    const now = Date.now()
    return Object.keys(jobs)
      .filter(k => isIngestJob(k, jobs[k] as Job))
      .sort()
      .map(k => {
        const job = jobs[k] as Job
        const src = String(job.source ?? k)
        const total = job.total ?? 0
        const processed = job.processed ?? 0
        const pct = total ? Math.min(100, (processed / total) * 100) : 0
        const pending = Math.max(0, total - processed)
        const status = rowStatus(job, health, src)
        const phase = jobPhase(job)
        const clock = progressClock.current[k]
        const idleSec = clock ? Math.round((now - clock.since) / 1000) : 0

        // Precedence matters: a paused worker is paused even though its phase
        // still reads `running`, and a run that reached its total is done even
        // if the worker never wrote `done`.
        let run: RunState
        if (controls[k]?.paused) run = { kind: 'paused' }
        else if (total > 0 && processed >= total) run = { kind: 'done' }
        else if (phase === 'done') run = { kind: 'done' }
        else if (phase === 'stale') {
          const upd = job.updated_at ? Date.parse(job.updated_at) : Number.NaN
          run = { kind: 'stale', agoSec: Number.isNaN(upd) ? 0 : Math.round((now - upd) / 1000) }
        } else if (phase === 'running') {
          // Two signals, because neither alone is enough. The client clock sees
          // an exact stall but starts over on every page load, so a reload made
          // a stuck job look healthy again for two minutes. The server's
          // per_second survives reloads but is a rolling average that lags. A
          // running job whose rate rounds to 0.0/s is not progressing either way.
          const rate = job.per_second ?? 0
          run = idleSec >= 120 || rate < 0.05
            ? { kind: 'stalled', sinceSec: idleSec }
            : { kind: 'running' }
        } else {
          run = { kind: 'idle' }
        }

        return { key: k, job, src, status, pct, pending, run }
      })
  }, [jobs, health, controls])

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
        // Phase and Progress used to be two columns that contradicted each
        // other. One column answers the question the table is asked: what is
        // this source doing, and how far along is it.
        id: 'run',
        header: 'Status',
        accessorFn: r => RANK[r.run.kind],
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: ({ row }) => {
          const { run, job, pct } = row.original
          const processed = job.processed ?? 0
          const total = job.total ?? 0
          const bar = total > 0 ? (
            <span className="h-1.5 w-14 shrink-0 overflow-hidden rounded-full bg-muted">
              <span className="block h-full bg-primary transition-all" style={{ width: `${pct.toFixed(1)}%` }} />
            </span>
          ) : null

          if (run.kind === 'running') {
            return (
              <span className="flex min-w-0 items-center gap-2">
                <span className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-emerald-500" />
                <span className="text-xs">running</span>
                {bar}
                <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">
                  {total > 0 ? `${Math.round(pct)}% · ` : ''}{cx(processed)}{total > 0 ? `/${cx(total)}` : ''}
                </span>
              </span>
            )
          }

          // Live worker, nothing moving. At 0.03 docs/sec a run can sit here for
          // hours while reading as healthy, which is exactly the case that made
          // "running" untrustworthy.
          if (run.kind === 'stalled') {
            return (
              <span className="flex min-w-0 items-center gap-2">
                <span className="h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                <span className="text-xs text-amber-600 dark:text-amber-400">no progress {fmtSec(run.sinceSec)}</span>
                <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">{cx(processed)} processed</span>
              </span>
            )
          }

          if (run.kind === 'paused') {
            return (
              <span className="flex min-w-0 items-center gap-2">
                <span className="h-2 w-2 shrink-0 rounded-full bg-muted-foreground" />
                <span className="text-xs">paused</span>
                {bar}
                <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">{cx(processed)}</span>
              </span>
            )
          }

          if (run.kind === 'stale') {
            return (
              <span className="flex min-w-0 items-center gap-2">
                <span className="h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                <span className="text-xs text-amber-600 dark:text-amber-400">stopped {fmtSec(run.agoSec)} ago</span>
                <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">{cx(processed)} processed</span>
              </span>
            )
          }

          if (run.kind === 'done') {
            return (
              <span className="flex min-w-0 items-center gap-2">
                <span className="h-2 w-2 shrink-0 rounded-full bg-sky-500" />
                <span className="text-xs text-muted-foreground">done</span>
                <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">{cx(processed)} records</span>
              </span>
            )
          }

          return (
            <span className="flex min-w-0 items-center gap-2">
              <span className="h-2 w-2 shrink-0 rounded-full bg-muted" />
              <span className="text-xs text-muted-foreground">not started</span>
            </span>
          )
        },
      },
      {
        id: 'speed',
        header: 'Speed',
        accessorFn: r => r.job.per_second ?? 0,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => {
          const { job, status } = row.original
          const history = (job.history ?? []).map(h => h.v).filter(v => Number.isFinite(v)) as number[]
          const live = isJobLive(job)
          // A stopped run has no rate worth reading, so it gets neither a trend
          // nor a number — a flat sparkline beside an em dash reads as broken
          // rather than idle.
          const tint = status.level === 'crit' ? 'var(--chart-5)'
            : status.level === 'warn' ? 'var(--chart-3)'
            : 'var(--chart-1)'
          return (
            <span className="flex items-center justify-end gap-2">
              {/* Fixed-width value column so the sparkline does not shift
                  between rows that show a rate and rows that show a dash. */}
              <span className={cn(
                'w-14 shrink-0 whitespace-nowrap text-right text-xs tabular-nums',
                live ? 'text-foreground' : 'text-muted-foreground',
              )}>
                {live && job.per_second != null
                  ? <ChangePulse value={`${job.per_second.toFixed(1)}/s`} />
                  : <span className="text-muted-foreground">—</span>}
              </span>
              {live && (
                <span className="flex w-14 shrink-0 justify-end">
                  <Sparkline
                    points={history}
                    color={tint}
                    width={56}
                    height={18}
                    label={`${row.original.src} recent ingestion rate`}
                  />
                </span>
              )}
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
          const { src, run } = row.original
          const kind = run.kind
          const isPending = !!pending[src]

          // Every action is always present. Hiding the ones that do not apply
          // made the control set change shape row to row, so you could not
          // learn where Pause lives — and a hidden button explains nothing,
          // whereas a disabled one with a reason teaches the state machine.
          const actions = [
            {
              key: 'pause',
              icon: Pause,
              label: `Pause ${src}`,
              enabled: kind === 'running' || kind === 'stalled',
              hint: kind === 'running' || kind === 'stalled'
                ? 'Pause after the record in flight. Resume continues from there.'
                : kind === 'paused'
                  ? 'Already paused.'
                  : 'Nothing is running, so there is nothing to pause.',
              run: () => fire(src, 'pause', () => onPause(src)),
            },
            {
              key: 'resume',
              icon: Play,
              label: `Resume ${src}`,
              enabled: kind === 'paused',
              hint: kind === 'paused'
                ? 'Continue this run from where it stopped.'
                : 'This source is not paused.',
              run: () => fire(src, 'resume', () => onResume(src)),
            },
            {
              key: 'retry',
              icon: RotateCcw,
              label: `Re-ingest ${src}`,
              enabled: kind !== 'running' && kind !== 'stalled' && kind !== 'paused',
              hint: kind === 'done'
                ? 'Re-walk the source from the beginning. Stored documents are skipped, so it costs a full pass.'
                : kind === 'running' || kind === 'stalled'
                  ? 'This is already running. Pause it first if you want to restart.'
                  : kind === 'paused'
                    ? 'Paused — resume instead of restarting.'
                    : 'Start ingesting this source from the beginning.',
              run: () => onRetry(src),
            },
          ]

          return (
            <span className="flex items-center justify-end gap-0.5">
              {actions.map(a => (
                <Tip key={a.key}>
                  <TipTrigger
                    render={
                      // The wrapper keeps hover alive on a disabled button;
                      // a disabled button swallows pointer events, which is why
                      // its title never appeared.
                      <span className="inline-flex">
                        <Button
                          size="icon-sm"
                          variant="ghost"
                          aria-label={a.label}
                          disabled={!a.enabled || isPending}
                          onClick={() => { if (a.enabled && !isPending) a.run() }}
                        >
                          {isPending && pending[src] === a.key
                            ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            : <a.icon className="h-3.5 w-3.5" />}
                        </Button>
                      </span>
                    }
                  />
                  <TipContent>{a.hint}</TipContent>
                </Tip>
              ))}
            </span>
          )
        },
      },
    ],
    // `pending` must be a dependency. The cell closures read it to render the
    // spinner, so without it here a click repainted nothing until the next data
    // poll happened to recreate these handlers — which is why the loader lagged
    // behind the button by a whole cycle.
    [onPause, onResume, onRetry, pending],
  )

  return (
    <TipProvider delay={250}>
    <DataTable<SourceRow>
      columns={columns}
      data={rows}
      getRowId={r => r.key}
      initialSorting={[{ id: 'run', desc: false }]}
      sortDescFirst
      maxHeight="22rem"
      ariaLabel="Ingestion sources"
      showPagination={false}
    />
      </TipProvider>
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
function MergeCard({ combine, backup, coverage }: {
  combine: CombineStats | undefined
  backup: BackupInfo | undefined
  coverage: Record<string, Record<string, number | undefined>>
}) {
  const COLORS = useChartColors()
  if (!combine) {
    return (
      <Card>
        <PanelHead title="Merge" icon={GitMerge} />
        <CardContent className="p-4">
          <EmptyState icon={GitMerge} title="Never combined" description="Run a combine strategy to see merge confidence here." />
        </CardContent>
      </Card>
    )
  }


  /**
   * Completeness of the merged output, as a radar.
   *
   * The Merge card showed only confidence — how sure the matcher was about the
   * pairs it merged. That says nothing about whether the merged records are
   * actually *rich*: a record can be merged at 99 confidence and still carry a
   * single reference id. Plotting the two merged collections against the same
   * axes makes the real state visible at a glance: anime is well covered,
   * manga is merged but barely enriched.
   */
  const mergeRadar = useMemo(() => {
    const cov = coverage ?? {}
    const axes = ['al', 'mal', 'k', 'cr', 'tx', 't', 'nf', 'yt']
    const series = [
      { key: 'anime', row: 'organized' as const },
      { key: 'manga', row: 'organized_manga' as const },
    ].filter(sr => (cov[sr.row]?.total ?? 0) > 0)

    const rows = axes.map(ax => {
      const row: Record<string, string | number> = { ref: ax }
      for (const sr of series) {
        const total = cov[sr.row]?.total ?? 0
        row[sr.key] = total > 0 ? Math.round(((cov[sr.row]?.[ax] ?? 0) / total) * 100) : 0
      }
      return row
    })
    // Drop axes nothing carries, so the shape is not padded with dead spokes.
    const used = rows.filter(r => series.some(sr => Number(r[sr.key]) > 0))
    return { rows: used.length >= 3 ? used : [], series: series.map(sr => sr.key) }
  }, [coverage])

  return (
    <Card className="flex flex-col">
      <PanelHead title="Merge" icon={GitMerge} />
      <CardContent className="flex min-h-0 flex-1 flex-col">
        {/* One line answers the only question that matters: how many pairs, by
            what method. Everything below is detail on that number. */}
        <div className="flex items-baseline gap-2">
          <span className="text-2xl font-medium tabular-nums">{cx(combine.merges)}</span>
          <span className="truncate text-xs text-muted-foreground">
            pairs · {combine.strategy} · base {combine.base}
          </span>
        </div>

        {/* Completeness of the merged output, not just the matcher's certainty.
            A record can merge at 99 confidence and still carry one reference. */}
        {mergeRadar.rows.length > 0 && (
          <div className="mt-2 h-36">
            <ResponsiveContainer width="100%" height="100%">
              <RadarChart data={mergeRadar.rows} cx="50%" cy="50%" outerRadius="72%">
                <PolarGrid stroke="var(--border)" />
                <PolarAngleAxis dataKey="ref" tick={{ fontSize: 9, fill: 'var(--muted-foreground)' }} />
                <PolarRadiusAxis domain={[0, 100]} tick={false} axisLine={false} />
                <Tooltip
                  contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }}
                  formatter={(v) => [`${v}%`]}
                />
                {mergeRadar.series.map((key, i) => (
                  <Radar
                    key={key}
                    name={key}
                    dataKey={key}
                    stroke={COLORS[i % COLORS.length]}
                    fill={COLORS[i % COLORS.length]}
                    fillOpacity={0.22}
                  />
                ))}
              </RadarChart>
            </ResponsiveContainer>
          </div>
        )}

        <p className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
          <span>avg <span className="tabular-nums text-foreground">{combine.scores?.avg ?? '—'}</span></span>
          <span>floor <span className="tabular-nums text-foreground">{combine.threshold ?? '—'}</span></span>
          <span>ran {ago(combine.completed_at)}</span>
          {/* Backup age is the one thing worth surfacing here that nothing
              else on this page answers. */}
          {backup?.last?.mtime != null && <span>backed up {ago(new Date(backup.last.mtime * 1000).toISOString())}</span>}
        </p>

      </CardContent>
    </Card>
  )
}
