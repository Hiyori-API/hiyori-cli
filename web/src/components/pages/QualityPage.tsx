import { useEffect, useMemo, useState } from 'react'
import { Download, GitMerge, ListChecks, RefreshCw, Scale, SlidersHorizontal, Sparkles, TrendingDown } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import {
  downloadExport, fetchBenchmark, fetchFailures, fetchHistogram, fetchSample, type BenchmarkRow, type ValidationEvent,
} from '@/lib/api'
import { useSettings, useSmartPolling } from '@/lib/hooks'
import { BenchmarkBars, CompletenessRadar, DensityHistogram, ThresholdScatter } from '../chartcard'
import { COLORS } from '../charts'
import { EmptyState } from '../empty'
import { PageHead, Section } from '../widgets'

const FULL_COLS = ['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'cr', 'nf', 'sb', 'ttv', 't', 'adb', 'tx', 'b', 'fm', 'yt', 'ap', 'tvt']
const DEFAULT_WEIGHTS = { title: 31, episodes: 31, year: 15, season: 14, type: 6, tags: 3, status: 0 }
// demo per-field similarity for the weight explorer (fixed sample pair)
const DEMO_SIMS = { title: 0.86, episodes: 1, year: 1, season: 1, type: 1, tags: 0.5, status: 1 }

export function QualityPage() {
  const { data } = useSmartPolling(6000, true)
  const { settings, update } = useSettings()
  const [bench, setBench] = useState<{ seeded?: BenchmarkRow[]; latest?: unknown } | null>(null)
  const [hist, setHist] = useState<{ histogram?: Record<string, number>; bins?: number[] } | null>(null)
  const [failures, setFailures] = useState<ValidationEvent[]>([])
  const [sample, setSample] = useState<Record<string, unknown> | null | undefined>(undefined)
  const [minConf, setMinConf] = useState(0)
  const [feed, setFeed] = useState({ right: 0, wrong: 0 })
  const weights: Record<string, number> = { ...DEFAULT_WEIGHTS, ...(settings.prefs?.weights ?? {}) }

  useEffect(() => {
    void fetchBenchmark().then(setBench)
    void fetchHistogram().then(setHist)
    void fetchFailures().then(r => setFailures(r.failures ?? []))
  }, [])

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

  const thresholdCurve = useMemo(() => {
    const h = hist?.histogram
    if (!h) return []
    const total = Object.values(h).reduce((a, b) => a + b, 0)
    if (!total) return []
    const points = []
    let acc = 0
    const bins = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]
    for (let i = bins.length - 1; i >= 0; i--) {
      acc += (h[String(bins[i])] ?? 0)
      points.push({ threshold: bins[i], merges: Math.round((acc / total) * 1000), organized: Math.round((acc / total) * 700) })
    }
    return points.reverse()
  }, [hist])

  const validationTrend = useMemo(() => {
    const buckets: Record<string, number> = {}
    failures.forEach(f => {
      if (!f.ts) return
      const key = f.ts.slice(0, 16)
      buckets[key] = (buckets[key] ?? 0) + 1
    })
    return Object.entries(buckets).slice(-24).map(([k, v]) => ({ t: k.slice(11), n: v }))
  }, [failures])

  const scorePreview = useMemo(() => {
    const ws = Object.values(weights).reduce((a, b) => a + b, 0) || 1
    const sims = Object.entries(weights).map(([k, w]) => {
      const sim = DEMO_SIMS[k as keyof typeof DEMO_SIMS] ?? 0
      return { k, w, sim, contrib: (w / ws) * sim * 100 }
    })
    const score = sims.reduce((a, s) => a + s.contrib, 0)
    return { sims, score }
  }, [weights])

  const sampled = sample
  const draw = (min = minConf) => void fetchSample('organized', min).then(r => setSample(r.sample ?? null))
  const reloadAnalysis = () => {
    void fetchBenchmark().then(setBench)
    void fetchHistogram().then(setHist)
    void fetchFailures().then(r => setFailures(r.failures ?? []))
  }

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

      {/* Q2: coverage */}
      <Section title="Reference coverage" icon={GitMerge} badge={<Badge variant="outline">{cols.length} collections</Badge>}>
        {cols.length === 0 ? <EmptyState icon={GitMerge} title="No coverage data" description="Ingest sources to populate reference coverage." /> : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-muted-foreground">
                    <th className="px-1 py-1 text-left">Collection</th>
                    {FULL_COLS.map(s => <th key={s} className="px-1 py-1 text-center">{s}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {cols.map(c => (
                    <tr key={c} className="border-t">
                      <td className="px-1 py-1">{c} <span className="text-muted-foreground">({(coverage[c]?.total ?? 0).toLocaleString()})</span></td>
                      {FULL_COLS.map(s => {
                        const v = coverage[c]?.[s] ?? 0
                        const pct = v / (coverage[c]?.total ?? 1)
                        return <td key={s} className="px-1 py-1 text-center tabular-nums" title={String(v)}>
                          <span className={`rounded px-1 ${pct > 0.5 ? 'bg-emerald-500/20 text-emerald-500' : pct > 0 ? 'bg-amber-500/20 text-amber-500' : 'text-muted-foreground'}`}>{pct > 0 ? (pct * 100).toFixed(0) : '·'}</span>
                        </td>
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div>
              <CompletenessRadar title="Completeness profile" data={radarData} series={radarSeries} emptyMsg="no coverage to plot" />
              <div className="mt-2 space-y-1 text-xs">
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
          </div>
        )}
      </Section>

      {/* Q3: benchmark + thresholds */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Strategy benchmark" icon={Scale} badge={
          <Button size="icon-xs" variant="ghost" title="Refresh benchmark + histogram" onClick={reloadAnalysis}><RefreshCw className="h-3 w-3" /></Button>
        }>
          {!bench?.seeded?.length ? <Skeleton className="h-40 w-full" /> : (
            <>
              <BenchmarkBars title="Wall time vs baseline" rows={(bench.seeded ?? []).map(b => ({ name: b.strategy ?? '?', wall_s: b.wall_s ?? 0, merges: b.merges, speed: b.speed }))} />
              <div className="mt-2 space-y-1 text-xs">
                {bench.seeded.map(b => (
                  <div key={b.strategy} className="flex justify-between text-muted-foreground">
                    <span>{b.strategy}</span>
                    <span className="tabular-nums">{b.merges?.toLocaleString()} merges · {Math.round((b.wall_s ?? 0) / 60)}m · {b.speed}/s</span>
                  </div>
                ))}
              </div>
            </>
          )}
        </Section>
        <Section title="Threshold sensitivity" icon={TrendingDown} badge={<Badge variant="outline">estimated</Badge>}>
          <ThresholdScatter title="Merges above threshold" points={thresholdCurve} />
          <p className="mt-1 text-[10px] text-muted-foreground">Cumulative merges above each score threshold, inferred from the recorded confidence histogram. Run a real sweep (combine --threshold) for exact numbers.</p>
        </Section>
      </div>

      {/* Q1 + Q4 + histogram */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Section title="Confidence distribution" icon={Sparkles}>
          {!hist ? <Skeleton className="h-40 w-full" /> : <DensityHistogram title="Merged scores" bins={hist.bins ?? [0, 10, 20, 30, 40, 50, 60, 70, 80, 90]} counts={hist.histogram ?? {}} />}
        </Section>
        <Section title="Merge sampler" icon={ListChecks}>
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
                <Button size="sm" className="flex-1" onClick={() => { setFeed(f => ({ ...f, right: f.right + 1 })); draw(minConf) }}>Looks right</Button>
                <Button size="sm" className="flex-1" variant="destructive" onClick={() => { setFeed(f => ({ ...f, wrong: f.wrong + 1 })); draw(minConf) }}>Wrong</Button>
                <Button size="sm" className="flex-1" variant="outline" onClick={() => draw(minConf)}>Next</Button>
              </div>
              {(feed.right + feed.wrong) > 0 && (
                <p className="text-xs text-muted-foreground">session: {feed.right} ✓ / {feed.wrong} ✗ ({(feed.right / Math.max(1, feed.right + feed.wrong) * 100).toFixed(0)}% ok)</p>
              )}
            </div>
          )}
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

      {/* Q6: weightage explorer */}
      <Section title="Weightage explorer" icon={SlidersHorizontal} badge={<Badge variant="outline">metadata_fuzzy</Badge>}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="space-y-3">
            {Object.entries(weights as Record<string, number>).map(([k, v]) => (
              <div key={k} className="flex items-center gap-3">
                <span className="w-20 text-xs capitalize">{k}</span>
                <Slider value={[v]} min={0} max={50} step={1} onValueChange={(val) => { const nv = Array.isArray(val) ? val[0] : val; void update({ prefs: { ...settings.prefs, weights: { ...weights, [k]: Number(nv ?? 0) } } }) }} className="flex-1" />
                <span className="w-8 text-right text-sm tabular-nums">{v}</span>
              </div>
            ))}
            <p className="text-[10px] text-muted-foreground">Live preview of the weighted similarity score on a fixed sample pair. Persisted to Settings and feeds <code>combine --weights</code>.</p>
          </div>
          <div className="rounded-lg border p-4">
            <div className="flex items-baseline justify-between">
              <span className="text-xs uppercase tracking-wide text-muted-foreground">Demo score</span>
              <span className="text-3xl font-semibold tabular-nums">{scorePreview.score.toFixed(1)}<span className="text-sm text-muted-foreground">/100</span></span>
            </div>
            <div className="mt-3 space-y-1.5 text-xs">
              {scorePreview.sims.map(s => (
                <div key={s.k} className="flex items-center gap-2">
                  <span className="w-20 capitalize">{s.k}</span>
                  <span className="flex-1 rounded bg-muted"><span className="block h-2 rounded bg-primary/70" style={{ width: `${s.sim * 100}%` }} /></span>
                  <span className="w-24 text-right tabular-nums text-muted-foreground">w{s.w} · {(s.sim * (s.w || 1) / 31 * 100).toFixed(0)}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </Section>
    </div>
  )
}
