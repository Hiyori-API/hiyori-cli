import { useEffect, useMemo, useState } from 'react'
import { BarChart4, Clock, DatabaseZap, History, ListChecks, RefreshCw } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { fetchFailures, fetchHistory, type Job, type ValidationEvent } from '@/lib/api'
import { useSmartPolling } from '@/lib/hooks'
import { EmptyState } from '../empty'
import { PageHead, Section } from '../widgets'

function timeStr(ts: string | undefined): string {
  if (!ts) return '—'
  const t = new Date(ts)
  return Number.isNaN(t.getTime()) ? ts : t.toLocaleTimeString()
}

export function LogsPage() {
  const { data, error } = useSmartPolling(8000, true)
  const [failures, setFailures] = useState<ValidationEvent[]>([])
  const [history, setHistory] = useState<Record<string, Job> | null>(null)
  const [srcFilter, setSrcFilter] = useState('all')
  const [slowSort, setSlowSort] = useState<'ms' | 'ts'>('ms')

  useEffect(() => {
    void fetchFailures().then(r => setFailures(r.failures ?? []))
    void fetchHistory().then(r => setHistory(r.states ?? null))
  }, [])

  const slow = (data?.slow ?? []).slice().sort((a, b) => (slowSort === 'ms' ? (b.ms ?? 0) - (a.ms ?? 0) : String(b.ts).localeCompare(String(a.ts))))
  const validation = useMemo(() => {
    const list = failures.slice().sort((a, b) => String(b.ts).localeCompare(String(a.ts)))
    return srcFilter === 'all' ? list : list.filter(f => f.source === srcFilter)
  }, [failures, srcFilter])
  const sources = useMemo(() => Array.from(new Set(failures.map(f => f.source ?? '?'))).sort(), [failures])

  const latBins = useMemo(() => {
    const buckets = { '2–3s': 0, '3–5s': 0, '5–10s': 0, '10s+': 0 }
    ;(data?.slow ?? []).forEach(s => {
      const ms = s.ms ?? 0
      if (ms < 3000) buckets['2–3s']++
      else if (ms < 5000) buckets['3–5s']++
      else if (ms < 10000) buckets['5–10s']++
      else buckets['10s+']++
    })
    return Object.entries(buckets)
  }, [data])

  const runs = useMemo(() => {
    if (!history) return []
    return Object.entries(history)
      .filter(([k]) => k.startsWith('ingest-'))
      .map(([k, j]) => ({ key: k, job: j }))
      .sort((a, b) => (b.job.started_at_ts ?? 0) - (a.job.started_at_ts ?? 0))
  }, [history])

  const cache = data?.cache

  return (
    <div className="space-y-4">
      <PageHead
        title="Logs"
        desc="Slow requests, validation issues, ingestion run history and cache."
        actions={<Button size="sm" variant="outline" onClick={() => window.location.reload()}><RefreshCw className="mr-1.5 h-3.5 w-3.5" />Reload</Button>}
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Slow requests" icon={Clock} badge={<Badge variant="outline">{slow.length}</Badge>}>
          {error && !data ? <EmptyState icon={Clock} title="Backend offline" description="Start the dashboard server." /> :
            slow.length === 0 ? <EmptyState icon={Clock} title="No slow requests" description="Requests over 2 seconds appear here." /> : (
              <div className="max-h-72 overflow-auto">
                <table className="w-full text-xs">
                  <thead className="text-left text-muted-foreground">
                    <tr>
                      <th className="px-2 py-1.5">
                        <button onClick={() => setSlowSort(s => (s === 'ms' ? 'ts' : 'ms'))}>ms {slowSort === 'ms' ? '↓' : ''}</button>
                      </th>
                      <th className="px-2 py-1.5">method</th>
                      <th className="px-2 py-1.5">url</th>
                      <th className="px-2 py-1.5">time</th>
                    </tr>
                  </thead>
                  <tbody>
                    {slow.slice(0, 40).map((s, i) => (
                      <tr key={i} className="border-t">
                        <td className="px-2 py-1 tabular-nums text-amber-500">{s.ms}ms</td>
                        <td className="px-2 py-1"><Badge variant="outline">{s.method ?? 'GET'}</Badge></td>
                        <td className="max-w-[260px] truncate px-2 py-1 text-muted-foreground">{s.url}</td>
                        <td className="px-2 py-1 text-muted-foreground">{timeStr(s.ts)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
        </Section>

        <Section title="Latency buckets" icon={BarChart4}>
          {latBins.every(([, n]) => n === 0) ? (
            <EmptyState icon={BarChart4} title="Nothing over 2s" description="Latency distribution of slow requests fills in as they occur." />
          ) : (
            <div className="space-y-1.5">
              {latBins.map(([label, n]) => (
                <div key={label} className="flex items-center gap-2 text-xs">
                  <span className="w-14 text-muted-foreground">{label}</span>
                  <div className="h-3 flex-1 rounded bg-muted"><div className="h-full rounded bg-amber-500/70" style={{ width: `${Math.min(100, (n / Math.max(1, ...latBins.map(b => b[1]))) * 100)}%` }} /></div>
                  <span className="tabular-nums text-muted-foreground">{n}</span>
                </div>
              ))}
              <p className="pt-1 text-[10px] text-muted-foreground">p95/p99 time-series requires per-request metric logging; volume buckets use the slow-request feed for now.</p>
            </div>
          )}
        </Section>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Validation log" icon={ListChecks} badge={<Badge variant="outline">{validation.length}</Badge>}>
          <div className="mb-2 flex items-center justify-end">
            <Select value={srcFilter} onValueChange={v => setSrcFilter(v ?? 'all')}>
              <SelectTrigger className="h-8 w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All sources</SelectItem>
                {sources.map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          {validation.length === 0 ? (
            <EmptyState icon={ListChecks} title="All clear" description="No validation violations recorded." />
          ) : (
            <div className="max-h-72 space-y-1.5 overflow-auto">
              {validation.slice(0, 60).map((v, i) => (
                <div key={i} className="rounded-lg border px-2 py-1.5 text-xs">
                  <div className="flex items-center gap-2">
                    <span className="text-muted-foreground tabular-nums">{timeStr(v.ts)}</span>
                    <Badge variant="outline">{v.source}</Badge>
                    <span className="flex-1 truncate">{v.title ?? ''}</span>
                  </div>
                  <div className="mt-0.5 text-amber-500">{Object.keys(v.errors ?? {}).join(', ')}</div>
                </div>
              ))}
            </div>
          )}
        </Section>

        <Section title="Ingestion run history" icon={History}>
          {!history ? <Skeleton className="h-32 w-full" /> : runs.length === 0 ? (
            <EmptyState icon={History} title="No runs yet" description="Started/finished ingestion runs will be listed here." />
          ) : (
            <div className="max-h-72 overflow-auto">
              <table className="w-full text-xs">
                <thead className="text-left text-muted-foreground">
                  <tr>
                    <th className="px-2 py-1.5">Source</th>
                    <th className="px-2 py-1.5">Phase</th>
                    <th className="px-2 py-1.5 text-right">Processed</th>
                    <th className="px-2 py-1.5 text-right">Saved/Skip</th>
                    <th className="px-2 py-1.5 text-right">Rate</th>
                    <th className="px-2 py-1.5">Started</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.slice(0, 30).map(({ key, job }) => (
                    <tr key={key} className="border-t">
                      <td className="px-2 py-1">{job.source ?? key}</td>
                      <td className="px-2 py-1"><Badge variant={job.phase === 'done' ? 'secondary' : 'outline'}>{job.phase ?? 'idle'}</Badge></td>
                      <td className="px-2 py-1 text-right tabular-nums">{job.processed?.toLocaleString() ?? '—'}</td>
                      <td className="px-2 py-1 text-right tabular-nums">{job.saved?.toLocaleString() ?? '—'}/{job.skipped?.toLocaleString() ?? '—'}</td>
                      <td className="px-2 py-1 text-right tabular-nums">{job.per_second?.toFixed(1) ?? '—'}</td>
                      <td className="px-2 py-1 text-muted-foreground">{job.started_at_ts ? new Date(job.started_at_ts * 1000).toLocaleString() : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Section>
      </div>

      <Section title="Cache & request counters" icon={DatabaseZap}>
        {!cache ? (
          <EmptyState icon={DatabaseZap} title="Cache telemetry unavailable" description="Cache counters appear once requests flow." />
        ) : (
          <div className="grid grid-cols-2 gap-2 text-sm lg:grid-cols-4">
            <div className="rounded-lg border p-3"><div className="text-xs text-muted-foreground">Requests</div><div className="font-medium tabular-nums">{cache.requests?.toLocaleString() ?? '—'}</div></div>
            <div className="rounded-lg border p-3"><div className="text-xs text-muted-foreground">Enabled</div><div className="font-medium">{cache.enabled ? 'yes' : 'no'}</div></div>
            <div className="rounded-lg border p-3"><div className="text-xs text-muted-foreground">Host</div><div className="font-medium">{cache.host ?? '—'}</div></div>
            <div className="rounded-lg border p-3"><div className="text-xs text-muted-foreground">Slow p95</div><div className="font-medium tabular-nums">{data?.slow?.[0]?.ms ? `${data.slow[0].ms}ms` : '—'}</div></div>
          </div>
        )}
      </Section>
    </div>
  )
}
