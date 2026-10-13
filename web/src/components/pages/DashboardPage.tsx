import { useEffect, useMemo, useRef, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  Activity, Archive, CircleDot, Database, Gauge, Hash, Layers, Loader2,
  Play, Pause, RotateCcw, Timer, X,
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
import { COLORS } from '../charts'
import { postAction, type HealthCheck, type Job } from '@/lib/api'
import { ChangePulse, KpiCard, PageHead, Section, StatusDot } from '../widgets'
import { SourceStatusBlip, SourceStatusLabel, sourceStatus, type SourceStatus } from '../source-status'
import { alignSeries, CompositionStream, PipelineFunnel, SeriesChartCard } from '../chartcard'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'

const MAX = 120

const WIDGET_IDS = ['kpi', 'fleet', 'rates', 'counts', 'dataset', 'health', 'pipeline', 'alerts']

export function DashboardPage() {
  const { settings, update } = useSettings()
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
        if (!(data.jobs[k] as Job)?.source) return
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
  const jobKeys = Object.keys(jobs).filter(k => (jobs[k] as Job)?.source).sort()
  const activeJobs = jobKeys.filter(k => (jobs[k] as Job).phase !== 'done' && (jobs[k] as Job).phase !== 'idle')
  const totalDocs = useMemo(() => Object.values(counts).reduce((a, b) => a + b, 0), [counts])
  const totalErrors = useMemo(() => jobKeys.reduce((a, k) => a + ((jobs[k] as Job).errors ?? 0), 0), [jobs, jobKeys])
  const activeRate = useMemo(() => jobKeys.reduce((a, k) => a + ((jobs[k] as Job).per_second ?? 0), 0), [jobs, jobKeys])

  const kpiDocsSpark = useMemo(() => (countSeries.totalDocs ?? []).slice(-win).map((v, i) => ({ t: i, v })), [countSeries, win])
  void kpiDocsSpark

  const dataset = useMemo(() => {
    const keys = Object.keys(counts).sort().filter(k => !hiddenCollections.includes(k))
    return keys.map((k, i) => ({ name: k, value: counts[k], color: COLORS[i % COLORS.length] }))
  }, [counts, hiddenCollections])

  const rateSeriesArr = useMemo(() => {
    const keys = focus ? jobKeys.filter(k => k.replace('ingest-', '') === focus || k === `ingest-${focus}`) : jobKeys
    return keys.map(k => ({ key: k.replace('ingest-', ''), color: COLORS[jobKeys.indexOf(k) % COLORS.length], data: (rateSeries[k] ?? []).slice(-win) }))
  }, [rateSeries, jobKeys, focus, win])

  const countSeriesArr = useMemo(() => {
    const keys = Object.keys(countSeries).sort().filter(k => !hiddenCollections.includes(k))
    return keys.map((k, i) => ({ key: k, color: COLORS[i % COLORS.length], data: (countSeries[k] ?? []).slice(-win) }))
  }, [countSeries, hiddenCollections, win])

  const ratesRows = useMemo(() => alignSeries(rateSeriesArr), [rateSeriesArr])
  const countsRows = useMemo(() => alignSeries(countSeriesArr), [countSeriesArr])

  const h = data?.health
  const health = h?.checks
  const gauges = [
    { key: 'disk', label: 'Disk', value: health?.disk_space?.used_percent ?? 0, color: '#ff8f6b' },
    { key: 'mem', label: 'Memory', value: health?.memory?.used_percent ?? 0, color: '#e8b64c' },
    { key: 'mongo', label: 'MongoDB', value: health?.mongodb?.healthy === false ? 0 : health?.mongodb?.latency_ms != null ? Math.min(100, Math.max(10, 100 - health.mongodb.latency_ms / 10)) : 0, color: '#3fd06a' },
  ]
  const circuits = Object.entries(health?.rate_limiter?.limiters ?? {})
  const extApis = Object.entries(health?.external_apis?.endpoints ?? {})

  const funnel = useMemo(() => {
    const ingest = totalDocs
    const organized = data?.db?.collections?.organized ?? 0
    const violations = data?.validation?.length ?? 0
    const merged = Math.max(0, ingest - violations)
    return [
      { name: 'Ingest', value: ingest, color: '#4fb3ff' },
      { name: 'Validate', value: merged, color: '#2fb5b0' },
      { name: 'Combine', value: organized, color: '#e8b64c' },
      { name: 'Export', value: data?.backup?.exists ? organized : 0, color: '#9b7bff' },
    ]
  }, [totalDocs, data])

  const retry = async (src: string) => {
    await postAction(`ingest-${src}`, 'retry')
    toast(`${src} retry scheduled`)
  }
  const setPause = async (src: string, paused: boolean) => {
    await postAction(`ingest-${src}`, paused ? 'pause' : 'resume')
    toast(`${src} ${paused ? 'paused' : 'resumed'}`)
  }
  const hideWidget = (id: string) => {
    const cur = widgets ?? WIDGET_IDS
    void update({ prefs: { ...settings.prefs, widgets: cur.filter(w => w !== id) } })
  }

  const dataReady = !!data || !!error

  return (
    <div className="space-y-4">
      <PageHead
        title="Dashboard"
        desc="Live telemetry — one view for the whole pipeline."
        actions={
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
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
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {[0, 1, 2, 3].map(i => <Skeleton key={i} className="h-28 w-full" />)}
        </div>
      )}


      {data && (
        <>
          {visible('kpi') && (
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <KpiCard label="Total docs" value={fmt(totalDocs)} icon={Database} sub={`${fmt(activeJobs.length)} active sources`} color={COLORS[0]} id="kpi-docs" />
              <KpiCard label="Ingest rate" value={`${activeRate.toFixed(1)}/s`} icon={Gauge} sub="combined entries per second" color={COLORS[1]} id="kpi-rate" />
              <KpiCard label="Errors" value={fmt(totalErrors)} icon={Hash} sub="since start" color={COLORS[5]} id="kpi-err" />
              <KpiCard label="Last backup" value={data?.backup?.last ? ago(data.backup.last.mtime) : 'none'} icon={Archive} sub={data?.backup?.last ? `${((data.backup.last.size ?? 0) / 1048576).toFixed(1)} MB` : 'run one in Management'} color={COLORS[3]} id="kpi-backup" />
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

          {focus && (
            <div className="flex items-center gap-2">
              <Badge variant="outline" className="gap-1"><span className="h-2 w-2 rounded-full bg-primary" /> focusing {focus}
                <button onClick={() => setFocus(null)} className="text-muted-foreground hover:text-foreground"><X className="h-3 w-3" /></button>
              </Badge>
            </div>
          )}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {visible('rates') && <SeriesChartCard title="Ingestion rate" series={rateSeriesArr} data={ratesRows} emptyMsg="start an ingestion to see rates" helper={<SelectWindow win={win} setWin={setWin} />} />}
            {visible('counts') && <CompositionStream title="Collection share" data={countsRows} keys={countSeriesArr.map(s => s.key)} colors={countSeriesArr.map(s => s.color)} emptyMsg="no collection history yet" />}
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {visible('dataset') && (
              <Card>
                <CardHeader className="flex-row items-center justify-between space-y-0">
                  <CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Dataset</CardTitle>
                  <Button size="icon-xs" variant="ghost" title="Hide widget" onClick={() => hideWidget('dataset')}><X className="h-3 w-3" /></Button>
                </CardHeader>
                <CardContent>
                  <ResponsiveContainer width="100%" height={150}>
                    <PieChart>
                      <Pie data={dataset} dataKey="value" nameKey="name" innerRadius={50} outerRadius={70} paddingAngle={2} strokeWidth={0} isAnimationActive={false}>
                        {dataset.map(e => <Cell key={e.name} fill={e.color} />)}
                      </Pie>
                      <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                    </PieChart>
                  </ResponsiveContainer>
                  <ScrollArea className="mt-2 max-h-40">
                    <div className="space-y-1 text-sm">
                    {dataset.length === 0 && <EmptyState icon={Database} title="No collections" description="Ingest a source to build the dataset." />}
                    {dataset.map(e => (
                      <div key={e.name} className="flex items-center justify-between">
                        <span className="flex items-center gap-2 text-muted-foreground"><span className="h-2 w-2 rounded-full" style={{ background: e.color }} />{e.name}</span>
                        <span className="tabular-nums">{fmt(e.value)}</span>
                      </div>
                    ))}
                    </div>
                  </ScrollArea>
                </CardContent>
              </Card>
            )}

            {visible('health') && (
              <Card>
                <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Health</CardTitle></CardHeader>
                <CardContent>
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
                  <div className="mt-3 space-y-1 border-t pt-2 text-xs">
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
                  </div>
                </CardContent>
              </Card>
            )}

            {visible('pipeline') && <PipelineFunnel stages={funnel} />}
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
    <select value={win} onChange={e => setWin(Number(e.target.value))} className="h-7 rounded-md border bg-transparent px-1 text-xs">
      <option value={20}>1m</option>
      <option value={100}>5m</option>
      <option value={300}>15m</option>
    </select>
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

/** Ingestion fleet rendered as a shared DataTable (Source / Phase / Progress / Speed / Pending / ETA / Done~ / Actions). */
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
        .filter(k => (jobs[k] as Job)?.source)
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
        meta: { align: 'left' } as const,
        cell: ({ row }) => {
          const { src, status } = row.original
          const isFocused = focus === src
          return (
            <span className="flex min-w-0 items-center gap-2">
              {status.level !== 'none' && <SourceStatusBlip status={status} />}
              <CircleDot className={cn('h-3.5 w-3.5 shrink-0', isFocused ? 'text-primary' : 'text-muted-foreground')} />
              <span className={cn('font-medium', isFocused && 'text-primary')}>{src}</span>
              <SourceStatusLabel status={status} />
              <span className="text-xs text-muted-foreground">{row.original.job.mode ?? ''}</span>
            </span>
          )
        },
      },
      {
        id: 'phase',
        header: 'Phase',
        accessorFn: r => r.job.phase ?? 'idle',
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: ({ row }) => (
          <Badge variant={row.original.job.phase === 'done' ? 'secondary' : 'outline'}>
            {row.original.job.phase ?? 'idle'}
          </Badge>
        ),
      },
      {
        id: 'progress',
        header: 'Progress',
        accessorFn: r => r.pct,
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: ({ row }) => (
          <span className="flex min-w-0 items-center gap-2">
            <span className="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-muted">
              <span className="block h-full bg-primary transition-all" style={{ width: `${row.original.pct.toFixed(1)}%` }} />
            </span>
            <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">
              {fmt(row.original.job.processed)}/{row.original.job.total ?? '?'}
            </span>
          </span>
        ),
      },
      {
        id: 'speed',
        header: 'Speed',
        accessorFn: r => r.job.per_second ?? 0,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => <ChangePulse value={`${row.original.job.per_second?.toFixed(1) ?? '—'}/s`} />,
      },
      {
        id: 'pending',
        header: 'Pending',
        accessorFn: r => r.pending,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => <span className="tabular-nums text-muted-foreground">{fmt(row.original.pending)}</span>,
      },
      {
        id: 'eta',
        header: 'ETA',
        accessorFn: r => r.job.eta_seconds ?? Infinity,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => <span className="tabular-nums text-muted-foreground">{fmtSec(row.original.job.eta_seconds)}</span>,
      },
      {
        id: 'done_at',
        header: 'Done~',
        accessorFn: r => r.job.eta_seconds ?? Infinity,
        sortingFn: 'basic',
        meta: { align: 'right' } as const,
        cell: ({ row }) => {
          const eta = row.original.job.eta_seconds
          if (eta == null || !Number.isFinite(eta)) return <span className="text-muted-foreground">—</span>
          const doneAt = new Date(Date.now() + eta * 1000)
          return (
            <span className="tabular-nums text-muted-foreground">
              {doneAt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
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
          const src = row.original.src
          return (
            <span className="flex items-center justify-end gap-1">
              <Button size="icon-sm" variant="ghost" title="Pause" onClick={() => onPause(src)}>
                <Pause className="h-3.5 w-3.5" />
              </Button>
              <Button size="icon-sm" variant="ghost" title="Resume" onClick={() => onResume(src)}>
                <Play className="h-3.5 w-3.5" />
              </Button>
              <Button size="icon-sm" variant="ghost" title="Retry" onClick={() => onRetry(src)}>
                <RotateCcw className="h-3.5 w-3.5" />
              </Button>
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
