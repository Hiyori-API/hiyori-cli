import { useCallback, useMemo, useState } from 'react'
import { Bar, BarChart, Line, LineChart, Pie, PieChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis, Legend } from 'recharts'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from 'sonner'
import { usePolling } from '@/lib/hooks'
import { COLORS, MultiLine } from './charts'
import { fetchBenchmark, fetchSample, postAction, runBackup, type BenchmarkRow, type Job } from '@/lib/api'

const CAP = 120
const FULL_COLS = ['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'cr', 'nf', 'sb', 'ttv', 't', 'adb', 'tx', 'b', 'fm', 'yt', 'ap', 'tvt']

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
  if (s < 60) return s + 's ago'
  if (s < 3600) return Math.floor(s / 60) + 'm ago'
  return Math.floor(s / 3600) + 'h ago'
}
function fmtSec(n: number | undefined | null): string {
  if (n === undefined || n === null || !Number.isFinite(n)) return '—'
  if (n < 90) return `${n}s`
  if (n < 5400) return `${Math.round(n / 60)}m`
  return `${Math.round(n / 3600)}h ${Math.round((n % 3600) / 60)}m`
}
function toCSV(rows: Record<string, unknown>[], headings: string[]): void {
  const esc = (v: unknown) => `"${String(v).replace(/"/g, '""')}"`
  const lines = [headings.map(esc).join(',')]
  rows.forEach(r => lines.push(headings.map(h => esc(r[h] ?? '')).join(',')))
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'hiyori-dashboard.csv'
  a.click()
}

