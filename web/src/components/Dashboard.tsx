import { useMemo, useState } from 'react'
import { Pie, PieChart, ResponsiveContainer, Cell, Tooltip } from 'recharts'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from 'sonner'
import { usePolling } from '@/lib/hooks'
import { COLORS, MultiLine } from './charts'
import { postAction, runBackup, type Job } from '@/lib/api'

const CAP = 120

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

export function Dashboard() {
  const { data, error } = usePolling(3000, true)
  const [cap, setCap] = useState(120)
  const [sortKey, setSortKey] = useState<'source' | 'processed' | 'per_second'>('source')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')

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
  }, [data]) // eslint-disable-line react-hooks/exhaustive-deps

  const countsRows = useMemo(() => {
    if (!history) return []
    const first = Object.keys(history.counts).sort()
    if (!first.length) return []
    const n = Math.min(cap, history.counts[first[0]].length)
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = {
        label: new Date(Date.now() - (n - 1 - i) * 3000).toLocaleTimeString([], { minute: '2-digit', second: '2-digit' }),
      }
      first.forEach(k => { row[k] = history.counts[k][history.counts[k].length - n + i] ?? 0 })
      rows.push(row)
    }
    return rows
  }, [history, cap])

  const ratesRows = useMemo(() => {
    const keys = Object.keys(history.rates).sort()
    if (!keys.length) return []
    const n = Math.min(cap, ...Object.values(history.rates).map(a => a.length))
    if (n <= 0) return []
    const rows: { label: string; [k: string]: string | number }[] = []
    for (let i = 0; i < n; i++) {
      const row: { label: string; [k: string]: string | number } = {
        label: new Date(Date.now() - (n - 1 - i) * 3000).toLocaleTimeString([], { minute: '2-digit', second: '2-digit' }),
      }
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
      const av = a.job[sortKey] ?? 0
      const bv = b.job[sortKey] ?? 0
      return typeof av === 'number' && typeof bv === 'number' ? (av - bv) * dir : String(a.job.source).localeCompare(String(b.job.source)) * dir
    })
  }, [jobs, jobKeys, sortKey, sortDir])

  const slice = (names: string[]) => {
    const base = Object.keys(data?.counts ?? {}).sort()
    const keys = names.length ? names.filter(n => base.includes(n)) : base
    return keys.map((k, i) => ({ name: k, value: data!.counts[k], color: COLORS[i % COLORS.length] }))
  }

  const h = data?.health
  const healthy = h?.status === 'healthy'

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="sticky top-0 z-20 border-b bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        <div className="mx-auto flex max-w-[1500px] items-center gap-4 px-5 py-3">
          <h1 className="text-lg font-bold tracking-tight">Hiyori<span className="text-primary">DB</span> · Live</h1>
          <Badge variant="outline" className={healthy ? 'border-emerald-500/40 text-emerald-500' : 'border-red-500/40 text-red-500'}>
            <span className={`mr-1.5 h-2 w-2 rounded-full ${healthy ? 'bg-emerald-500' : 'bg-red-500'}`} />
            {healthy ? 'live' : 'degraded'}
          </Badge>
          <span className="ml-auto text-xs tabular-nums text-muted-foreground">{error ? 'reconnecting…' : data ? `updated ${new Date(data.timestamp).toLocaleTimeString()}` : 'connecting…'}</span>
        </div>
      </header>

      <main className="mx-auto grid max-w-[1500px] grid-cols-12 gap-4 px-5 py-6">
        {/* RANGE PRESETS */}
        <div className="col-span-12 flex items-center gap-2">
          <span className="text-xs text-muted-foreground">window</span>
          {[['1m', 20], ['5m', 100], ['1h', 120, 3600]].map(([l, c]) => (
            <Button key={l as string} size="sm" variant={cap === c ? 'default' : 'outline'} onClick={() => setCap(c as number)}>{l}</Button>
          ))}
          <span className="grow" />
          <Select value={sortKey} onValueChange={v => setSortKey(v as typeof sortKey)}>
            <SelectTrigger className="h-8 w-36"><SelectValue placeholder="sort jobs" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="source">Source</SelectItem>
              <SelectItem value="processed">Processed</SelectItem>
              <SelectItem value="per_second">Speed</SelectItem>
            </SelectContent>
          </Select>
          <Button size="sm" variant="outline" onClick={() => setSortDir(d => d === 'asc' ? 'desc' : 'asc')}>
            {sortDir === 'asc' ? '↑' : '↓'}
          </Button>
        </div>

        {/* JOBS */}
        <Card className="col-span-12 lg:col-span-8">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Ingestion jobs</CardTitle></CardHeader>
          <CardContent className="p-0">
            {error && <p className="px-4 py-2 text-xs text-red-500">backend unreachable — start the dashboard server</p>}
            {!data && !error ? <div className="space-y-2 px-4 py-3"><Skeleton className="h-8 w-full" /><Skeleton className="h-8 w-full" /><Skeleton className="h-8 w-full" /></div> : null}
            {data && jobKeys.length === 0 && <p className="px-4 py-3 text-xs text-muted-foreground">No job telemetry yet — start/restart an ingestion to see live progress.</p>}
            {data && jobKeys.length > 0 && (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="text-left text-xs uppercase text-muted-foreground">
                    <tr><th className="px-4 py-2">Source</th><th className="px-2 py-2">Status</th><th className="px-2 py-2">Progress</th><th className="px-2 py-2 text-right">Speed</th><th className="px-2 py-2 text-right">Saved</th><th className="px-2 py-2 text-right">ETA</th><th className="px-4 py-2 text-right">Errors</th></tr>
                  </thead>
                  <tbody>
                    {sortedJobs.map(({ key, job }) => {
                      const pct = job.total ? Math.min(100, ((job.processed ?? 0) / job.total) * 100) : 0
                      const badge = job.phase === 'done' ? 'secondary' : job.phase === 'running' ? 'green' : 'red'
                      return (
                        <tr key={key} className="border-t">
                          <td className="px-4 py-2">
                            <div className="flex items-center gap-2">
                              <span>{job.source}</span>
                              <button
                                className="rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-accent"
                                onClick={async () => {
                                  const r = await postAction(String(job.source), 'pause')
                                  toast(r?.error ? `error: ${r.error}` : `${job.source} paused`)
                                }}
                              >⏸</button>
                              <button
                                className="rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-accent"
                                onClick={async () => {
                                  const r = await postAction(String(job.source), 'resume')
                                  toast(r?.error ? `error: ${r.error}` : `${job.source} resumed`)
                                }}
                              >▶</button>
                            </div>
                          </td>
                          <td className="px-2 py-2"><Badge variant={badge === 'green' ? 'outline' : badge === 'red' ? 'destructive' : 'secondary'}>{job.phase ?? 'idle'}</Badge></td>
                          <td className="px-2 py-2">
                            <div className="flex items-center gap-2">
                              <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><div className="h-full bg-primary" style={{ width: `${pct.toFixed(1)}%` }} /></div>
                              <span className="text-xs text-muted-foreground">{fmt(job.processed)}/{job.total ?? '?'}</span>
                            </div>
                          </td>
                          <td className="px-2 py-2 text-right tabular-nums">{job.per_second?.toFixed(1) ?? '—'}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{fmt(job.saved)}</td>
                          <td className="px-2 py-2 text-right tabular-nums">{fmtSec(job.eta_seconds)}</td>
                          <td className="px-4 py-2 text-right tabular-nums">{fmt(job.errors)}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* DATASET SPLIT */}
        <Card className="col-span-12 lg:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Dataset · {fmt(totalDocs)}</CardTitle></CardHeader>
          <CardContent>
            {data && (
              <>
                <ResponsiveContainer width="100%" height={170}>
                  <PieChart>
                    <Pie data={slice([])} dataKey="value" nameKey="name" innerRadius={55} outerRadius={78} paddingAngle={2} strokeWidth={0}>
                      {slice([]).map(e => <Cell key={e.name} fill={e.color} />)}
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
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Ingestion rate (entries/s)</CardTitle></CardHeader>
          <CardContent><div className="h-56">{ratesRows.length ? <MultiLine rows={ratesRows} series={Object.keys(history.rates).sort().map((k, i) => ({ key: k.replace('ingest-', ''), color: COLORS[i % COLORS.length] }))} /> : <p className="text-xs text-muted-foreground">no rate history yet</p>}</div></CardContent>
        </Card>

        {/* COUNTS TIME SERIES */}
        <Card className="col-span-12 lg:col-span-6">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Collection counts</CardTitle></CardHeader>
          <CardContent><div className="h-56">{countsRows.length ? <MultiLine rows={countsRows} series={Object.keys(history.counts).sort().map((k, i) => ({ key: k, color: COLORS[i % COLORS.length] }))} /> : <p className="text-xs text-muted-foreground">no history yet</p>}</div></CardContent>
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
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Base</div><div className="font-medium">{data.combine.base}</div></div>
                <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Merges</div><div className="font-medium">{fmt(data.combine.merges)}</div></div>
                {data.combine.scores && <div className="rounded-lg border p-2"><div className="text-xs text-muted-foreground">Score avg</div><div className="font-medium">{data.combine.scores.avg}</div></div>}
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
              <Button size="sm" variant="outline" className="w-full" disabled={!data} onClick={async () => { await runBackup(); toast('backup started'); }}>
                Backup now
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* CONFIDENCE HISTOGRAM */}
        <Card className="col-span-12 md:col-span-4">
          <CardHeader><CardTitle className="text-sm uppercase tracking-wide text-muted-foreground">Merge confidence histogram</CardTitle></CardHeader>
          <CardContent>
            {!data?.combine?.score_histogram ? <p className="text-sm text-muted-foreground">run metadata_fuzzy to populate</p> : (
              <div className="flex h-36 items-end gap-2">
                {([['70-80', data.combine.score_histogram.p70_80], ['80-90', data.combine.score_histogram.p80_90], ['90-100', data.combine.score_histogram.p90_100]] as const).map(([label, v]) => {
                  const max = Math.max(data.combine.score_histogram!.p70_80 ?? 1, data.combine.score_histogram!.p80_90 ?? 1, data.combine.score_histogram!.p90_100 ?? 1)
                  return (
                    <div key={label} className="flex flex-1 flex-col items-center gap-1">
                      <span className="text-xs tabular-nums">{v ?? 0}</span>
                      <div className="w-full rounded-t-md bg-gradient-to-t from-primary to-primary/40" style={{ height: `${(((v ?? 0) / max) * 100).toFixed(0)}%` }} />
                      <span className="text-[10px] text-muted-foreground">{label}</span>
                    </div>
                  )
                })}
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
                    <span className="float-right tabular-nums text-muted-foreground">{v.ts ? new Date(v.ts).toLocaleTimeString() : ''}</span>
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
