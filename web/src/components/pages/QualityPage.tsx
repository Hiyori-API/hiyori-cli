import { useCallback, useEffect, useMemo, useState } from 'react'
import { type ColumnDef } from '@tanstack/react-table'
import { Download, GitMerge, ListChecks, RefreshCw, Scale, ShieldX, SlidersHorizontal, Sparkles, TrendingDown } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  downloadExport, fetchBenchmarkManga, fetchRejections, fetchSample, postLabel, promoteRejections,
  type BenchmarkRow, type MlBenchmark, type RejectionRow, type RejectionStats, type SampleRef,
} from '@/lib/api'
import { useDebouncedCallback, useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { useCachedFetch, useFailures } from '@/lib/fetch-cache'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { CompletenessRadar, DensityHistogram } from '../chartcard'
import { useChartColors } from '../charts'
import { EmptyState } from '../empty'
import { fmt, fmtCompact, fmtSec } from '@/lib/format'
import { tzDateTime, type Tz } from '@/lib/time'
import { PageHead, Section } from '../widgets'

const FULL_COLS = ['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'cr', 'nf', 'sb', 'ttv', 't', 'adb', 'tx', 'b', 'fm', 'yt', 'ap', 'tvt']
const DEFAULT_WEIGHTS = { title: 31, episodes: 31, year: 15, season: 14, type: 6, tags: 3, status: 0 }

/** Missing critical refs per collection, kept as hidden sortable columns. */
const MISSING_KEYS = ['missing_al', 'missing_mal', 'missing_k'] as const

interface CoverageMatrixRow {
  name: string
  total: number
  counts: Record<string, number | undefined>
}

/**
 * Collections worth comparing in coverage views.
 *
 * Drops derived `organized*` outputs (the combiner's own product, not a
 * reference source) and the internal `_*` bookkeeping collections
 * (`_counters`, `_migrations`, `_hiyori_ids`) which carry no reference ids.
 * Used for both the section badge and the matrix so the two cannot drift.
 */
function coverageCollections(coverage: Record<string, Record<string, number | undefined>>): string[] {
  return Object.keys(coverage)
    .filter(c => (coverage[c]?.total ?? 0) > 0 && !c.includes('organized') && !c.startsWith('_'))
    .sort()
}

/** Enriched, sortable coverage matrix built on the shared DataTable (Q1: empty shorthand columns are hidden). */
function CoverageMatrixTable({ coverage }: { coverage: Record<string, Record<string, number | undefined>> }) {
  const rows = useMemo<CoverageMatrixRow[]>(
    () =>
      coverageCollections(coverage)
        .map(name => ({ name, total: coverage[name]?.total ?? 0, counts: coverage[name] ?? {} })),
    [coverage],
  )

  const columns = useMemo<ColumnDef<DataTableFeatures, CoverageMatrixRow, any>[]>(() => {
    // Only render shorthand columns that carry at least one doc across collections.
    const active = FULL_COLS.filter(sh => rows.some(r => (r.counts[sh] ?? 0) > 0))
    return [
      {
        id: 'name',
        header: 'Collection',
        accessorFn: r => r.name,
        sortingFn: 'basic',
        meta: { align: 'left' } as const,
        cell: info => (
          <>
            {info.row.original.name}{' '}
            <span className="text-muted-foreground">({fmt(info.row.original.total)})</span>
          </>
        ),
      },
      ...active.map<ColumnDef<DataTableFeatures, CoverageMatrixRow, any>>(sh => ({
        id: sh,
        header: sh,
        accessorFn: r => r.counts[sh] ?? 0,
        sortingFn: 'basic',
        meta: { align: 'center' } as const,
        cell: info => {
          const { total } = info.row.original
          const v = info.row.original.counts[sh] ?? 0
          const pct = v / (total || 1)
          return (
            <span
              title={String(v)}
              className={`rounded px-1 ${pct > 0.5 ? 'bg-emerald-500/20 text-emerald-500' : pct > 0 ? 'bg-amber-500/20 text-amber-500' : 'text-muted-foreground'}`}
            >
              {pct > 0 ? (pct * 100).toFixed(0) : '·'}
            </span>
          )
        },
      })),
      ...MISSING_KEYS.map<ColumnDef<DataTableFeatures, CoverageMatrixRow, any>>(k => ({
        id: k,
        header: k,
        accessorFn: r => r.counts[k] ?? 0,
        sortingFn: 'basic',
        meta: { className: 'hidden' } as const,
      })),
    ]
  }, [rows])

  return (
    <DataTable
      columns={columns}
      data={rows}
      getRowId={r => r.name}
      initialSorting={[{ id: 'name', desc: false }]}
      initialPageSize={8}
      sortDescFirst
      maxHeight="22rem"
      ariaLabel="Reference coverage matrix"
      footerLeft={`${rows.length} collections · % of docs carrying each shorthand`}
    />
  )
}

/** One benchmark table (Q4): strategy, wall, merges, dups. */
function BenchmarkTable({ rows }: { rows: BenchmarkRow[] }) {
  if (!rows.length) {
    return <EmptyState icon={Scale} title="No benchmark rows" description="Run a combine benchmark to compare strategies." />
  }
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Strategy</TableHead>
          <TableHead className="text-right">Wall</TableHead>
          <TableHead className="text-right">Merges</TableHead>
          <TableHead className="text-right">Dups</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map(b => {
          // dups: prefer the API field when a benchmark exposes it; until then,
          // estimate duplicate hits as merges minus uniquely-organized docs.
          const withDups = b as BenchmarkRow & { dups?: number }
          const dups = withDups.dups ?? Math.max(0, (b.merges ?? 0) - (b.organized ?? 0))
          return (
            <TableRow key={b.strategy ?? `${b.wall_s ?? ''}-${b.merges ?? ''}`}>
              <TableCell className="font-medium">{b.strategy ?? '—'}</TableCell>
              <TableCell className="text-right tabular-nums">{fmtSec(b.wall_s)}</TableCell>
              <TableCell className="text-right tabular-nums">{fmtCompact(b.merges)}</TableCell>
              <TableCell className="text-right tabular-nums" title={withDups.dups === undefined ? 'estimated: merges − organized until the API exposes dups' : 'duplicate merges'}>
                {fmtCompact(dups)}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}

export function QualityPage() {
  const { data } = useStatus()
  const { settings, update } = useSettings()
  const tz: Tz = settings.prefs?.tz === 'utc' ? 'utc' : 'local'
  const [benchKey, setBenchKey] = useState(0)
  const bench = useCachedFetch<{ recorded?: BenchmarkRow[]; source?: string | null; recorded_at?: string | null; latest?: unknown; ml?: MlBenchmark | null }>('/api/benchmark', benchKey)
  const hist = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram', benchKey)
  const histManga = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram?collection=organized_manga', benchKey)
  const histMl = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram?field=ml_score', benchKey)
  const [benchManga, setBenchManga] = useState<BenchmarkRow[]>([])
  const { failures, refresh: refreshFailures } = useFailures()

  useEffect(() => { void fetchBenchmarkManga().then(r => setBenchManga(r.benchmark ?? [])) }, [benchKey])
  const [sample, setSample] = useState<Record<string, unknown> | null | undefined>(undefined)
  const [sampleRows, setSampleRows] = useState<SampleRef[]>([])
  // Reference shorthand:id -> the verdict written for that pair on this draw.
  const [verdicts, setVerdicts] = useState<Record<string, 0 | 1>>({})
  const [minConf, setMinConf] = useState(0)
  const [feed, setFeed] = useState({ right: 0, wrong: 0 })
  // Rejection buffer (`pair_rejections`) — the review queue of turned-down pairs.
  const [rejections, setRejections] = useState<RejectionRow[]>([])
  const [rejStats, setRejStats] = useState<RejectionStats | null>(null)
  const [rejPromoting, setRejPromoting] = useState(false)
  const weights: Record<string, number> = { ...DEFAULT_WEIGHTS, ...(settings.prefs?.weights ?? {}) }
  const [weightsLocal, setWeightsLocal] = useState<Record<string, number>>(weights)
  useEffect(() => setWeightsLocal(weights), [JSON.stringify(weights)])
  const persistWeights = useDebouncedCallback((w: Record<string, number>) => {
    void update({ prefs: { ...settings.prefs, weights: w } })
  }, 400)

  const coverage = (data?.coverage ?? {}) as Record<string, Record<string, number | undefined>>
  const cols = coverageCollections(coverage)

  const radarData = useMemo(() => {
    if (!cols.length) return []
    return FULL_COLS.map(sh => {
      const row: Record<string, string | number> = { ref: sh }
      cols.forEach(c => {
        const total = coverage[c]?.total ?? 0
        row[c] = total > 0 ? Math.round(((coverage[c]?.[sh] ?? 0) / total) * 100) : 0
      })
      return row
    })
  }, [coverage, cols])

  const COLORS = useChartColors()

  const radarSeries = cols.map((c, i) => ({ key: c, color: COLORS[i % COLORS.length] }))

  const missingRows = useMemo(() => cols.map((c, i) => ({
    name: c,
    al: coverage[c]?.missing_al ?? 0,
    mal: coverage[c]?.missing_mal ?? 0,
    k: coverage[c]?.missing_k ?? 0,
    color: COLORS[i % COLORS.length],
  })), [coverage, cols])

  const validationTrend = useMemo(() => {
    const buckets: Record<string, number> = {}
    failures.forEach(f => {
      if (!f.ts) return
      const key = f.ts.slice(0, 16)
      buckets[key] = (buckets[key] ?? 0) + 1
    })
    return Object.entries(buckets).slice(-24).map(([k, v]) => ({ t: k.slice(11), n: v }))
  }, [failures])

  const sampled = sample
  const draw = (min = minConf) => void fetchSample('organized', min).then(r => {
    setSample(r.sample ?? null)
    setSampleRows(r.refs ?? [])
    setVerdicts({})
  })
  // Rejection buffer: newest-first review queue + stats, refreshed after a promote.
  const loadRejections = useCallback(() => {
    void fetchRejections(50).then(r => {
      setRejections(r.rejections ?? [])
      if (r.stats) setRejStats(r.stats)
    })
  }, [])
  useEffect(() => { loadRejections() }, [loadRejections])

  const promoteBuffer = useCallback(async () => {
    setRejPromoting(true)
    try {
      const res = await promoteRejections()
      toast.success(`Promoted ${res.promoted ?? 0} rejection(s) into ${(res.labels ?? 0)} training label(s)`)
      loadRejections()
    } catch {
      toast.error('Promote failed')
    } finally {
      setRejPromoting(false)
    }
  }, [loadRejections])
  // A verdict belongs to a *pair*, not to the record. A merged document can
  // hold one wrong reference beside three correct ones, and a whole-record
  // "wrong" was previously written against every reference in it — including
  // the correct ones, which is how a review pass can teach the trainer the
  // wrong lesson. The first reference is the base; every other reference is
  // judged against it individually.
  const sampleDoc = (sampled ?? {}) as { title?: string; confidence?: number; score?: number; reference_ids?: Record<string, string> }
  const sampleRefRows = sampleRows.filter(r => r.id !== '')
  // Prefer a base reference the dashboard can actually show: a reference whose
  // source the local database does not hold gives the reviewer nothing to
  // compare against.
  const sampleBase = sampleRefRows.find(r => r.title !== null) ?? sampleRefRows[0]
  // Only references with a resolvable title can be judged; the rest are shown
  // but carry no controls, so a review pass cannot record a verdict it could
  // not have reached.
  const samplePairs = sampleRefRows.filter(r => r !== sampleBase && r.title !== null)
  const pairKey = (r: SampleRef) => `${r.shorthand}:${r.id}`
  const recordVerdict = (r: SampleRef, label: 0 | 1) => {
    if (!sampleBase) return
    void postLabel({
      base_source: sampleBase.shorthand,
      base_id: sampleBase.id,
      candidate_source: r.shorthand,
      candidate_id: r.id,
      label,
      source: 'human',
    })
    setVerdicts(v => ({ ...v, [pairKey(r)]: label }))
    setFeed(f => (label === 1 ? { ...f, right: f.right + 1 } : { ...f, wrong: f.wrong + 1 }))
  }
  const labelAllRight = () => samplePairs.forEach(r => { if (verdicts[pairKey(r)] === undefined) recordVerdict(r, 1) })
  const reloadAnalysis = () => {
    setBenchKey(k => k + 1)
    void refreshFailures()
  }

  const weightsJson = JSON.stringify(weights)

  return (
    <div className="space-y-4">
      <PageHead
        title="Quality & Analysis"
        desc="Merge confidence, coverage and benchmark comparisons."
        actions={
          <div className="flex items-center gap-2">
            <Button size="sm" variant="outline" onClick={() => downloadExport('organized', 'jsonl')}><Download className="mr-1.5 h-3.5 w-3.5" />JSONL</Button>
            <Button size="sm" variant="outline" onClick={() => downloadExport('organized', 'csv')}><Download className="mr-1.5 h-3.5 w-3.5" />CSV merged</Button>
          </div>
        }
      />

      {/* Q2: coverage as Matrix / Radar tabs (missing-refs bars kept in Radar) */}
      <Tabs defaultValue="quality">
        <TabsList>
          <TabsTrigger value="quality">Metrics</TabsTrigger>
          <TabsTrigger value="review">Review queue</TabsTrigger>
          <TabsTrigger value="tuning">Tuning</TabsTrigger>
        </TabsList>
        <TabsContent value="quality" className="mt-4 space-y-4">
      <Section title="Reference coverage" icon={GitMerge} badge={<Badge variant="outline">{cols.length} collections</Badge>}>
        {cols.length === 0 ? <EmptyState icon={GitMerge} title="No coverage data" description="Ingest sources to populate reference coverage." /> : (
          <Tabs defaultValue="matrix">
            <TabsList>
              <TabsTrigger value="matrix">Matrix</TabsTrigger>
              <TabsTrigger value="radar">Radar</TabsTrigger>
            </TabsList>
            <TabsContent value="matrix" className="pt-3">
              <CoverageMatrixTable coverage={coverage} />
            </TabsContent>
            <TabsContent value="radar" className="pt-3">
              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <CompletenessRadar title="Completeness profile" data={radarData} series={radarSeries} emptyMsg="no coverage to plot" />
                <div className="space-y-1 text-xs">
                  <div className="mb-1 text-muted-foreground">Missing critical refs</div>
                  {missingRows.map(m => (
                    <div key={m.name} className="flex items-center gap-2">
                      <span className="w-24 truncate">{m.name}</span>
                      <span className="flex-1 rounded bg-muted"><span className="block h-3 max-w-full rounded bg-red-400/70" style={{ width: `${Math.min(100, ((m.al + m.mal + m.k) / Math.max(1, (coverage[m.name]?.total ?? 1))) * 100)}%` }} /></span>
                      <span className="tabular-nums text-muted-foreground">{m.al + m.mal + m.k}</span>
                    </div>
                  ))}
                </div>
              </div>
            </TabsContent>
          </Tabs>
        )}
      </Section>

      {/* Q7 + Q4 + Q3: strategy benchmark, Anime/Manga tabs, one table, honesty note */}
      <Section title="Strategy benchmark" icon={Scale} badge={
        <Button
          size="icon-xs"
          variant="ghost"
          title="Refresh benchmark + histogram"
          aria-label="Refresh strategy benchmark and score histogram"
          onClick={reloadAnalysis}
        ><RefreshCw className="h-3 w-3" /></Button>
      }>
        <Tabs defaultValue="anime">
          <TabsList>
            <TabsTrigger value="anime">Anime</TabsTrigger>
            <TabsTrigger value="manga">Manga</TabsTrigger>
          </TabsList>
          <TabsContent value="anime" className="space-y-3 pt-3">
            {!bench.data ? (
              <Skeleton className="h-40 w-full" />
            ) : bench.data.recorded?.length ? (
              <BenchmarkTable rows={bench.data.recorded} />
            ) : (
              <EmptyState icon={Scale} title="No benchmark recorded" description="Runs appear here once a benchmark script writes its log." />
            )}
            <p className="text-xs text-muted-foreground">
              {bench.data?.recorded_at && (
                <>Recorded from <code className="rounded bg-muted px-1">{bench.data.source}</code> ({tzDateTime(bench.data.recorded_at, tz)}). </>
              )}
              The canonical multi-strategy comparison lives in{' '}
              <code className="rounded bg-muted px-1">docs/combiner-strategy-benchmark.md</code>.
            </p>
          </TabsContent>
          <TabsContent value="manga" className="space-y-3 pt-3">
            {benchManga.length === 0 ? (
              <EmptyState icon={Scale} title="No manga benchmark yet" description="Runs from benchmark-manga.sh (--into organized_manga) appear here." />
            ) : (
              <BenchmarkTable rows={benchManga} />
            )}
            {!histManga.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="Manga merged scores (organized_manga)" bins={histManga.data?.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={histManga.data?.histogram ?? {}} color={['var(--chart-4)', 'var(--chart-1)']} />}
          </TabsContent>
        </Tabs>
      </Section>

      {/* Q5: one Confidence card — histogram on top, sampler + single min-score slider below */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Section title="Confidence" icon={Sparkles} className="lg:col-span-2">
          <div className="space-y-4">
            {(() => {
              const ml = bench.data?.ml
              const best = ml?.best?.metadata_ml
              if (!ml || !best) return null
              const gate = ml.gate
              return (
                <div className="rounded-md border bg-muted/30 p-3 text-xs">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="font-medium">Suggested threshold</span>
                    <span className="rounded bg-primary/10 px-1.5 py-0.5 font-mono tabular-nums">{best.threshold}</span>
                    <span className="text-muted-foreground">
                      metadata_ml · F1 {Number(best.f1).toFixed(3)} · P {Number(best.precision).toFixed(2)} / R {Number(best.recall).toFixed(2)} · {best.predicted} merges
                    </span>
                    {gate?.comparable && (
                      <Badge
                        variant={gate.passed ? 'default' : 'secondary'}
                        title={gate.baseline_f1 != null && gate.ml_f1 != null
                          ? `metadata_ml F1 ${Number(gate.ml_f1).toFixed(3)} vs best baseline ${Number(gate.baseline_f1).toFixed(3)}; requires +${Number(gate.required_margin ?? 0).toFixed(3)}`
                          : undefined}
                      >
                        {gate.passed ? 'gate passed' : 'gate not met'} · {Number(gate.margin ?? 0) >= 0 ? '+' : ''}{Number(gate.margin ?? 0).toFixed(3)} vs baseline
                      </Badge>
                    )}
                  </div>
                  <p className="mt-1 text-muted-foreground">
                    From the last <code>combine:benchmark</code> run ({ml.rows} pairs{ml.ml_model_version ? `, model ${ml.ml_model_version}` : ''}). Suggested — not auto-applied.
                  </p>
                </div>
              )
            })()}
            {!hist.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="Merged scores" bins={hist.data.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={hist.data.histogram ?? {}} />}
            {!histMl.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="ML scores (metadata_ml)" bins={histMl.data.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={histMl.data.histogram ?? {}} color={['var(--chart-6)', 'var(--chart-2)']} />}
          </div>
        </Section>
        <Section title="Validation trend" icon={TrendingDown}>
          {validationTrend.length === 0 ? (
            <EmptyState icon={TrendingDown} title="No violations" description="Validation issues over time will appear here." />
          ) : (
            <div className="h-40 space-y-1">
              {validationTrend.map((b, i) => (
                <div key={i} className="flex items-center gap-2 text-xs">
                  <span className="w-14 text-muted-foreground tabular-nums">{b.t}</span>
                  <div className="h-3 flex-1 rounded bg-muted"><div className="h-full rounded bg-amber-500/70" style={{ width: `${Math.min(100, b.n * 10)}%` }} /></div>
                  <span className="tabular-nums text-muted-foreground">{b.n}</span>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>
        </TabsContent>

        <TabsContent value="review" className="mt-4 space-y-4">
      <Section title="Review queue" icon={ListChecks} badge={<Badge variant="outline">spot-check</Badge>}>
          <div>
            <div className="mb-3 flex items-center gap-3">
              <span className="text-xs text-muted-foreground">min score</span>
              <Slider
                value={[minConf]}
                min={0}
                max={97}
                step={1}
                aria-label="Minimum confidence score for sampled merges"
                onValueChange={(val) => { const v = Array.isArray(val) ? val[0] : val; setMinConf(Number(v ?? 0)) }}
                className="flex-1"
              />
              <span className="w-10 text-right text-sm tabular-nums">{minConf}</span>
            </div>
            {sampled === undefined && !sample && (
              <EmptyState icon={ListChecks} title="Spot-check merges" description="Draw a random merged record and judge correctness." action={() => draw()} actionLabel="Draw a sample" />
            )}
            {sampled === null && <p className="text-sm text-muted-foreground">No organized records{minConf > 0 ? ` above ${minConf}` : ''} found.</p>}
            {sampled && (
              <div className="space-y-2 text-sm">
                <div className="rounded-lg border p-2">
                  <div className="font-medium">{sampleDoc.title ?? '(no title)'}</div>
                  <div className="text-xs text-muted-foreground">
                    confidence: {sampleDoc.confidence?.toFixed?.(1) ?? sampleDoc.score?.toFixed?.(1) ?? '—'}
                    {sampleRefRows.length < 2 && ' · single-source record: no pair to judge'}
                  </div>
                  {/* The per-source titles are the thing actually being judged: a
                      merge is only correct if every reference describes the same
                      work, and the merged title alone cannot show that. */}
                  <div className="mt-1.5 space-y-1 border-t pt-1.5">
                    {sampleRows.length === 0 && (
                      <div className="text-xs text-muted-foreground">no reference ids on this record</div>
                    )}
                    {sampleRows.map(r => {
                      const isBase = r === sampleBase
                      const verdict = verdicts[pairKey(r)]
                      return (
                        <div key={pairKey(r)} className="flex items-center gap-2 text-xs">
                          <span className="w-9 shrink-0 font-mono text-muted-foreground">{r.shorthand}</span>
                          <span className="w-16 shrink-0 tabular-nums text-muted-foreground">{r.id || '—'}</span>
                          <span className={`min-w-0 flex-1 truncate ${r.title ? '' : 'italic text-muted-foreground'}`} title={r.title ?? undefined}>
                            {r.title ?? 'not in a local source'}
                          </span>
                          {r.year ? <span className="shrink-0 tabular-nums text-muted-foreground">{r.year}</span> : null}
                          {isBase ? (
                            <span className="w-16 shrink-0 text-right text-muted-foreground">base</span>
                          ) : r.title === null ? (
                            <span className="w-16 shrink-0 text-right text-muted-foreground" title="This source's record is not in the local database, so there is nothing to compare">
                              no data
                            </span>
                          ) : (
                            <span className="flex w-16 shrink-0 justify-end gap-1">
                              <button
                                type="button"
                                aria-label={`${r.shorthand} ${r.id} is the same work`}
                                title="Same work as the base reference"
                                onClick={() => recordVerdict(r, 1)}
                                className={`rounded border px-1.5 leading-5 ${verdict === 1 ? 'border-emerald-500 bg-emerald-500/15 text-emerald-600' : 'text-muted-foreground hover:bg-muted'}`}
                              >✓</button>
                              <button
                                type="button"
                                aria-label={`${r.shorthand} ${r.id} is a different work`}
                                title="A different work — this reference does not belong"
                                onClick={() => recordVerdict(r, 0)}
                                className={`rounded border px-1.5 leading-5 ${verdict === 0 ? 'border-destructive bg-destructive/15 text-destructive' : 'text-muted-foreground hover:bg-muted'}`}
                              >✗</button>
                            </span>
                          )}
                        </div>
                      )
                    })}
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    className="flex-1"
                    disabled={samplePairs.length === 0}
                    title={samplePairs.length === 0 ? 'A pair label needs at least two reference sources.' : undefined}
                    onClick={labelAllRight}
                  >All look right</Button>
                  <Button size="sm" className="flex-1" variant="outline" onClick={() => draw(minConf)}>Next</Button>
                </div>
                {(feed.right + feed.wrong) > 0 && (
                  <p className="text-xs text-muted-foreground">session: {feed.right} ✓ / {feed.wrong} ✗ ({(feed.right / Math.max(1, feed.right + feed.wrong) * 100).toFixed(0)}% ok) · pairs written to <code className="rounded bg-muted px-1">_labels</code></p>
                )}
              </div>
            )}
          </div>
      </Section>
      <Section title="Rejection buffer" icon={ShieldX} badge={<Badge variant="outline">pair_rejections</Badge>}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          <div className="space-y-1.5 lg:col-span-2">
            {rejections.length === 0 ? (
              <EmptyState icon={ShieldX} title="Buffer is empty" description="Rejected candidate pairs land here for review, then promote into training labels." />
            ) : (
              rejections.map((r, i) => (
                <div key={`${r.base_source}:${r.base_id}:${r.candidate_source}:${r.candidate_id}:${i}`} className="flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs">
                  <Badge variant={r.decision === 'approve' ? 'default' : r.decision === 'reject' ? 'secondary' : 'outline'} className="w-16 justify-center">{r.decision}</Badge>
                  <span className="font-mono text-muted-foreground">{r.base_source}:{r.base_id} ↔ {r.candidate_source}:{r.candidate_id}</span>
                  <span className="ml-auto tabular-nums text-muted-foreground">{r.score != null ? Number(r.score).toFixed(1) : '—'}</span>
                  <span className={r.promoted ? 'text-muted-foreground' : 'text-amber-600'}>{r.promoted ? 'promoted' : 'pending'}</span>
                </div>
              ))
            )}
          </div>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2 text-center">
              <div className="rounded-md border p-2"><div className="text-lg font-semibold tabular-nums">{rejStats?.pending ?? '—'}</div><div className="text-xs text-muted-foreground">pending</div></div>
              <div className="rounded-md border p-2"><div className="text-lg font-semibold tabular-nums">{rejStats?.promoted ?? '—'}</div><div className="text-xs text-muted-foreground">promoted</div></div>
            </div>
            <Button className="w-full" onClick={() => { void promoteBuffer() }} disabled={rejPromoting || (rejStats?.pending ?? 0) === 0}>
              {rejPromoting ? 'Promoting…' : 'Promote to training'}
            </Button>
            <p className="text-xs leading-relaxed text-muted-foreground">
              Promote copies decided pairs into <code className="rounded bg-muted px-1">_labels</code> (approve → agree, reject → disagree). <code className="rounded bg-muted px-1">unsure</code> rows are skipped so an ambiguous verdict never reaches the trainer.
            </p>
          </div>
        </div>
      </Section>

      {/* Q6: weight explorer — adjustable weights + honest CLI note, preview dropped */}
        </TabsContent>

        <TabsContent value="tuning" className="mt-4 space-y-4">
      <Section title="Weightage explorer" icon={SlidersHorizontal} badge={<Badge variant="outline">metadata_fuzzy</Badge>}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="space-y-3">
            {Object.entries(weights as Record<string, number>).map(([k, v]) => (
              <div key={k} className="flex items-center gap-3">
                <span className="w-20 text-xs capitalize">{k}</span>
                <Slider value={[weightsLocal[k] ?? v]} min={0} max={50} step={1} onValueChange={(val) => { const nv = Array.isArray(val) ? val[0] : val; const next = { ...weightsLocal, [k]: Number(nv ?? 0) }; setWeightsLocal(next); persistWeights(next) }} className="flex-1" />
                <span className="w-8 text-right text-sm tabular-nums">{v}</span>
              </div>
            ))}
          </div>
          <div className="rounded-lg border p-4 text-xs leading-relaxed text-muted-foreground">
            <div className="mb-2 flex items-center gap-2 text-sm font-medium text-foreground"><SlidersHorizontal className="h-4 w-4" />Live tuning</div>
            <p>Weights drive <code className="rounded bg-muted px-1">metadata_fuzzy</code> string similarity for anime merges (0–50 per field). Changes persist to Settings → <code className="rounded bg-muted px-1">prefs.weights</code> and feed <code className="rounded bg-muted px-1">combine --weights</code>.</p>
            <p className="mt-2">Adjust weights, then rebuild:
              <code className="mt-1 block overflow-x-auto rounded bg-muted px-2 py-1.5">php hiyori combine anilist --strategy metadata_fuzzy --weights '{weightsJson}'</code>
            </p>
          </div>
        </div>
      </Section>
        </TabsContent>
      </Tabs>
    </div>
  )
}