export function Dashboard() {
  const { data, error } = usePolling(3000, true)
  const [cap, setCap] = useState(120)
  const [sortKey, setSortKey] = useState<'source' | 'processed' | 'per_second'>('source')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [logScale, setLogScale] = useState(false)
  const [area, setArea] = useState(false)
  const [bench, setBench] = useState<{ seeded?: BenchmarkRow[]; latest?: unknown } | null>(null)
  const [sample, setSample] = useState<Record<string, unknown> | null | undefined>(undefined)
  const [samplerFeed, setSamplerFeed] = useState<{ right: number; wrong: number }>({ right: 0, wrong: 0 })
  const [dismissedAlert, setDismissedAlert] = useState<string | null>(null)

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
    const n = Math.min(cap, history.counts[keys[0]].length)
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = { label: (i * 3) + 's' }
      keys.forEach(k => { row[k] = history.counts[k][history.counts[k].length - n + i] ?? 0 })
      rows.push(row)
    }
    return rows
  }, [history, cap])

  const ratesRows = useMemo(() => {
    const keys = Object.keys(history.rates).sort()
    if (!keys.length) return []
    const n = Math.min(cap, ...Object.values(history.rates).map(a => a.length))
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = { label: (i * 3) + 's' }
      keys.forEach(k => { row[k] = history.rates[k][history.rates[k].length - n + i] ?? 0 })
      rows.push(row)
    }
    return rows
  }, [history, cap])

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

  const sliceDataset = () => {
    const base = Object.keys(data?.counts ?? {}).sort()
    const keys = base.filter(k => !k.includes('_manga'))
    return keys.map((k, i) => ({ name: k, value: data!.counts[k], color: COLORS[i % COLORS.length] }))
  }
  const hasManga = Object.keys(data?.counts ?? {}).some(k => k.includes('_manga'))

  const sampled = (sample as Record<string, unknown> | null | undefined)

  const exportCSV = () => {
    const rows = jobKeys.map(k => ({ source: jobs[k].source, phase: jobs[k].phase, processed: jobs[k].processed, saved: jobs[k].saved, skipped: jobs[k].skipped, errors: jobs[k].errors, speed: jobs[k].per_second }))
    toCSV(rows, ['source', 'phase', 'processed', 'saved', 'skipped', 'errors', 'speed'])
  }

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="sticky top-0 z-20 border-b bg-background/85 backdrop-blur support-[backdrop-filter]:bg-background/60">
        <div className="mx-auto flex max-w-[1520px] items-center gap-4 px-5 py-3">
          <h1 className="text-lg font-bold">Hiyori<span className="text-primary">DB</span> · Live</h1>
          <Badge variant="outline">{data?.health?.status === 'healthy' ? 'healthy' : 'degraded'}</Badge>
          <span className="ml-auto text-xs tabular-nums text-muted-foreground">
            {error ? 'reconnecting…' : data ? `updated ${new Date(data.timestamp).toLocaleTimeString()}` : 'connecting…'}
          </span>
        </div>
      </header>

      <main className="mx-auto grid max-w-[1520px] grid-cols-12 gap-4 px-5 py-6">
        {/* ALERTS */}
        {alerts.filter(a => a.msg !== dismissedAlert).map((a, i) => (
          <div key={i} className={`col-span-12 rounded-lg border px-4 py-2 text-sm ${a.level === 'critical' ? 'border-red-500/50 bg-red-500/10 text-red-500' : 'border-amber-500/50 bg-amber-500/10 text-amber-500'}`}>
            ⚠ {a.msg}
            <button className="ml-3 text-xs underline" onClick={() => setDismissedAlert(a.msg ?? null)}>dismiss</button>
          </div>
        ))}

        {/* TOOLBAR */}
        <div className="col-span-12 flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">window</span>
          {[['1m', 20], ['5m', 100], ['1h', 120]].map(([l, c]) => (
            <Button key={l as string} size="sm" variant={cap === c ? 'default' : 'outline'} onClick={() => setCap(c as number)}>{l}</Button>
          ))}
          <span className="grow" />
          <span className="text-xs text-muted-foreground">y-axis</span>
          <Button size="sm" variant={logScale ? 'default' : 'outline'} onClick={() => setLogScale(v => !v)}>log</Button>
          <Button size="sm" variant={area ? 'default' : 'outline'} onClick={() => setArea(v => !v)}>area</Button>
          <Select value={sortKey} onValueChange={v => setSortKey(v as typeof sortKey)}>
            <SelectTrigger className="h-8 w-36"><SelectValue placeholder="sort" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="source">Source</SelectItem>
              <SelectItem value="processed">Processed</SelectItem>
              <SelectItem value="per_second">Speed</SelectItem>
            </SelectContent>
          </Select>
          <Button size="sm" variant="outline" onClick={() => setSortDir(d => d === 'asc' ? 'desc' : 'asc')}>{sortDir === 'asc' ? '↑' : '↓'}</Button>
          <Button size="sm" variant="outline" onClick={exportCSV}>⬇ CSV</Button>
          <Button size="sm" variant="outline" onClick={() => void refreshBench()}>refresh</Button>
        </div>

        {/* ALERTS: JOBS */}
        <Card className="col-span-12 lg:col-span-8">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Ingestion jobs</CardTitle></CardHeader>
          <CardContent className="p-0">
            {error && <p className="px-4 py-2 text-xs text-red-500">backend unreachable — start the dashboard server</p>}
            {!data && !error && <div className="space-y-2 px-4 py-3"><Skeleton className="h-8 w-full" /><Skeleton className="h-8 w-full" /></div>}
            {data && jobKeys.length === 0 && <p className="px-4 py-3 text-xs text-muted-foreground">No job telemetry yet — start/restart an ingestion.</p>}
            {data && jobKeys.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="text-left text-xs uppercase text-muted-foreground">
                    <tr><th className="px-4 py-2">Source</th><th className="px-2 py-2">Status</th><th className="px-2 py-2">Progress</th><th className="px-2 py-2 text-right">Speed</th><th className="px-2 py-2 text-right">Pending</th><th className="px-2 py-2 text-right">Saved/Skip</th><th className="px-2 py-2 text-right">ETA</th></tr>
                  </thead>
                  <tbody>
                    {sortedJobs.map(({ key, job }) => {
                      const pct = job.total ? Math.min(100, ((job.processed ?? 0) / job.total) * 100) : 0
                      const pending = Math.max(0, (job.total ?? 0) - (job.processed ?? 0))
                      return (
                        <tr key={key} className="border-t">
                          <td className="px-4 py-2">
                            <div className="flex items-center gap-2">
                              <span>{job.source}</span>
                              <Button size="sm" variant="ghost" className="h-6 px-1 text-[10px]" onClick={async () => { await postAction(String(job.source), 'pause'); toast(`${job.source} paused`) }}>⏸</Button>
                              <Button size="sm" variant="ghost" className="h-6 px-1 text-[10px]" onClick={async () => { await postAction(String(job.source), 'resume'); toast(`${job.source} resumed`) }}>▶</Button>
                              <Button size="sm" variant="ghost" className="h-6 px-1 text-[10px]" onClick={async () => { await postAction(String(job.source), 'retry'); toast(`${job.source} retry scheduled`) }}>↻</Button>
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
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* DATASET */}
        <Card className="col-span-12 lg:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Dataset · {fmt(totalDocs)}{hasManga ? ' · manga +' : ''}</CardTitle></CardHeader>
          <CardContent>
            {data && (
              <>
                <ResponsiveContainer width="100%" height={150}>
                  <PieChart>
                    <Pie data={sliceDataset()} dataKey="value" nameKey="name" innerRadius={50} outerRadius={70} paddingAngle={2} strokeWidth={0}>
                      {sliceDataset().map(e => <Cell key={e.name} fill={e.color} />)}
                    </Pie>
                    <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="mt-2 space-y-1 text-sm">
                  {Object.entries(data.counts).sort().map(([k, v]) => (
                    <div key={k} className="flex items-center justify-between">
                      <span className="flex items-center gap-2 text-muted-foreground"><span className="h-2 w-2 rounded-full" style={{ background: COLORS[Object.keys(data.counts).sort().indexOf(k) % COLORS.length] }} />{k}</span>
                      <span className="tabular-nums">{fmt(v)}</span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* RATES */}
        <Card className="col-span-12 lg:col-span-6">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Ingestion rate (entries/s) · {area ? 'area' : 'line'}</CardTitle></CardHeader>
          <CardContent>
            <div className="h-56">
              {ratesRows.length ? (
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={ratesRows} margin={{ top: 6, right: 8, bottom: 0, left: -14 }}>
                    <XAxis dataKey="label" tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} minTickGap={40} />
                    <YAxis scale={logScale ? 'log' : 'auto'} allowDataOverflow={false} tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }} tickLine={false} axisLine={false} width={44} />
                    <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} />
                    <Legend wrapperStyle={{ fontSize: 11 }} />
                    {Object.keys(history.rates).sort().map((k, i) =>
                      area
                        ? <Line key={k} type="monotone" dataKey={k.replace('ingest-', '')} stroke={COLORS[i % COLORS.length]} fill={COLORS[i % COLORS.length]} fillOpacity={0.15} dot={false} strokeWidth={2} />
                        : <Line key={k} type="monotone" dataKey={k.replace('ingest-', '')} stroke={COLORS[i % COLORS.length]} dot={false} strokeWidth={2} />
                    )}
                  </LineChart>
                </ResponsiveContainer>
              ) : <p className="text-xs text-muted-foreground">no rate history yet</p>}
            </div>
          </CardContent>
        </Card>

        {/* COUNTS */}
        <Card className="col-span-12 lg:col-span-6">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Collection counts</CardTitle></CardHeader>
          <CardContent><div className="h-56">{countsRows.length ? <MultiLine rows={countsRows} series={Object.keys(history.counts).sort().map((k, i) => ({ key: k, color: COLORS[i % COLORS.length] }))} /> : <p className="text-xs text-muted-foreground">no history yet</p>}</div></CardContent>
        </Card>

        {/* PIPELINE DAG */}
        <Card className="col-span-12">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Pipeline</CardTitle></CardHeader>
          <CardContent>
            <div className="flex flex-wrap items-center gap-2">
              {['Ingest', 'Validate', 'Combine', 'Export'].map((stage, i) => (
                <div key={stage} className="flex items-center gap-2">
                  <div className={`rounded-lg border px-4 py-2 text-sm ${i === 0 ? 'border-primary/40' : ''}`}>
                    <div className="flex items-center gap-2">
                      <span className={`h-2 w-2 rounded-full ${i === 0 && jobKeys.length ? 'bg-emerald-500' : 'bg-muted'}`} />
                      {stage}
                    </div>
                  </div>
                  {i < 3 && <span className="text-muted-foreground">→</span>}
                </div>
              ))}
              <span className="ml-auto text-xs text-muted-foreground">
                {jobKeys.length ? `${jobKeys.length} active source(s)` : 'idle'}
                {' · '}
                {((data?.validation?.length ?? 0) > 0) ? `${data?.validation?.length} recent violations` : 'validation clean'}
                {' · '}
                {data?.combine?.strategy ? `last combine: ${data.combine.strategy}` : 'no combine yet'}
              </span>
            </div>
          </CardContent>
        </Card>

        {/* COVERAGE MATRIX */}
        <Card className="col-span-12 lg:col-span-6">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Reference coverage matrix</CardTitle></CardHeader>
          <CardContent className="overflow-x-auto">
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
                    <td className="px-1 py-1">{name} <span className="text-muted-foreground">({fmt(row.total)})</span></td>
                    {FULL_COLS.map(s => {
                      const v = row[s] as number
                      const pct = row.total ? (v / row.total) : 0
                      return <td key={s} className="px-1 py-1 text-center tabular-nums" title={fmt(v)}>
                        <span className={`rounded px-1 ${pct > 0.5 ? 'bg-emerald-500/20 text-emerald-500' : pct > 0 ? 'bg-amber-500/20 text-amber-500' : 'text-muted-foreground'}`}>{pct > 0 ? (pct * 100).toFixed(0) : '·'}</span>
                      </td>
                    })}
                  </tr>
                ))}
                {Object.keys(coverage).length === 0 && <tr><td className="py-2 text-muted-foreground">no collections</td></tr>}
              </tbody>
            </table>
            <p className="mt-2 text-[10px] text-muted-foreground">% of docs carrying each reference shorthand (cell = coverage %)</p>
          </CardContent>
        </Card>

        {/* BENCHMARK */}
        <Card className="col-span-12 lg:col-span-6">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Strategy benchmark</CardTitle></CardHeader>
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
                      <span className="tabular-nums">{fmt(b.merges)} merges · {Math.round(b.wall_s ?? 0 / 60)}m wall · {b.speed}/s</span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* MERGE SAMPLER */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader>
            <CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Merge sampler</CardTitle>
          </CardHeader>
          <CardContent>
            {sampled === undefined ? (
              <Button size="sm" onClick={async () => setSample((await fetchSample('organized')).sample ?? null)}>Draw a sample</Button>
            ) : sampled === null ? (
              <p className="text-sm text-muted-foreground">organized is empty</p>
            ) : (
              <div className="space-y-2 text-sm">
                <div className="rounded-lg border p-2">
                  <div className="font-medium">{(sampled as { title?: string }).title ?? '(no title)'}</div>
                  <div className="text-xs text-muted-foreground">
                    refs: {Object.keys(((sampled as { reference_ids?: Record<string, string> }).reference_ids) ?? {}).join(', ') || 'none'}
                  </div>
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

        {/* DB + BACKUP */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Database & backups</CardTitle></CardHeader>
          <CardContent>
            {!data?.db ? <p className="text-sm text-muted-foreground">no db stats</p> : (
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
              <Button size="sm" variant="outline" className="w-full" onClick={async () => { await runBackup(); toast('backup started') }}>Backup now</Button>
            </div>
          </CardContent>
        </Card>

        {/* CONFIDENCE HISTOGRAM */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Merge confidence histogram</CardTitle></CardHeader>
          <CardContent>
            {!data?.combine?.score_histogram ? <p className="text-sm text-muted-foreground">run metadata_fuzzy to populate</p> : (
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

        {/* HEALTH */}
        <Card className="col-span-12 md:col-span-4">
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

        {/* COMBINE */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Last combine</CardTitle></CardHeader>
          <CardContent>
            {!data?.combine?.strategy ? <p className="text-sm text-muted-foreground">No combine run recorded yet.</p> : (
              <div className="grid grid-cols-2 gap-2 text-sm">
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Strategy</div><div className="font-medium">{data.combine.strategy}</div></div>
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Merges</div><div className="font-medium">{fmt(data.combine.merges)}</div></div>
                {data.combine.scores && <div className="col-span-2 rounded-lg border p-2"><div className="text-xs text-muted-foreground">Score avg/min/max</div><div className="font-medium">{data.combine.scores.avg} / {data.combine.scores.min} / {data.combine.scores.max}</div></div>}
              </div>
            )}
          </CardContent>
        </Card>

        {/* VALIDATION */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Validation feed</CardTitle></CardHeader>
          <CardContent>
            {!data?.validation?.length ? <p className="text-sm text-muted-foreground">No violations.</p> : (
              <div className="max-h-56 space-y-1.5 overflow-auto text-xs">
                {data.validation.slice(0, 40).map((v, i) => (
                  <div key={i} className="rounded-md border px-2 py-1.5">
                    <span className="text-muted-foreground">[{v.source}]</span> {v.title ?? ''} <span className="text-amber-500">{Object.keys(v.errors ?? {}).join(', ')}</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </main>
    </div>
  )
}
