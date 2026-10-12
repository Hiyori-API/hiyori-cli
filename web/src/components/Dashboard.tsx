import { useCallback, useMemo, useState } from 'react'
import {
  Activity, AlertTriangle, Clock, Database, Download, Gauge, GitMerge, LayoutDashboard,
  ListChecks, Moon, Pause, Play, RefreshCw, RotateCcw, ShieldCheck, Sun,
} from 'lucide-react'
import { Bar, BarChart, Line, LineChart, Pie, PieChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis, Legend } from 'recharts'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Breadcrumb, BreadcrumbItem, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarInset, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarProvider, SidebarRail, SidebarTrigger,
} from '@/components/ui/sidebar'
import { toast } from 'sonner'
import { usePolling } from '@/lib/hooks'
import { COLORS } from './charts'
import { EmptyState } from './empty'
import { fetchBenchmark, fetchSample, postAction, runBackup, type BenchmarkRow, type Job } from '@/lib/api'

const CAP = 120
const FULL_COLS = ['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'cr', 'nf', 'sb', 'ttv', 't', 'adb', 'tx', 'b', 'fm', 'yt', 'ap', 'tvt']

type View = 'overview' | 'ingest' | 'dataset' | 'health' | 'merge'
const NAV: { id: View; label: string; icon: typeof LayoutDashboard }[] = [
  { id: 'overview', label: 'Overview', icon: LayoutDashboard },
  { id: 'ingest', label: 'Ingestion', icon: Activity },
  { id: 'dataset', label: 'Dataset & Coverage', icon: Database },
  { id: 'health', label: 'Health & Alerts', icon: ShieldCheck },
  { id: 'merge', label: 'Merge & Quality', icon: GitMerge },
]

function fmt(n: number | undefined | null): string {
  if (n === undefined || n === null || Number.isNaN(n)) return '—'
  return n.toLocaleString()
}
function mb(n: number | undefined | null): string {
  if (n === undefined || n === null) return '—'
  return (n / 1024 / 1024).toFixed(1) + ' MB'
}
function ago(ts?: number): string {
  if (!ts) return '—'
  const s = Math.max(0, Math.floor(Date.now() / 1000 - ts))
  if (s < 60) return `${s}s ago`
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  return `${Math.floor(s / 3600)}h ago`
}
function fmtSec(n: number | undefined | null): string {
  if (n === undefined || n === null || !Number.isFinite(n)) return '—'
  if (n < 90) return `${n}s`
  if (n < 5400) return `${Math.round(n / 60)}m`
  return `${Math.round(n / 3600)}h ${Math.round((n % 3600) / 60)}m`
}
function toCSV(rows: Record<string, unknown>[], headings: string[]): void {
  const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const lines = [headings.map(esc).join(',')]
  rows.forEach(r => lines.push(headings.map(h => esc(r[h])).join(',')))
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'hiyori-dashboard.csv'
  a.click()
}
function exportCSV(jobsKeys: string[], jobs: Record<string, Job>) {
  const rows = jobsKeys.map(k => ({ source: jobs[k].source, phase: jobs[k].phase, processed: jobs[k].processed, saved: jobs[k].saved, skipped: jobs[k].skipped, errors: jobs[k].errors, speed: jobs[k].per_second }))
  toCSV(rows, ['source', 'phase', 'processed', 'saved', 'skipped', 'errors', 'speed'])
}

export function Dashboard() {
  const { data, error } = usePolling(3000, true)
  const [view, setView] = useState<View>('overview')
  const [sortKey, setSortKey] = useState<'source' | 'processed' | 'per_second'>('source')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [logScale, setLogScale] = useState(false)
  const [area, setArea] = useState(false)
  const [tz, setTz] = useState<'local' | 'utc'>('local')
  const [dark, setDark] = useState<boolean>(() => (localStorage.getItem('hiyori-theme') ?? 'dark') === 'dark')
  const [bench, setBench] = useState<{ seeded?: BenchmarkRow[]; latest?: unknown } | null>(null)
  const [sample, setSample] = useState<Record<string, unknown> | null | undefined>(undefined)
  const [samplerFeed, setSamplerFeed] = useState<{ right: number; wrong: number }>({ right: 0, wrong: 0 })
  const [dismissedAlert, setDismissedAlert] = useState<string | null>(null)

  const toggleTheme = () => {
    setDark(d => {
      const n = !d
      document.documentElement.classList.toggle('dark', n)
      localStorage.setItem('hiyori-theme', n ? 'dark' : 'light')
      return n
    })
  }

  const refreshBench = useCallback(async () => {
    try { setBench(await fetchBenchmark()) } catch { /* ignore */ }
  }, [])
  useMemo(() => { void refreshBench() }, [refreshBench])

  const history = useMemo(() => {
    const counts: Record<string, number[]> = {}
    const rates: Record<string, number[]> = {}
    if (!data) return { counts, rates }
    Object.entries(data.counts).forEach(([k, v]) => {
      if (!counts[k]) counts[k] = []
      counts[k].push(v)
      if (counts[k].length > CAP) counts[k].shift()
    })
    const known = new Set(Object.keys(data.jobs))
    Object.keys(rates).forEach(k => { if (!known.has(k)) delete rates[k] })
    Object.entries(data.jobs).forEach(([k, j]) => {
      if (!j?.source) return
      if (!rates[k]) rates[k] = []
      rates[k].push(j.per_second ?? 0)
      if (rates[k].length > CAP) rates[k].shift()
    })
    return { counts, rates }
  }, [data])

  const countsRows = useMemo(() => {
    const keys = Object.keys(history.counts).sort()
    if (!keys.length) return []
    const n = Math.min(CAP, history.counts[keys[0]].length)
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = { label: `${i * 3}s` }
      keys.forEach(k => { row[k] = history.counts[k][history.counts[k].length - n + i] ?? 0 })
      rows.push(row)
    }
    return rows
  }, [history, CAP])

  const ratesRows = useMemo(() => {
    const keys = Object.keys(history.rates).sort()
    if (!keys.length) return []
    const n = Math.min(CAP, ...Object.values(history.rates).map(a => a.length))
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = { label: `${i * 3}s` }
      keys.forEach(k => { row[k] = history.rates[k][history.rates[k].length - n + i] ?? 0 })
      rows.push(row)
    }
    return rows
  }, [history, CAP])

  const totalDocs = useMemo(() => Object.values(data?.counts ?? {}).reduce((a, b) => a + b, 0), [data])
  const jobs = data?.jobs ?? {}
  const jobKeys = Object.keys(jobs).filter(k => jobs[k]?.source).sort()
  const sortedJobs = useMemo(() => {
    const items = jobKeys.map(k => ({ key: k, job: jobs[k] as Job }))
    const dir = sortDir === 'asc' ? 1 : -1
    return items.sort((a, b) => {
      const av = (a.job[sortKey] as number) ?? 0
      const bv = (b.job[sortKey] as number) ?? 0
      return typeof av === 'number' && typeof bv === 'number' ? (av - bv) * dir : String(a.job.source).localeCompare(String(b.job.source)) * dir
    })
  }, [jobs, jobKeys, sortKey, sortDir])

  const h = data?.health
  const alerts = data?.alerts ?? []
  const coverage = (data?.coverage ?? {}) as Record<string, Record<string, number | undefined>>
  const dataset = useMemo(() => {
    const base = Object.keys(data?.counts ?? {}).sort()
    const keys = base.filter(k => !k.includes('_manga'))
    return keys.map((k, i) => ({ name: k, value: data!.counts[k], color: COLORS[i % COLORS.length] }))
  }, [data])
  const hasManga = Object.keys(data?.counts ?? {}).some(k => k.includes('_manga'))

  const sampled = sample
  const yLog = logScale ? 'log' : 'auto'

  const jobsTable = (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Ingestion jobs</CardTitle>
        <div className="flex items-center gap-1.5">
          <Select value={sortKey} onValueChange={v => setSortKey(v as typeof sortKey)}>
            <SelectTrigger className="h-8 w-32"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="source">Source</SelectItem>
              <SelectItem value="processed">Processed</SelectItem>
              <SelectItem value="per_second">Speed</SelectItem>
            </SelectContent>
          </Select>
          <Button size="sm" variant="outline" onClick={() => setSortDir(d => (d === 'asc' ? 'desc' : 'asc'))}>
            {sortDir === 'asc' ? '↑' : '↓'}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="p-0">
        {error && <p className="px-4 py-2 text-xs text-red-500">backend unreachable — start the dashboard server</p>}
        {!data && !error && <div className="space-y-2 px-4 py-3"><Skeleton className="h-8 w-full" /><Skeleton className="h-8 w-full" /></div>}
        {data && jobKeys.length === 0 && (
          <div className="p-4">
            <EmptyState icon={Activity} title="No jobs running" description="Start an ingestion to see live progress, speed and ETA here." />
          </div>
        )}
        {data && jobKeys.length > 0 && (
          <ScrollArea className="max-h-[420px]">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-4 py-2">Source</th><th className="px-2 py-2">Status</th><th className="px-2 py-2">Progress</th>
                  <th className="px-2 py-2 text-right">Speed</th><th className="px-2 py-2 text-right">Pending</th>
                  <th className="px-2 py-2 text-right">Saved/Skip</th><th className="px-2 py-2 text-right">ETA</th><th className="px-4 py-2 text-right">Done ~</th>
                </tr>
              </thead>
              <tbody>
                {sortedJobs.map(({ key, job }) => {
                  const pct = job.total ? Math.min(100, ((job.processed ?? 0) / job.total) * 100) : 0
                  const pending = Math.max(0, (job.total ?? 0) - (job.processed ?? 0))
                  return (
                    <tr key={key} className="border-t">
                      <td className="px-4 py-2">
                        <div className="flex items-center gap-1">
                          <span>{job.source}</span>
                          <Button size="icon-sm" variant="ghost" title="Pause" onClick={async () => { await postAction(String(job.source), 'pause'); toast(`${job.source} paused`) }}><Pause className="h-3.5 w-3.5" /></Button>
                          <Button size="icon-sm" variant="ghost" title="Resume" onClick={async () => { await postAction(String(job.source), 'resume'); toast(`${job.source} resumed`) }}><Play className="h-3.5 w-3.5" /></Button>
                          <Button size="icon-sm" variant="ghost" title="Retry" onClick={async () => { await postAction(String(job.source), 'retry'); toast(`${job.source} retry scheduled`) }}><RotateCcw className="h-3.5 w-3.5" /></Button>
                        </div>
                      </td>
                      <td className="px-2 py-2"><Badge variant={job.phase === 'done' ? 'secondary' : 'outline'}>{job.phase ?? 'idle'}</Badge></td>
                      <td className="px-2 py-2">
                        <div className="flex items-center gap-2">
                          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><div className="h-full bg-primary" style={{ width: `${pct.toFixed(1)}%` }} /></div>
                          <span className="text-xs text-muted-foreground">{fmt(job.processed)}/{job.total ?? '?'}</span>
                        </div>
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums">{job.per_second?.toFixed(1) ?? '—'}</td>
                      <td className="px-2 py-2 text-right tabular-nums">{fmt(pending)}</td>
                      <td className="px-2 py-2 text-right tabular-nums">{fmt(job.saved)}/{fmt(job.skipped)}</td>
                      <td className="px-2 py-2 text-right tabular-nums">{fmtSec(job.eta_seconds)}</td>
                      <td className="px-4 py-2 text-right tabular-nums text-muted-foreground">{job.eta_seconds ? new Date(Date.now() + job.eta_seconds * 1000).toLocaleTimeString() : '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </ScrollArea>
        )}
      </CardContent>
    </Card>
  )

  const chartsCard = (title: string, rows: { label: string; [k: string]: string | number }[], series: { key: string; color: string }[], emptyMsg: string) => (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">{title}</CardTitle></CardHeader>
      <CardContent>
        <div className="h-56">
          {rows.length ? (
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={rows} margin={{ top: 6, right: 8, bottom: 0, left: -14 }}>
                <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} minTickGap={40} />
                <YAxis scale={yLog} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                {series.map((s, i) =>
                  area
                    ? <Line key={s.key} type="monotone" dataKey={s.key} stroke={COLORS[i % COLORS.length]} fill={COLORS[i % COLORS.length]} fillOpacity={0.15} dot={false} strokeWidth={2} />
                    : <Line key={s.key} type="monotone" dataKey={s.key} stroke={COLORS[i % COLORS.length]} dot={false} strokeWidth={2} />
                )}
              </LineChart>
            </ResponsiveContainer>
          ) : <EmptyState icon={Gauge} title="Waiting for data" description={emptyMsg} />}
        </div>
      </CardContent>
    </Card>
  )

  const datasetCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Dataset · {fmt(totalDocs)}{hasManga ? ' · + manga' : ''}</CardTitle></CardHeader>
      <CardContent>
        {data ? (
          <>
            <ResponsiveContainer width="100%" height={150}>
              <PieChart>
                <Pie data={dataset} dataKey="value" nameKey="name" innerRadius={50} outerRadius={70} paddingAngle={2} strokeWidth={0}>
                  {dataset.map(e => <Cell key={e.name} fill={e.color} />)}
                </Pie>
                <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
              </PieChart>
            </ResponsiveContainer>
            <div className="mt-2 space-y-1 text-sm">
              {Object.entries(data.counts).sort().map(([k, v]) => (
                <div key={k} className="flex items-center justify-between">
                  <span className="flex items-center gap-2 text-muted-foreground">
                    <span className="h-2 w-2 rounded-full" style={{ background: COLORS[Object.keys(data.counts).sort().indexOf(k) % COLORS.length] }} />{k}
                  </span>
                  <span className="tabular-nums">{fmt(v)}</span>
                </div>
              ))}
              {Object.keys(data.counts).length === 0 && <EmptyState icon={Database} title="No collections yet" description="Ingest a source to build the dataset." />}
            </div>
          </>
        ) : <Skeleton className="h-40 w-full" />}
      </CardContent>
    </Card>
  )

  const coverageCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Reference coverage matrix</CardTitle></CardHeader>
      <CardContent className="overflow-x-auto">
        {Object.keys(coverage).length ? (
          <table className="w-full text-xs">
            <thead>
              <tr className="text-muted-foreground">
                <th className="px-1 py-1 text-left">Collection</th>
                {FULL_COLS.map(s => <th key={s} className="px-1 py-1 text-center">{s}</th>)}
              </tr>
            </thead>
            <tbody>
              {Object.entries(coverage).map(([name, row]) => (
                <tr key={name} className="border-t">
                  <td className="px-1 py-1">{name} <span className="text-muted-foreground">({fmt(row.total as number)})</span></td>
                  {FULL_COLS.map(s => {
                    const v = row[s] as number
                    const pct = row.total ? (v / (row.total as number)) : 0
                    return <td key={s} className="px-1 py-1 text-center tabular-nums" title={fmt(v)}>
                      <span className={`rounded px-1 ${pct > 0.5 ? 'bg-emerald-500/20 text-emerald-500' : pct > 0 ? 'bg-amber-500/20 text-amber-500' : 'text-muted-foreground'}`}>{pct > 0 ? (pct * 100).toFixed(0) : '·'}</span>
                    </td>
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        ) : <EmptyState icon={Database} title="Coverage unavailable" description="No source collections ingested yet." />}
        <p className="mt-2 text-[10px] text-muted-foreground">% of docs carrying each reference shorthand</p>
      </CardContent>
    </Card>
  )

  const dbCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Database & backups</CardTitle></CardHeader>
      <CardContent>
        {!data?.db ? <Skeleton className="h-24 w-full" /> : (
          <div className="grid grid-cols-2 gap-2 text-sm">
            <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Data</div><div className="font-medium">{mb(data.db.dataSize)}</div></div>
            <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Index</div><div className="font-medium">{mb(data.db.indexSize)}</div></div>
            <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Storage</div><div className="font-medium">{mb(data.db.storageSize)}</div></div>
            <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Docs</div><div className="font-medium">{fmt(data.db.totalDocuments)}</div></div>
          </div>
        )}
        <div className="mt-3 space-y-1.5 text-sm">
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">Last backup</span>
            <span className="tabular-nums">{data?.backup?.last ? `${ago(data.backup.last.mtime)} · ${(data.backup.last.size ?? 0 / 1048576).toFixed(1)} MB` : 'none'}</span>
          </div>
          <Button size="sm" variant="outline" className="w-full" onClick={async () => { await runBackup(); toast('backup started') }}>
            <Download className="mr-2 h-3.5 w-3.5" /> Backup now
          </Button>
        </div>
      </CardContent>
    </Card>
  )

  const histCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Merge confidence histogram</CardTitle></CardHeader>
      <CardContent>
        {!data?.combine?.score_histogram ? (
          <EmptyState icon={GitMerge} title="No scores yet" description="Run metadata_fuzzy to populate the confidence histogram." />
        ) : (
          <div className="flex h-36 items-end gap-3">
            {([['70-80', data.combine.score_histogram.p70_80], ['80-90', data.combine.score_histogram.p80_90], ['90-100', data.combine.score_histogram.p90_100]] as const).map(([label, v]) => (
              <div key={label} className="flex flex-1 flex-col items-center gap-1">
                <span className="text-xs tabular-nums">{v ?? 0}</span>
                <div className="w-full rounded-t-md bg-gradient-to-t from-primary to-primary/40" style={{ height: `${(((v ?? 0) / Math.max(1, data.combine.score_histogram!.p90_100 ?? 1)) * 100).toFixed(0)}%` }} />
                <span className="text-[10px] text-muted-foreground">{label}</span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )

  const samplerCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Merge sampler</CardTitle></CardHeader>
      <CardContent>
        {sampled === undefined && (
          <EmptyState icon={ListChecks} title="Spot-check merges" description="Draw a random merged record and judge whether it’s correct." action={() => void fetchSample('organized').then(r => setSample(r.sample ?? null))} actionLabel="Draw a sample" />
        )}
        {sampled === null && <p className="text-sm text-muted-foreground">organized is empty</p>}
        {sampled && (
          <div className="space-y-2 text-sm">
            <div className="rounded-lg border p-2">
              <div className="font-medium">{(sampled as { title?: string }).title ?? '(no title)'}</div>
              <div className="text-xs text-muted-foreground">refs: {Object.keys(((sampled as { reference_ids?: Record<string, string> }).reference_ids) ?? {}).join(', ') || 'none'}</div>
              {'confidence' in sampled && <div className="text-xs">confidence: {fmt(Number(sampled.confidence))}</div>}
            </div>
            <div className="flex gap-2">
              <Button size="sm" className="flex-1" onClick={() => { setSamplerFeed(s => ({ ...s, right: s.right + 1 })); setSample(undefined) }}>Looks right</Button>
              <Button size="sm" className="flex-1" variant="destructive" onClick={() => { setSamplerFeed(s => ({ ...s, wrong: s.wrong + 1 })); setSample(undefined) }}>Wrong</Button>
              <Button size="sm" className="flex-1" variant="outline" onClick={() => { void fetchSample('organized').then(r => setSample(r.sample ?? null)) }}>Next</Button>
            </div>
            {(samplerFeed.right || samplerFeed.wrong) > 0 && (
              <p className="text-xs text-muted-foreground">session: {samplerFeed.right} ✓ / {samplerFeed.wrong} ✗ ({(samplerFeed.right / Math.max(1, samplerFeed.right + samplerFeed.wrong) * 100).toFixed(0)}% ok)</p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  )

  const benchmarkCard = (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Strategy benchmark</CardTitle>
        <Button size="icon-sm" variant="ghost" onClick={() => void refreshBench()}><RefreshCw className="h-3.5 w-3.5" /></Button>
      </CardHeader>
      <CardContent>
        {!bench?.seeded?.length ? <Skeleton className="h-40 w-full" /> : (
          <>
            <div className="h-48">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={bench.seeded} margin={{ top: 6, right: 8, bottom: 0, left: -14 }}>
                  <XAxis dataKey="strategy" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} />
                  <YAxis tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                  <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                  <Legend wrapperStyle={{ fontSize: 11 }} />
                  <Bar dataKey="merges" name="merges" fill={COLORS[0]} radius={[4, 4, 0, 0]} />
                  <Bar dataKey="speed" name="merges/s" fill={COLORS[1]} radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
            <div className="mt-2 space-y-1 text-xs">
              {bench.seeded.map(b => (
                <div key={b.strategy} className="flex items-center justify-between text-muted-foreground">
                  <span>{b.strategy}</span>
                  <span className="tabular-nums">{fmt(b.merges)} merges · {Math.round((b.wall_s ?? 0) / 60)}m wall · {b.speed}/s</span>
                </div>
              ))}
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )

  const healthCard = (
    <Card>
      <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">API health</CardTitle></CardHeader>
      <CardContent>
        {!data && <Skeleton className="h-24 w-full" />}
        {data && (
          <div className="space-y-2 text-sm">
            {h?.checks?.external_apis?.endpoints && Object.entries(h.checks.external_apis.endpoints).map(([name, e]) => (
              <div key={name} className="flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${e.healthy === false ? 'bg-red-500' : 'bg-emerald-500'}`} />
                <span className="flex-1">{name}</span>
                <span className="tabular-nums text-muted-foreground">{e.latency_ms != null ? `${e.latency_ms}ms` : '—'}</span>
              </div>
            ))}
            <div className="grid grid-cols-2 gap-2 pt-2 text-xs">
              <div className="rounded-lg border p-2"><div className="text-muted-foreground">MongoDB</div><div className="font-medium">{h?.checks?.mongodb?.latency_ms}ms</div></div>
              <div className="rounded-lg border p-2"><div className="text-muted-foreground">Disk</div><div className="font-medium">{h?.checks?.disk_space?.used_percent}%</div></div>
              <div className="rounded-lg border p-2"><div className="text-muted-foreground">Memory</div><div className="font-medium">{h?.checks?.memory?.used_percent}%</div></div>
              <div className="rounded-lg border p-2"><div className="text-muted-foreground">Circuit</div><div className="font-medium">{Object.values(h?.checks?.rate_limiter?.limiters ?? {}).some(l => l.circuit_state === 'open') ? 'open' : 'closed'}</div></div>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )

  const alertsBanner = alerts.filter(a => a.msg !== dismissedAlert).length > 0 && (
    <div className="col-span-12 space-y-2">
      {alerts.filter(a => a.msg !== dismissedAlert).map((a, i) => (
        <div key={i} className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${a.level === 'critical' ? 'border-red-500/50 bg-red-500/10 text-red-500' : 'border-amber-500/50 bg-amber-500/10 text-amber-500'}`}>
          <AlertTriangle className="h-4 w-4" />
          <span className="flex-1">{a.msg}</span>
          <Button size="sm" variant="ghost" onClick={() => setDismissedAlert(a.msg ?? null)}>dismiss</Button>
        </div>
      ))}
    </div>
  )

  const pages: Record<View, React.ReactNode> = {
    overview: (
      <div className="grid grid-cols-12 gap-4">
        {alertsBanner}
        {jobsTable && <div className="col-span-12 lg:col-span-8">{jobsTable}</div>}
        <div className="col-span-12 lg:col-span-4">{datasetCard}</div>
        <div className="col-span-12 lg:col-span-6">{chartsCard('Ingestion rate (entries/s)', ratesRows, Object.keys(history.rates).sort().map((k, i) => ({ key: k.replace('ingest-', ''), color: COLORS[i % COLORS.length] })), 'start an ingestion to see rates')}</div>
        <div className="col-span-12 lg:col-span-6">{chartsCard('Collection counts', countsRows, Object.keys(history.counts).sort().map((k, i) => ({ key: k, color: COLORS[i % COLORS.length] })), 'no history yet')}</div>
        <div className="col-span-12">
          <Card><CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Pipeline</CardTitle></CardHeader><CardContent>
            <div className="flex flex-wrap items-center gap-2">
              {['Ingest', 'Validate', 'Combine', 'Export'].map((stage, i) => (
                <div key={stage} className="flex items-center gap-2">
                  <div className={`rounded-lg border px-4 py-2 text-sm ${i === 0 ? 'border-primary/40' : ''}`}>
                    <div className="flex items-center gap-2"><span className={`h-2 w-2 rounded-full ${i === 0 && jobKeys.length ? 'bg-emerald-500' : 'bg-muted'}`} />{stage}</div>
                  </div>
                  {i < 3 && <Separator orientation="vertical" className="h-4" />}
                </div>
              ))}
              <span className="ml-auto text-xs text-muted-foreground">
                {jobKeys.length ? `${jobKeys.length} active source(s)` : 'idle'} · {(data?.validation?.length ?? 0) > 0 ? `${data?.validation?.length} recent violations` : 'validation clean'} · {data?.combine?.strategy ? `last combine: ${data.combine.strategy}` : 'no combine yet'}
              </span>
            </div>
          </CardContent></Card>
        </div>
      </div>
    ),
    ingest: (
      <div className="col-span-12 grid grid-cols-12 gap-4">
        <div className="col-span-12">{jobsTable}</div>
        <div className="col-span-12 lg:col-span-6">{chartsCard('Ingestion rate (entries/s)', ratesRows, Object.keys(history.rates).sort().map((k, i) => ({ key: k.replace('ingest-', ''), color: COLORS[i % COLORS.length] })), 'start an ingestion to see rates')}</div>
        <div className="col-span-12 lg:col-span-6">{chartsCard('Collection counts', countsRows, Object.keys(history.counts).sort().map((k, i) => ({ key: k, color: COLORS[i % COLORS.length] })), 'no history yet')}</div>
      </div>
    ),
    dataset: (
      <div className="grid grid-cols-12 gap-4">
        <div className="col-span-12 lg:col-span-4">{datasetCard}</div>
        <div className="col-span-12 lg:col-span-4">{dbCard}</div>
        <div className="col-span-12 lg:col-span-4">{histCard}</div>
        <div className="col-span-12">{coverageCard}</div>
      </div>
    ),
    health: (
      <div className="grid grid-cols-12 gap-4">
        {alertsBanner}
        <div className="col-span-12 md:col-span-4">{healthCard}</div>
        <div className="col-span-12 md:col-span-4">
          <Card><CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Scheduler</CardTitle></CardHeader><CardContent className="space-y-2 text-sm">
            <div className="flex items-center justify-between"><span className="text-muted-foreground">Sources</span><span className="tabular-nums">{data?.schedule?.sources ?? '—'}</span></div>
            <div className="flex items-center justify-between"><span className="text-muted-foreground">Interval</span><span className="tabular-nums">{data?.schedule?.interval_hours ?? 24} h</span></div>
            <div className="flex items-center justify-between"><span className="text-muted-foreground">Next run</span><span className="tabular-nums">{data?.schedule?.next_in != null ? fmtSec(data.schedule.next_in) : '—'}</span></div>
            <div className="rounded-lg border p-2 text-xs text-muted-foreground">{data?.schedule?.method ?? 'systemd timer'}</div>
            <div className="text-xs text-muted-foreground">Run <code className="mono">php hiyori schedule --install</code> for units; use ↻ retry to trigger now.</div>
          </CardContent></Card>
        </div>
        <div className="col-span-12 md:col-span-4">
          <Card><CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Slow requests (&gt;2s)</CardTitle></CardHeader><CardContent>
            {!data?.slow?.length ? <EmptyState icon={Clock} title="No slow requests" description="Requests over 2s will appear here." /> : (
              <div className="max-h-48 space-y-1.5 overflow-auto text-xs">
                {data.slow.slice(0, 30).map((s, i) => (
                  <div key={i} className="flex items-center justify-between rounded-md border px-2 py-1">
                    <span className="mr-2 truncate text-muted-foreground">{s.url}</span>
                    <span className="tabular-nums text-amber-500">{s.ms}ms</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent></Card>
        </div>
      </div>
    ),
    merge: (
      <div className="grid grid-cols-12 gap-4">
        <div className="col-span-12 lg:col-span-6">
          <Card><CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Last combine</CardTitle></CardHeader><CardContent>
            {!data?.combine?.strategy ? <EmptyState icon={GitMerge} title="No combine run" description="Run php hiyori combine to populate." /> : (
              <div className="grid grid-cols-2 gap-2 text-sm">
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Strategy</div><div className="font-medium">{data.combine.strategy}</div></div>
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Merges</div><div className="font-medium">{fmt(data.combine.merges)}</div></div>
                {data.combine.scores && <div className="col-span-2 rounded-lg border p-2"><div className="text-xs text-muted-foreground">Score avg/min/max</div><div className="font-medium">{data.combine.scores.avg} / {data.combine.scores.min} / {data.combine.scores.max}</div></div>}
              </div>
            )}
          </CardContent></Card>
        </div>
        <div className="col-span-12 lg:col-span-6">{benchmarkCard}</div>
        <div className="col-span-12 lg:col-span-4">{histCard}</div>
        <div className="col-span-12 lg:col-span-4">{samplerCard}</div>
        <div className="col-span-12 lg:col-span-4">
          <Card><CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Validation feed</CardTitle></CardHeader><CardContent>
            {!data?.validation?.length ? <EmptyState icon={ListChecks} title="All clear" description="No validation violations recorded." /> : (
              <ScrollArea className="max-h-56">
                <div className="space-y-1.5 text-xs">
                  {data.validation.slice(0, 40).map((v, i) => (
                    <div key={i} className="rounded-md border px-2 py-1.5">
                      <span className="text-muted-foreground">[{v.source}]</span> {v.title ?? ''} <span className="text-amber-500">{Object.keys(v.errors ?? {}).join(', ')}</span>
                    </div>
                  ))}
                </div>
              </ScrollArea>
            )}
          </CardContent></Card>
        </div>
      </div>
    ),
  }

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full">
        <Sidebar collapsible="icon">
          <SidebarHeader>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton size="lg">
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                    <Activity className="h-4 w-4" />
                  </div>
                  <div className="grid flex-1 text-left leading-tight">
                    <span className="truncate font-semibold">Hiyori<span className="text-primary">DB</span></span>
                    <span className="truncate text-xs text-muted-foreground">{totalDocs} docs</span>
                  </div>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarHeader>
          <SidebarContent>
            <SidebarGroup>
              <SidebarGroupLabel>Dashboard</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {NAV.map(item => (
                    <SidebarMenuItem key={item.id}>
                      <SidebarMenuButton isActive={view === item.id} onClick={() => setView(item.id)} tooltip={item.label}>
                        <item.icon />
                        <span>{item.label}</span>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          </SidebarContent>
          <SidebarFooter>
            <div className="px-3 py-2 text-xs text-muted-foreground">
              <div className="flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${data?.health?.status === 'healthy' ? 'bg-emerald-500' : 'bg-red-500'}`} />
                {error ? 'offline' : data?.health?.status ?? '…'}
              </div>
            </div>
          </SidebarFooter>
          <SidebarRail />
        </Sidebar>

        <SidebarInset>
          <header className="flex h-14 shrink-0 items-center gap-2 border-b px-5">
            <SidebarTrigger />
            <Separator orientation="vertical" className="h-4" />
            <Breadcrumb>
              <BreadcrumbList>
                <BreadcrumbItem className="hidden md:block"><BreadcrumbPage>Dashboard</BreadcrumbPage></BreadcrumbItem>
                <BreadcrumbSeparator className="hidden md:block" />
                <BreadcrumbItem><BreadcrumbPage className="text-muted-foreground">{NAV.find(n => n.id === view)?.label}</BreadcrumbPage></BreadcrumbItem>
              </BreadcrumbList>
            </Breadcrumb>
            <div className="ml-auto flex items-center gap-2">
              <Button size="sm" variant="outline" title="Refresh" onClick={() => void refreshBench()}><RefreshCw className="h-3.5 w-3.5" /></Button>
              <Button size="sm" variant="outline" title="Download CSV" onClick={() => exportCSV(jobKeys, jobs)}><Download className="h-3.5 w-3.5" /></Button>
              <Button size="sm" variant="outline" title="Toggle timezone" onClick={() => setTz(t => (t === 'local' ? 'utc' : 'local'))}>{tz === 'local' ? '🕐' : 'UTC'}</Button>
              <Button size="sm" variant="outline" title="Toggle y-axis log scale" onClick={() => setLogScale(v => !v)}>log</Button>
              <Button size="sm" variant="outline" title="Toggle area/line" onClick={() => setArea(v => !v)}>{area ? 'area' : 'line'}</Button>
              <Button size="sm" variant="outline" title="Toggle theme" onClick={toggleTheme}>{dark ? <Sun className="h-3.5 w-3.5" /> : <Moon className="h-3.5 w-3.5" />}</Button>
            </div>
          </header>
          <main className="flex-1 p-6">{pages[view]}</main>
        </SidebarInset>
      </div>
    </SidebarProvider>
  )
}
