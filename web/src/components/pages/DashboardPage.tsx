import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Activity, Archive, CircleDot, Database, Gauge, Hash, Layers,
  Play, Pause, RotateCcw, Timer, X,
} from 'lucide-react'
import { Pie, PieChart, Cell, ResponsiveContainer, Tooltip } from 'recharts'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { COLORS } from '../charts'
import { postAction, type Job } from '@/lib/api'
import { ChangePulse, KpiCard, PageHead, Section, StatusDot } from '../widgets'
import { SourceStatusBlip, SourceStatusLabel, sourceStatus } from '../source-status'
import { alignSeries, CompositionStream, PipelineFunnel, SeriesChartCard } from '../chartcard'
import { EmptyState } from '../empty'

const MAX = 120

function fmt(n: number | undefined | null): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  return n.toLocaleString()
}
function ago(ts?: number): string {
  if (!ts) return '—'
  const s = Math.max(0, Math.floor(Date.now() / 1000 - ts))
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)}m`
  if (s < 86400) return `${Math.floor(s / 3600)}h`
  return `${Math.floor(s / 86400)}d`
}
function fmtSec(n: number | undefined | null): string {
  if (n === undefined || n === null || !Number.isFinite(n)) return '—'
  if (n < 90) return `${Math.round(n)}s`
  if (n < 5400) return `${Math.round(n / 60)}m`
  return `${Math.round(n / 3600)}h`
}

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

      {!dataReady && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {[0, 1, 2, 3].map(i => <Skeleton key={i} className="h-28 w-full" />)}
        </div>
      )}

      {error && !data && (
        <EmptyState icon={Activity} title="Backend unreachable" description="Start the dashboard server (php hiyori dashboard:serve) to see live telemetry." action={() => window.location.reload()} actionLabel="Retry" />
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
                <div className="space-y-2">
                  {jobKeys.map(k => {
                    const job = jobs[k] as Job
                    const src = String(job.source ?? k)
                    const srcStatus = sourceStatus(data?.health, src)
                    const pct = job.total ? Math.min(100, ((job.processed ?? 0) / job.total) * 100) : 0
                    const pending = Math.max(0, (job.total ?? 0) - (job.processed ?? 0))
                    const isFocus = focus === src
                    return (
                      <div key={k} className={`rounded-lg border p-3 ${isFocus ? 'border-primary/60 bg-primary/5' : ''}`}>
                        <div className="flex flex-wrap items-center gap-3">
                          <button className="flex min-w-0 flex-1 items-center gap-2 text-left" onClick={() => setFocus(isFocus ? null : src)} title="Drill down">
                            {srcStatus.level !== 'none' && <SourceStatusBlip status={srcStatus} />}
                            <CircleDot className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            <span className="font-medium">{src}</span>
                            <SourceStatusLabel status={srcStatus} />
                            <Badge variant={job.phase === 'done' ? 'secondary' : 'outline'}>{job.phase ?? 'idle'}</Badge>
                            <span className="text-xs text-muted-foreground">{job.mode ?? ''}</span>
                          </button>
                          <div className="flex items-center gap-1.5">
                            <Button size="icon-sm" variant="ghost" title="Pause" onClick={() => void setPause(src, true)}><Pause className="h-3.5 w-3.5" /></Button>
                            <Button size="icon-sm" variant="ghost" title="Resume" onClick={() => void setPause(src, false)}><Play className="h-3.5 w-3.5" /></Button>
                            <Button size="icon-sm" variant="ghost" title="Retry" onClick={() => void retry(src)}><RotateCcw className="h-3.5 w-3.5" /></Button>
                          </div>
                        </div>
                        <div className="mt-2 flex items-center gap-3">
                          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                            <div className="h-full bg-primary transition-all" style={{ width: `${pct.toFixed(1)}%` }} />
                          </div>
                          <span className="whitespace-nowrap text-xs tabular-nums text-muted-foreground">
                            <ChangePulse value={`${job.per_second?.toFixed(1) ?? '—'}/s`} /> · {fmt(job.processed)}/{job.total ?? '?'} · pend {fmt(pending)} · ETA {fmtSec(job.eta_seconds)}
                          </span>
                        </div>
                      </div>
                    )
                  })}
                </div>
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
                  <div className="mt-2 max-h-40 space-y-1 overflow-auto text-sm">
                    {dataset.length === 0 && <EmptyState icon={Database} title="No collections" description="Ingest a source to build the dataset." />}
                    {dataset.map(e => (
                      <div key={e.name} className="flex items-center justify-between">
                        <span className="flex items-center gap-2 text-muted-foreground"><span className="h-2 w-2 rounded-full" style={{ background: e.color }} />{e.name}</span>
                        <span className="tabular-nums">{fmt(e.value)}</span>
                      </div>
                    ))}
                  </div>
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
