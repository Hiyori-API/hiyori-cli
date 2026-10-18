import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { GitMerge } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { useChartColors } from './charts'
import { CompletenessRadar } from './chartcard'
import { EmptyState } from './empty'
import { Section } from './widgets'
import { fmt } from '@/lib/format'

/**
 * Reference coverage: how much of each collection carries each external
 * reference id, as a matrix and as a radar.
 *
 * Lifted out of the Merge-quality page so the Dashboard can show it too. Both
 * places render the same widget rather than a copy, because the two views of
 * the same numbers drifting apart is exactly how the coverage table ended up
 * with a different collection set from the explorer.
 */

const FULL_COLS = ['al', 'mal', 'k', 'ann', 'wpen', 'wpja', 'cr', 'nf', 'sb', 'ttv', 't', 'adb', 'tx', 'b', 'fm', 'yt', 'ap', 'tvt']
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
/**
 * Collections worth showing reference coverage for.
 *
 * This excluded the merged output and included `embeddings`, so the matrix
 * carried an infrastructure collection with 3 documents while omitting the
 * collections the tool actually produces — and whose enrichment is the thing
 * being judged here. Sources come first, then the merged output.
 */
const COVERAGE_ORDER = [
  'organized', 'organized_manga',
  'anilist', 'anilist_manga',
  'myanimelist', 'myanimelist_manga',
  'kitsu', 'kitsu_manga',
]

function coverageCollections(coverage: Record<string, Record<string, number | undefined>>): string[] {
  const present = new Set(Object.keys(coverage).filter(c => (coverage[c]?.total ?? 0) > 0))
  const known = COVERAGE_ORDER.filter(c => present.has(c))
  // Anything else that is a real catalogue (not `_`-prefixed bookkeeping) still
  // appears, so a new source is never silently hidden.
  const rest = [...present]
    .filter(c => !COVERAGE_ORDER.includes(c) && !c.startsWith('_') && c !== 'embeddings' && c !== 'llm_judgements')
    .sort()
  return [...known, ...rest]
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
              className={`rounded px-1 ${pct > 0.5 ? 'bg-emerald-500/20 text-emerald-500' : pct > 0 ? 'bg-warn/20 text-warn' : 'text-muted-foreground'}`}
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
    <div className="space-y-2">
      <DataTable
        columns={columns}
        data={rows}
        getRowId={r => r.name}
        initialSorting={[{ id: 'name', desc: false }]}
        sortDescFirst
        maxHeight="22rem"
        ariaLabel="Reference coverage matrix"
        // Seven collections fit on one screen; paginating them added a page
        // control and a row counter that only ever said "1/1".
        showPagination={false}
      />
      {/* The dots carried the most meaning in the table and were explained
          nowhere. */}
      <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
        <span>% of documents carrying each reference shorthand</span>
        <span className="text-muted-foreground/60">·</span>
        <span className="inline-flex items-center gap-1">
          <span className="rounded bg-emerald-500/20 px-1 text-emerald-500">60</span> over half
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="rounded bg-warn/20 px-1 text-warn">12</span> some
        </span>
        <span className="inline-flex items-center gap-1">
          <span className="text-muted-foreground">·</span> none
        </span>
      </p>
    </div>
  )
}

/**
 * The whole widget: matrix / radar tabs over the current coverage snapshot.
 */
export function ReferenceCoveragePanel({ coverage: raw }: { coverage: Record<string, Record<string, number | undefined>> }) {
  const coverage = raw
  const cols = coverageCollections(coverage)
  const COLORS = useChartColors()

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

  const missingRows = useMemo(() => cols.map(c => ({
    name: c,
    al: coverage[c]?.missing_al ?? 0,
    mal: coverage[c]?.missing_mal ?? 0,
    k: coverage[c]?.missing_k ?? 0,
  })), [coverage, cols])

  return (
    <Section title="Reference coverage" icon={GitMerge} badge={<Badge variant="outline">{cols.length} collections</Badge>}>
      {cols.length === 0 ? (
        <EmptyState icon={GitMerge} title="No coverage data" description="Ingest sources to populate reference coverage." />
      ) : (
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
                    <span className="flex-1 rounded bg-muted">
                      <span
                        className="block h-3 max-w-full rounded bg-destructive/70"
                        style={{ width: `${Math.min(100, ((m.al + m.mal + m.k) / Math.max(1, (coverage[m.name]?.total ?? 1))) * 100)}%` }}
                      />
                    </span>
                    <span className="tabular-nums text-muted-foreground">{m.al + m.mal + m.k}</span>
                  </div>
                ))}
              </div>
            </div>
          </TabsContent>
        </Tabs>
      )}
    </Section>
  )
}
