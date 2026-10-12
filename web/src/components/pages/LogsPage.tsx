import { useEffect, useMemo, useState } from 'react'
import { BarChart4, Clock, DatabaseZap, History, ListChecks, RefreshCw } from 'lucide-react'
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { fetchHistory, type Job, type SlowEntry, type ValidationEvent } from '@/lib/api'
import { useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { timeStr } from '@/lib/format'
import { useFailures } from '@/lib/fetch-cache'
import { tzDateTime, type Tz } from '@/lib/time'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'
import { PageHead, Section } from '../widgets'

export function LogsPage() {
  const { data, error } = useStatus()
  const { settings } = useSettings()
  const tz: Tz = settings.prefs?.tz === 'utc' ? 'utc' : 'local'
  const { failures } = useFailures()
  const [history, setHistory] = useState<Record<string, Job> | null>(null)
  const [srcFilter, setSrcFilter] = useState('all')

  useEffect(() => {
    void fetchHistory().then(r => setHistory(r.states ?? null))
  }, [])

  const slow = useMemo(() => data?.slow ?? [], [data])
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

  const runRows = useMemo(() => {
    if (!history) return []
    return Object.entries(history)
      .filter(([k]) => k.startsWith('ingest-'))
      .map(([k, job]) => ({ key: k, job }))
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
              <SlowTable slow={slow} tz={tz} />
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
            <ValidationTable rows={validation} tz={tz} />
          )}
        </Section>

        <Section title="Ingestion run history" icon={History}>
          {!history ? <Skeleton className="h-32 w-full" /> : runRows.length === 0 ? (
            <EmptyState icon={History} title="No runs yet" description="Started/finished ingestion runs will be listed here." />
          ) : (
            <RunHistoryTable rows={runRows} tz={tz} />
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
            <div className="rounded-lg border p-3"><div className="text-xs text-muted-foreground">Max slow ms</div><div className="font-medium tabular-nums">{data?.slow?.length ? `${Math.max(...data.slow.map(s => s.ms ?? 0))}ms` : '—'}</div></div>
          </div>
        )}
      </Section>
    </div>
  )
}

interface RunRow { key: string; job: Job }

const runHelper = createColumnHelper<DataTableFeatures, RunRow>()

type ValidationRow = ValidationEvent & { key: string }

function ValidationTable({ rows, tz }: { rows: ValidationEvent[]; tz: Tz }) {
  const data: ValidationRow[] = useMemo(
    () => rows.map((v, i) => ({ ...v, key: `${v.ts}-${i}` })),
    [rows],
  )
  const columns = useMemo<ColumnDef<DataTableFeatures, ValidationRow, any>[]>(() => [
    {
      id: 'ts',
      header: 'Time',
      accessorFn: r => r.ts ?? '',
      cell: info => <span className="tabular-nums text-muted-foreground">{timeStr(info.row.original.ts, tz)}</span>,
      sortingFn: 'basic',
      meta: { align: 'left' } as const,
    },
    {
      id: 'source',
      header: 'Source',
      accessorFn: r => r.source ?? '?',
      cell: info => <Badge variant="outline">{info.row.original.source ?? '?'}</Badge>,
      sortingFn: 'basic',
      meta: { align: 'left' } as const,
    },
    {
      id: 'title',
      header: 'Title',
      accessorFn: r => r.title ?? '',
      cell: info => <span className="truncate">{info.row.original.title ?? ''}</span>,
      sortingFn: 'basic',
      meta: { align: 'left' } as const,
    },
    {
      id: 'errors',
      header: 'Errors',
      accessorFn: r => Object.keys(r.errors ?? {}).join(', '),
      cell: info => <span className="text-amber-500">{Object.keys(info.row.original.errors ?? {}).join(', ')}</span>,
      sortingFn: 'basic',
      meta: { align: 'left' } as const,
    },
  ], [tz])
  return (
    <DataTable
      columns={columns}
      data={data}
      getRowId={r => r.key}
      initialSorting={[{ id: 'ts', desc: true }]}
      initialPageSize={10}
      sortDescFirst
      maxHeight="18rem"
      ariaLabel="Validation violations"
    />
  )
}

