import { useMemo } from 'react'
import { Bar, BarChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { GitMerge } from 'lucide-react'
import { Section } from './widgets'
import { EmptyState } from './empty'
import { useChartColors } from './charts'
import { ago } from '@/lib/format'
import type { BackupInfo, CombineStats } from '@/lib/api'

/**
 * Coverage and merge trust, in one chart.
 *
 * This replaces two panels that rendered four views of one dataset: a
 * 16-column coverage matrix, an 18-axis radar of those same numbers, a
 * missing-reference bar list (which is `100 −` three of the matrix columns), and
 * a separate Merge card. The matrices' `organized` rows *were* "how complete is
 * the merged output", so the two panels were answering the same question.
 *
 * What survives is the irreducible content: four collections × one completeness
 * number, plus how sure the matcher was. The grammar is one stacked bar per row,
 * always 100% wide — coloured is present, grey is missing — so rows can be
 * compared by length and read by colour.
 *
 * The confidence row keeps the same shape but a different denominator (pairs,
 * not documents). That is a real risk — it can be misread as coverage — so it
 * sits below a titled group label and its row label names the unit.
 */

/** Collections that make up each bar. Anime and manga are combined; the
 *  tooltip splits them back out. */
const SRC_ROWS = [
  { label: 'AniList', cols: ['anilist', 'anilist_manga'] },
  { label: 'MyAnimeList', cols: ['myanimelist', 'myanimelist_manga'] },
  { label: 'Kitsu', cols: ['kitsu', 'kitsu_manga'] },
] as const

const MERGED = ['organized', 'organized_manga'] as const

/** The three references every record is expected to carry. */
const CRITICAL = [
  { key: 'al', label: 'AniList' },
  { key: 'mal', label: 'MyAnimeList' },
  { key: 'k', label: 'Kitsu' },
] as const

/** Where the merged output ought to be. Stated so a bar length has a reference. */
const TARGET = 90

interface Refs {
  docs: number
  al: number
  mal: number
  k: number
}

function refsOf(coverage: Record<string, Record<string, number | undefined>>, cols: readonly string[]): Refs {
  const pick = (k: string) => cols.reduce((n, c) => n + (coverage[c]?.[k] ?? 0), 0)
  return {
    docs: cols.reduce((n, c) => n + (coverage[c]?.total ?? 0), 0),
    al: pick('al'),
    mal: pick('mal'),
    k: pick('k'),
  }
}

export function MergeQualityPanel({ coverage, combine, backup }: {
  coverage: Record<string, Record<string, number | undefined>>
  combine: CombineStats | undefined
  backup: BackupInfo | undefined
}) {
  const COLORS = useChartColors()

  const rows = useMemo(() => {
    const build = (label: string, r: Refs, kind: 'docs' | 'pairs') => {
      // Each document can carry all three refs, so the budget is 3 × docs and
      // the fill rate is "how much of that budget was filled".
      const budget = Math.max(1, r.docs * 3)
      const al = r.al / budget
      const mal = r.mal / budget
      const k = r.k / budget
      const pct = Math.round((al + mal + k) * 100)
      return {
        label,
        kind,
        docs: r.docs,
        al, mal, k,
        gap: Math.max(0, 1 - (al + mal + k)),
        c0: 0, c70: 0, c80: 0, c90: 0,
        pct,
        detail: CRITICAL.map(c => `${c.label} ${fmtPct(r[c.key], r.docs)}`).join(' · '),
      }
    }

    const out = [
      build('Merged', refsOf(coverage, MERGED), 'docs'),
      ...SRC_ROWS.map(s => build(s.label, refsOf(coverage, s.cols), 'docs')),
    ]

    // Confidence shares the 100%-budget shape but counts pairs, so it is kept
    // as its own row with its unit in the label rather than folded into the
    // document rows above.
    const hist = combine?.score_histogram
    const merges = Number(combine?.merges ?? 0)
    if (hist && merges > 0) {
      const b = (v: unknown) => Number(v ?? 0)
      const p0 = b(hist.p0_70), p70 = b(hist.p70_80), p80 = b(hist.p80_90), p90 = b(hist.p90_100)
      const total = p0 + p70 + p80 + p90
      // Distinct keys, so the confidence row cannot inherit the AniList /
      // MyAnimeList / Kitsu colours above it — same shape, different meaning.
      out.push({
        label: `Confidence · ${cx(merges)} pairs`,
        kind: 'pairs',
        docs: merges,
        al: 0, mal: 0, k: 0, gap: 0,
        c0: p0 / (total || 1),
        c70: p70 / (total || 1),
        c80: p80 / (total || 1),
        c90: p90 / (total || 1),
        pct: Math.round(((p80 + p90) / (total || 1)) * 100),
        detail: `${cx(p0)} below 70 · ${cx(p70)} at 70–80 · ${cx(p80)} at 80–90 · ${cx(p90)} at 90–100`,
      })
    }
    return out
  }, [coverage, combine])

  const hasData = rows.some(r => r.docs > 0)

  const summary = rows.map(r => `${r.label}: ${r.pct}%`).join('; ')

  return (
    <Section title="Merge quality" icon={GitMerge} collapsible={false}>
      {!hasData ? (
        <EmptyState
          icon={GitMerge}
          title="Nothing to show yet"
          description="Reference coverage and merge confidence appear once a source has been ingested and combined."
        />
      ) : (
        <>
          {/* The pair count, strategy and base stay as a line — they are the
              context for the confidence row and read better as text. */}
          {combine && (
            <p className="mb-2 text-xs text-muted-foreground">
              <span className="text-base font-medium tabular-nums text-foreground">{cx(combine.merges ?? 0)}</span>
              {' pairs · '}{combine.strategy}{' · base '}{combine.base}
            </p>
          )}

          <div className="h-[13.5rem]" role="img" aria-label={summary}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart layout="vertical" data={rows} barSize={13} margin={{ top: 2, right: 26, bottom: 2, left: 0 }}>
                <XAxis type="number" domain={[0, 1]} hide />
                <YAxis
                  type="category"
                  dataKey="label"
                  width={128}
                  tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }}
                  tickLine={false}
                  axisLine={false}
                />
                <ReferenceLine x={TARGET / 100} stroke="var(--border)" strokeDasharray="3 3" />
                <Tooltip
                  contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }}
                  formatter={(value, name) => [`${Math.round(Number(value ?? 0) * 100)}%`, String(name)] as [string, string]}
                />
                {/* One hue family for the reference segments so the split reads
                    as parts of one thing; grey tail is what is missing. */}
                <Bar dataKey="al" name="AniList ref" stackId="q" fill={COLORS[0]} isAnimationActive={false} />
                <Bar dataKey="mal" name="MyAnimeList ref" stackId="q" fill={COLORS[1]} isAnimationActive={false} />
                <Bar dataKey="k" name="Kitsu ref" stackId="q" fill={COLORS[2]} isAnimationActive={false} />
                <Bar dataKey="gap" name="Missing" stackId="q" fill="var(--muted)" isAnimationActive={false} />
                <Bar dataKey="c0" name="scored below 70" stackId="q" fill={COLORS[5]} fillOpacity={0.2} isAnimationActive={false} />
                <Bar dataKey="c70" name="scored 70–80" stackId="q" fill={COLORS[5]} fillOpacity={0.45} isAnimationActive={false} />
                <Bar dataKey="c80" name="scored 80–90" stackId="q" fill={COLORS[5]} fillOpacity={0.7} isAnimationActive={false} />
                <Bar dataKey="c90" name="scored 90–100" stackId="q" fill={COLORS[5]} isAnimationActive={false} />
              </BarChart>
            </ResponsiveContainer>
          </div>

          <p className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-muted-foreground">
            {CRITICAL.map((c, i) => (
              <span key={c.key} className="inline-flex items-center gap-1">
                <span className="h-1.5 w-1.5 rounded-sm" style={{ background: COLORS[i] }} />
                {c.label}
              </span>
            ))}
            <span className="inline-flex items-center gap-1">
              <span className="h-1.5 w-1.5 rounded-sm bg-muted" />
              missing
            </span>
            <span className="text-muted-foreground/60">·</span>
            <span>
              <span className="mr-1 inline-block h-1.5 w-1.5 rounded-sm align-middle" style={{ background: COLORS[5] }} />
              confidence bands (below 70 / 70–80 / 80–90 / 90–100)
            </span>
            <span className="text-muted-foreground/60">·</span>
            <span>% of the 3-reference budget per document; dashed line is {TARGET}%</span>
          </p>

          {combine && (
            <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
              <span>avg <span className="tabular-nums text-foreground">{combine.scores?.avg ?? '—'}</span></span>
              <span>floor <span className="tabular-nums text-foreground">{combine.threshold ?? '—'}</span></span>
              <span>ran {ago(combine.completed_at)}</span>
              {backup?.last?.mtime != null && (
                <span>backed up {ago(new Date(backup.last.mtime * 1000).toISOString())}</span>
              )}
            </p>
          )}
        </>
      )}
    </Section>
  )
}

function fmtPct(n: number, total: number): string {
  return total > 0 ? `${Math.round((n / total) * 100)}%` : '—'
}

/** Compact big counts (≥10k → "12K"). */
function cx(n?: number | null): string {
  if (n == null || Number.isNaN(n)) return '—'
  return Math.abs(n) >= 10_000 ? `${(n / 1000).toFixed(0)}K` : n.toLocaleString()
}
