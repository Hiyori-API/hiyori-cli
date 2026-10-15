import { useEffect, useMemo, useState } from 'react'
import { type ColumnDef } from '@tanstack/react-table'
import { Download, GitMerge, ListChecks, RefreshCw, Scale, SlidersHorizontal, Sparkles, TrendingDown } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  downloadExport, fetchBenchmarkManga, fetchSample, postLabel, type BenchmarkRow,
} from '@/lib/api'
import { useDebouncedCallback, useSettings } from '@/lib/hooks'
import { useStatus } from '@/lib/status-context'
import { useCachedFetch, useFailures } from '@/lib/fetch-cache'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { CompletenessRadar, DensityHistogram } from '../chartcard'
import { COLORS } from '../charts'
import { EmptyState } from '../empty'
import { fmt, fmtCompact, fmtSec } from '@/lib/format'
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

/** Enriched, sortable coverage matrix built on the shared DataTable (Q1: empty shorthand columns are hidden). */
function CoverageMatrixTable({ coverage }: { coverage: Record<string, Record<string, number | undefined>> }) {
  const rows = useMemo<CoverageMatrixRow[]>(
    () =>
      Object.keys(coverage)
        .filter(c => (coverage[c]?.total ?? 0) > 0 && !c.includes('organized'))
        .sort()
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
  const [benchKey, setBenchKey] = useState(0)
  const bench = useCachedFetch<{ seeded?: BenchmarkRow[]; latest?: unknown }>('/api/benchmark', benchKey)
  const hist = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram', benchKey)
  const histManga = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram?collection=organized_manga', benchKey)
  const histMl = useCachedFetch<{ histogram?: Record<string, number>; bins?: number[] }>('/api/histogram?field=ml_score', benchKey)
  const [benchManga, setBenchManga] = useState<BenchmarkRow[]>([])
  const { failures, refresh: refreshFailures } = useFailures()

  useEffect(() => { void fetchBenchmarkManga().then(r => setBenchManga(r.benchmark ?? [])) }, [benchKey])
  const [sample, setSample] = useState<Record<string, unknown> | null | undefined>(undefined)
  const [minConf, setMinConf] = useState(0)
  const [feed, setFeed] = useState({ right: 0, wrong: 0 })
  const weights: Record<string, number> = { ...DEFAULT_WEIGHTS, ...(settings.prefs?.weights ?? {}) }
  const [weightsLocal, setWeightsLocal] = useState<Record<string, number>>(weights)
  useEffect(() => setWeightsLocal(weights), [JSON.stringify(weights)])
  const persistWeights = useDebouncedCallback((w: Record<string, number>) => {
    void update({ prefs: { ...settings.prefs, weights: w } })
  }, 400)

  const coverage = (data?.coverage ?? {}) as Record<string, Record<string, number | undefined>>
  const cols = Object.keys(coverage).filter(c => (coverage[c]?.total ?? 0) > 0).sort()

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
  const draw = (min = minConf) => void fetchSample('organized', min).then(r => setSample(r.sample ?? null))
  // Persist the human judgement as pair labels so `ml:export-pairs` can use it.
  // A whole-record verdict maps to every cross-source reference pair in the doc.
  const labelSample = (doc: Record<string, unknown>, label: 0 | 1) => {
    const refs = (doc.reference_ids ?? {}) as Record<string, string>
    const entries = Object.entries(refs).filter(([, v]) => v != null && String(v) !== '')
    if (entries.length < 2) return
    const [baseSource, baseId] = entries[0]
    for (const [source, id] of entries.slice(1)) {
      void postLabel({ base_source: baseSource, base_id: String(baseId), candidate_source: source, candidate_id: String(id), label })
    }
  }
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
        <Button size="icon-xs" variant="ghost" title="Refresh benchmark + histogram" onClick={reloadAnalysis}><RefreshCw className="h-3 w-3" /></Button>
      }>
        <Tabs defaultValue="anime">
          <TabsList>
            <TabsTrigger value="anime">Anime</TabsTrigger>
            <TabsTrigger value="manga">Manga</TabsTrigger>
          </TabsList>
          <TabsContent value="anime" className="space-y-3 pt-3">
            {!bench.data?.seeded?.length ? <Skeleton className="h-40 w-full" /> : <BenchmarkTable rows={bench.data.seeded} />}
            <p className="text-xs text-muted-foreground">
              sensitivity sweep: run <code className="rounded bg-muted px-1">combine --threshold</code> to populate
            </p>
          </TabsContent>
          <TabsContent value="manga" className="space-y-3 pt-3">
            {benchManga.length === 0 ? (
              <EmptyState icon={Scale} title="No manga benchmark yet" description="Runs from benchmark-manga.sh (--into organized_manga) appear here." />
            ) : (
              <BenchmarkTable rows={benchManga} />
            )}
            {!histManga.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="Manga merged scores (organized_manga)" bins={histManga.data?.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={histManga.data?.histogram ?? {}} color={['#9b7bff', '#b9a6ff']} />}
          </TabsContent>
        </Tabs>
      </Section>

      {/* Q5: one Confidence card — histogram on top, sampler + single min-score slider below */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Section title="Confidence" icon={Sparkles} className="lg:col-span-2">
          <div className="space-y-4">
            {!hist.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="Merged scores" bins={hist.data.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={hist.data.histogram ?? {}} />}
            {!histMl.data ? <Skeleton className="h-44 w-full" /> : <DensityHistogram title="ML scores (metadata_ml)" bins={histMl.data.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={histMl.data.histogram ?? {}} color={['#34d399', '#6ee7b7']} />}
            <div>
              <div className="mb-3 flex items-center gap-3">
                <span className="text-xs text-muted-foreground">min score</span>
                <Slider value={[minConf]} min={0} max={97} step={1} onValueChange={(val) => { const v = Array.isArray(val) ? val[0] : val; setMinConf(Number(v ?? 0)) }} className="flex-1" />
                <span className="w-10 text-right text-sm tabular-nums">{minConf}</span>
              </div>
              {sampled === undefined && !sample && (
                <EmptyState icon={ListChecks} title="Spot-check merges" description="Draw a random merged record and judge correctness." action={() => draw()} actionLabel="Draw a sample" />
              )}
              {sampled === null && <p className="text-sm text-muted-foreground">No organized records{minConf > 0 ? ` above ${minConf}` : ''} found.</p>}
              {sampled && (
                <div className="space-y-2 text-sm">
                  <div className="rounded-lg border p-2">
                    <div className="font-medium">{(sampled as { title?: string }).title ?? '(no title)'}</div>
                    <div className="text-xs text-muted-foreground">score: {(sampled as { score?: number }).score?.toFixed?.() ?? (sampled as { confidence?: number }).confidence ?? '—'}</div>
                    <div className="text-xs text-muted-foreground">refs: {Object.keys(((sampled as { reference_ids?: Record<string, string> }).reference_ids) ?? {}).join(', ') || 'none'}</div>
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" className="flex-1" onClick={() => { setFeed(f => ({ ...f, right: f.right + 1 })); labelSample(sampled, 1); draw(minConf) }}>Looks right</Button>
                    <Button size="sm" className="flex-1" variant="destructive" onClick={() => { setFeed(f => ({ ...f, wrong: f.wrong + 1 })); labelSample(sampled, 0); draw(minConf) }}>Wrong</Button>
                    <Button size="sm" className="flex-1" variant="outline" onClick={() => draw(minConf)}>Next</Button>
                  </div>
                  {(feed.right + feed.wrong) > 0 && (
                    <p className="text-xs text-muted-foreground">session: {feed.right} ✓ / {feed.wrong} ✗ ({(feed.right / Math.max(1, feed.right + feed.wrong) * 100).toFixed(0)}% ok)</p>
                  )}
                </div>
              )}
            </div>
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

      {/* Q6: weight explorer — adjustable weights + honest CLI note, preview dropped */}
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
    </div>
  )
}