function RunHistoryTable({ rows, tz }: { rows: RunRow[]; tz: Tz }) {
  const columns = useMemo<ColumnDef<DataTableFeatures, RunRow, any>[]>(() => [
    runHelper.accessor('key', {
      id: 'source',
      header: 'Source',
      cell: ({ row }) => row.original.job.source ?? row.original.key,
    }),
    runHelper.accessor('key', {
      id: 'phase',
      header: 'Phase',
      cell: ({ row }) => <Badge variant={row.original.job.phase === 'done' ? 'secondary' : 'outline'}>{row.original.job.phase ?? 'idle'}</Badge>,
    }),
    runHelper.accessor(r => r.job.processed ?? 0, {
      id: 'processed',
      header: 'Processed',
      sortFn: 'basic',
      meta: { align: 'right' },
      cell: ({ row }) => <span className="tabular-nums">{row.original.job.processed?.toLocaleString() ?? '—'}</span>,
    }),
    runHelper.accessor(r => r.job.saved ?? 0, {
      id: 'saved',
      header: 'Saved/Skip',
      sortFn: 'basic',
      meta: { align: 'right' },
      cell: ({ row }) => (
        <span className="tabular-nums">
          {row.original.job.saved?.toLocaleString() ?? '—'}/{row.original.job.skipped?.toLocaleString() ?? '—'}
        </span>
      ),
    }),
    runHelper.accessor(r => r.job.per_second ?? 0, {
      id: 'rate',
      header: 'Rate',
      sortFn: 'basic',
      meta: { align: 'right' },
      cell: ({ row }) => <span className="tabular-nums">{row.original.job.per_second?.toFixed(1) ?? '—'}</span>,
    }),
    runHelper.accessor(r => r.job.started_at_ts ?? 0, {
      id: 'started_at',
      header: 'Started',
      sortFn: 'basic',
      cell: ({ row }) => (
        <span className="text-muted-foreground">
          {row.original.job.started_at_ts ? tzDateTime(row.original.job.started_at_ts * 1000, tz) : '—'}
        </span>
      ),
    }),
  ], [tz])

  return (
    <DataTable<RunRow>
      columns={columns}
      data={rows}
      getRowId={r => r.key}
      initialSorting={[{ id: 'started_at', desc: true }]}
      initialPageSize={25}
      pageSizeOptions={[10, 25, 50]}
      maxHeight="18rem"
      ariaLabel="Ingestion run history"
      footerLeft={`${rows.length} runs`}
    />
  )
}

const slowHelper = createColumnHelper<DataTableFeatures, SlowEntry>()

function SlowTable({ slow, tz }: { slow: SlowEntry[]; tz: Tz }) {
  const columns = useMemo<ColumnDef<DataTableFeatures, SlowEntry, any>[]>(() => [
    slowHelper.accessor('ms', {
      id: 'ms',
      header: 'ms',
      sortFn: 'basic',
      sortDescFirst: true,
      meta: { align: 'right' },
      cell: ({ row }) => <span className="tabular-nums text-amber-500">{row.original.ms}ms</span>,
    }),
    slowHelper.accessor('method', {
      id: 'method',
      header: 'method',
      cell: ({ row }) => <Badge variant="outline">{row.original.method ?? 'GET'}</Badge>,
    }),
    slowHelper.accessor('url', {
      id: 'url',
      header: 'url',
      meta: { truncate: true },
      cell: ({ row }) => <span className="text-muted-foreground">{row.original.url}</span>,
    }),
    slowHelper.accessor('ts', {
      id: 'ts',
      header: 'time',
      cell: ({ row }) => <span className="text-muted-foreground">{timeStr(row.original.ts, tz)}</span>,
    }),
  ], [tz])

  return (
    <DataTable<SlowEntry>
      columns={columns}
      data={slow}
      getRowId={(s, i) => `${s.url ?? ''}-${s.ms ?? 0}-${s.ts ?? ''}-${i}`}
      initialSorting={[{ id: 'ms', desc: true }]}
      initialPageSize={10}
      pageSizeOptions={[10, 25, 50, 100]}
      maxHeight="18rem"
      ariaLabel="Slow requests"
      footerLeft={`${slow.length} entries`}
    />
  )
}