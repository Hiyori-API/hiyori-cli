import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  Bookmark, Code2, Copy, Database, Download, GitCompareArrows, Link2, ListFilter,
  MoreHorizontal, Save, Settings2, Trash2, X,
} from 'lucide-react'
import { createColumnHelper, type ColumnDef, type PaginationState, type SortingState, type Updater } from '@tanstack/react-table'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { deleteDocs, fetchExplore, fetchMeta, type ExploreRow } from '@/lib/api'
import { DataTable, type DataTableFeatures } from '@/components/data-table/data-table'
import { EmptyState } from '../empty'
import { PageHead } from '../widgets'

const PREBUILTS: Array<[string, string]> = [
  ['__all', 'All entries'],
  ['no_mal', 'Missing MAL ref'],
  ['no_al', 'Missing AniList ref'],
  ['no_k', 'Missing Kitsu ref'],
  ['has_mal', 'Has MAL ref'],
  ['no_refs', 'No refs at all'],
  ['empty_title', 'Empty title'],
]

/** Hard cap for "export all matching" so huge collections can't OOM the browser. */
const EXPORT_CAP = 5000

function buildCsv(rows: ExploreRow[], headings: string[]): string {
  const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const lines = [headings.map(esc).join(',')]
  rows.forEach(d => lines.push(headings.map(h => esc(fieldOf(d, h))).join(',')))
  return lines.join('\n')
}
function buildJsonl(rows: ExploreRow[]): string {
  return rows.map(d => JSON.stringify(d)).join('\n')
}
function downloadText(text: string, mime: string, filename: string) {
  const blob = new Blob([text], { type: mime })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

interface SavedQuery { name: string; collection: string; q: string; filter: string }

function titleOf(doc: ExploreRow): string {
  const t = doc.title
  if (typeof t === 'string') return t
  if (t && typeof t === 'object') {
    const o = t as Record<string, unknown>
    return String(o.romaji ?? o.english ?? o.native ?? '')
  }
  return ''
}
function refsOf(doc: ExploreRow): Array<[string, string]> {
  const r = doc.reference_ids
  if (!r || typeof r !== 'object') return []
  return Object.entries(r as Record<string, unknown>).filter(([, v]) => v) as Array<[string, string]>
}

const columnHelper = createColumnHelper<DataTableFeatures, ExploreRow>()

export function ExplorerPage() {
  const [meta, setMeta] = useState<Record<string, { count?: number; fields?: string[] }>>({})
  const [collection, setCollection] = useState('anilist')
  const [page, setPage] = useState(1)
  const [per, setPer] = useState(25)
  const [q, setQ] = useState('')
  const [qDebounced, setQDebounced] = useState('')
  const [filter, setFilter] = useState('__all')
  const [sort, setSort] = useState('')
  const [dir, setDir] = useState<'asc' | 'desc'>('asc')
  const [cols, setCols] = useState<string[]>([])
  const [data, setData] = useState<{ total?: number; docs?: ExploreRow[]; fields?: string[] } | null>(null)
  const [loading, setLoading] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [sel, setSel] = useState<Set<string>>(new Set())
  const [detail, setDetail] = useState<ExploreRow | null>(null)
  const [confirmDel, setConfirmDel] = useState<string[] | null>(null)
  const [diffPair, setDiffPair] = useState<ExploreRow[]>([])
  const [saved, setSaved] = useState<SavedQuery[]>(() => {
    try { return JSON.parse(localStorage.getItem('hiyori-saved-queries') ?? '[]') } catch { return [] }
  })
  const [queryName, setQueryName] = useState('')
  const [resolver, setResolver] = useState('')
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const seq = useRef(0)
  const colsInit = useRef(false)

  const refetch = useCallback((res: { total?: number; docs?: ExploreRow[]; fields?: string[] }) => {
    setData({ total: res.total, docs: res.docs, fields: res.fields })
  }, [])

  useEffect(() => {
    void fetchMeta().then(m => {
      const cols = m.collections ?? {}
      setMeta(cols)
      const preferred = Object.keys(cols).find(c => !['organized', 'manga_organized'].includes(c)) ?? 'anilist'
      setCollection(preferred)
    })
  }, [])

  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => setQDebounced(q), 400)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [q])

  useEffect(() => { setPage(1) }, [collection, qDebounced, filter])
  const exploreFilter = filter === '__all' ? '' : filter

  useEffect(() => {
    const id = ++seq.current
    setLoading(true)
    void fetchExplore({ collection, page, per, q: qDebounced, filter: exploreFilter, sort, dir }).then(res => {
      if (seq.current !== id) return
      refetch(res)
      if (res.fields && !colsInit.current) {
        colsInit.current = true
        const preferred = ['title', 'reference_ids', 'format', 'status', 'episodes', 'start_date', 'season', 'genres']
        setCols(preferred.filter(f => res.fields?.includes(f)).concat((res.fields ?? []).filter(f => !preferred.includes(f))).slice(0, 6))
      }
      setLoading(false)
    }).catch(() => { if (seq.current === id) setLoading(false) })
  }, [collection, page, per, qDebounced, filter, sort, dir, refetch])

  const total = data?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / per))
  const docs = useMemo(() => data?.docs ?? [], [data])
  const fields = data?.fields ?? []

  const done = async (ids: string[]) => {
    const r = await deleteDocs(collection, ids)
    toast.success(`deleted ${r.deleted ?? 0} docs`)
    setSel(s => {
      const n = new Set(s)
      ids.forEach(i => n.delete(i))
      return n
    })
    setConfirmDel(null)
    const res = await fetchExplore({ collection, page, per, q: qDebounced, filter: exploreFilter, sort, dir })
    refetch(res)
  }

  const saveQuery = () => {
    if (!queryName.trim()) { toast('name the query'); return }
    const item: SavedQuery = { name: queryName.trim(), collection, q, filter }
    const next = [...saved.filter(s => s.name !== item.name), item]
    setSaved(next)
    localStorage.setItem('hiyori-saved-queries', JSON.stringify(next))
    setQueryName('')
    toast.success(`saved “${item.name}”`)
  }
  const loadQuery = (s: SavedQuery) => {
    setCollection(s.collection); setQ(s.q); setFilter(s.filter || '__all')
  }

  const resolveRef = () => {
    const raw = resolver.trim()
    if (!raw) return
    let src = ''; let id = ''
    const parsed = raw.includes('://') ? (() => { try { return new URL(raw) } catch { return null } })() : null
    if (parsed) {
      const host = parsed.hostname.toLowerCase()
      if (host.includes('myanimelist')) { src = 'myanimelist'; id = raw.split('/').filter(Boolean).pop() ?? '' }
      else if (host.includes('anilist')) { src = 'anilist'; id = raw.split('/').filter(Boolean).pop() ?? '' }
      else if (host.includes('kitsu')) { src = 'kitsu'; id = raw.split('/').filter(Boolean).pop() ?? '' }
    } else if (raw.includes('/')) {
      const parts = raw.split('/').filter(Boolean)
      src = parts[0].toLowerCase(); id = parts[1] ?? ''
    } else {
      src = collection; id = raw
    }
    const targetSrc = ['myanimelist', 'anilist', 'kitsu'].includes(src) ? src : collection
    setCollection(targetSrc)
    setQ(id)
    setFilter('__all')
    toast(`resolving ${targetSrc} #${id}`)
  }

  const exportCSV = () => {
    const headings = cols.length ? cols : fields
    downloadText(buildCsv(docs, headings), 'text/csv;charset=utf-8', `hiyori-${collection}-p${page}.csv`)
    toast.success(`exported ${docs.length} rows`)
  }

  // Fetch up to EXPORT_CAP rows matching the current filters/sort, paging by 100.
  const exportAll = async (format: 'csv' | 'jsonl') => {
    if (exporting) return
    setExporting(true)
    const per = 100
    const rows: ExploreRow[] = []
    const pageFields: string[] = []
    let total = 0
    let capped = false
    try {
      for (let p = 1; ; p++) {
        const res = await fetchExplore({ collection, page: p, per, q: qDebounced, filter: exploreFilter, sort, dir })
        total = res.total ?? total
        if (res.fields?.length && pageFields.length === 0) pageFields.push(...res.fields)
        const pageDocs = res.docs ?? []
        rows.push(...pageDocs)
        if (rows.length >= EXPORT_CAP) { capped = true; break }
        if (rows.length >= total || pageDocs.length === 0) break
      }
    } catch {
      toast.error('export failed')
      setExporting(false)
      return
    }
    setExporting(false)
    if (rows.length === 0) { toast('nothing to export'); return }
    const filename = `hiyori-${collection}-all.${format}`
    if (format === 'csv') {
      const headings = cols.length ? cols : (pageFields.length ? pageFields : fields)
      downloadText(buildCsv(rows, headings), 'text/csv;charset=utf-8', filename)
    } else {
      downloadText(buildJsonl(rows), 'application/x-ndjson', filename)
    }
    if (capped) toast.warning(`export capped at ${EXPORT_CAP} rows — narrow the filters for the full set`)
    else toast.success(`exported ${rows.length} rows`)
  }

  const diffRows = useMemo(() => {
    if (diffPair.length < 2) return []
    const [a, b] = diffPair
    const keys = new Set([...Object.keys(a), ...Object.keys(b)])
    const out: Array<[string, unknown, unknown]> = []
    keys.forEach(k => {
      const va = JSON.stringify(a[k]); const vb = JSON.stringify(b[k])
      if (va !== vb) out.push([k, a[k], b[k]])
    })
    return out
  }, [diffPair])

  // ---- TanStack bridges (server-driven sorting / pagination) ----
  const sortingState = useMemo<SortingState>(() => (sort ? [{ id: sort, desc: dir === 'desc' }] : []), [sort, dir])
  const handleSortingChange = useCallback((updater: Updater<SortingState>) => {
    const next = typeof updater === 'function' ? updater(sortingState) : updater
    const s = next[0]
    setSort(s?.id ?? '')
    setDir(s?.desc ? 'desc' : 'asc')
  }, [sortingState])

  const paginationState = useMemo<PaginationState>(() => ({ pageIndex: Math.max(0, page - 1), pageSize: per }), [page, per])
  const handlePaginationChange = useCallback((updater: Updater<PaginationState>) => {
    const next = typeof updater === 'function' ? updater(paginationState) : updater
    if (next.pageSize !== per) {
      setPer(next.pageSize)
      setPage(1)
    } else if (next.pageIndex + 1 !== page) {
      setPage(next.pageIndex + 1)
    }
  }, [paginationState, per, page])

  const columns = useMemo<ColumnDef<DataTableFeatures, ExploreRow, unknown>[]>(() => [
    ...cols.map(c => columnHelper.accessor(c, {
      id: c,
      header: c,
      enableSorting: true,
      meta: { truncate: c === 'title' },
      cell: ({ row }) => {
        const d = row.original
        if (c === 'title') return titleOf(d) || <span className="text-muted-foreground italic">(no title)</span>
        return prettyVal(d, c)
      },
    })),
    columnHelper.display({
      id: 'actions',
      enableSorting: false,
      meta: { align: 'right', stickyRight: true },
      cell: ({ row }) => {
        const d = row.original
        const id = stringId(d)
        const refs = refsOf(d)
        const inDiff = diffPair.includes(d)
        const diffFull = diffPair.length >= 2 && !inDiff
        const toggleDiff = () => setDiffPair(p => p.includes(d) ? p.filter(x => x !== d) : [...p, d])
        return (
          <div data-column-actions className="flex items-center justify-end gap-1">
            <span className="mr-1 hidden text-[10px] text-muted-foreground sm:inline">{refs.slice(0, 3).map(([sh]) => sh).join('·') || 'no refs'}</span>
            <Button size="icon-xs" variant="ghost" title="Inspect" aria-label={`Inspect row ${id}`} onClick={() => setDetail(d)}><ListFilter className="h-3.5 w-3.5" /></Button>
            <Button size="icon-xs" variant="ghost" title="Toggle diff" aria-label={`Toggle diff row ${id}`} disabled={diffFull} onClick={toggleDiff}>
              <GitCompareArrows className={`h-3.5 w-3.5 ${inDiff ? 'text-primary' : ''}`} />
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger render={<Button size="icon-xs" variant="ghost" aria-label={`Row actions for ${id}`}><MoreHorizontal className="h-3.5 w-3.5" /></Button>} />
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>Row {previewVal(id).slice(0, 24)}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={() => setDetail(d)}><ListFilter className="h-3.5 w-3.5" />Inspect</DropdownMenuItem>
                <DropdownMenuItem disabled={diffFull} onClick={toggleDiff}><GitCompareArrows className={`h-3.5 w-3.5 ${inDiff ? 'text-primary' : ''}`} />{inDiff ? 'Remove from diff' : 'Add to diff'}</DropdownMenuItem>
                <DropdownMenuItem onClick={() => { void navigator.clipboard.writeText(id); toast('copied id') }}><Copy className="h-3.5 w-3.5" />Copy ID</DropdownMenuItem>
                <DropdownMenuItem onClick={() => { void navigator.clipboard.writeText(JSON.stringify(d, null, 2)); toast('copied json') }}><Code2 className="h-3.5 w-3.5" />Copy JSON</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onClick={() => setConfirmDel([id])}><Trash2 className="h-3.5 w-3.5" />Delete</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        )
      },
    }),
  ], [cols, diffPair])

  return (
    <div className="space-y-4">
      <PageHead
        title="Explorer"
        desc="Browse MongoDB collections — search, filter, inspect, compare."
        actions={
          <>
            <div className="flex items-center gap-1.5">
              <Input value={resolver} onChange={e => setResolver(e.target.value)} placeholder="paste URL or src/id…" className="h-8 w-56" />
              <Button size="sm" variant="outline" onClick={resolveRef}><Link2 className="mr-1.5 h-3.5 w-3.5" />Resolve</Button>
            </div>
            <DropdownMenu>
              <DropdownMenuTrigger render={<Button size="sm" variant="outline" disabled={!docs.length && !total}><Download className="mr-1.5 h-3.5 w-3.5" />Export</Button>} />
              <DropdownMenuContent align="end">
                <DropdownMenuLabel>Export</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={exportCSV} disabled={!docs.length}>Export page (CSV)</DropdownMenuItem>
                <DropdownMenuItem onClick={() => void exportAll('csv')} disabled={exporting || !total}>Export all matching (CSV)</DropdownMenuItem>
                <DropdownMenuItem onClick={() => void exportAll('jsonl')} disabled={exporting || !total}>Export all matching (JSONL)</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        {Object.keys(meta).filter(c => !c.includes('_manga') || true).map(c => (
          <button key={c} onClick={() => setCollection(c)} className={`rounded-lg border px-3 py-1.5 text-sm ${collection === c ? 'border-primary/60 bg-primary/10 font-medium' : 'text-muted-foreground hover:bg-muted/40'}`}>
            {c}
            <span className="ml-1.5 text-xs tabular-nums text-muted-foreground">{meta[c]?.count?.toLocaleString() ?? '…'}</span>
          </button>
        ))}
      </div>

      <DataTable<ExploreRow>
        columns={columns}
        data={docs}
        getRowId={(d, i) => stringId(d) || `row-${i}`}
        manualSorting
        sorting={sortingState}
        onSortingChange={handleSortingChange}
        manualPagination
        pagination={paginationState}
        onPaginationChange={handlePaginationChange}
        pageCount={pages}
        enableSelection
        selected={sel}
        onSelectionChange={setSel}
        selectionActions={count => (
          <Button size="sm" variant="destructive" onClick={() => setConfirmDel([...sel])} aria-label={`Delete ${count} selected rows`}>
            <Trash2 className="h-3.5 w-3.5" />Delete ({count})
          </Button>
        )}
        onRowClick={d => setDetail(d)}
        maxHeight="62vh"
        loading={loading}
        ariaLabel="Explorer collection"
        empty={
          <EmptyState
            icon={Database}
            title="No documents match"
            description={total === 0 ? 'Nothing in this collection for the current filters.' : 'Narrow your search or clear filters.'}
          />
        }
        footerLeft={`${total.toLocaleString()} docs in ${collection}`}
        toolbar={
          <>
            <Input value={q} onChange={e => setQ(e.target.value)} placeholder="search title / refs / id…" className="h-8 w-52" aria-label="Search" />
            <Select value={filter} onValueChange={v => setFilter(v ?? '__all')}>
              <SelectTrigger className="h-8 w-40"><SelectValue placeholder="Filter" /></SelectTrigger>
              <SelectContent>
                {PREBUILTS.map(([v, l]) => <SelectItem key={v} value={v}>{l}</SelectItem>)}
              </SelectContent>
            </Select>
            <DropdownMenu>
              <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Settings2 className="mr-1.5 h-3.5 w-3.5" />Columns</Button>} />
              <DropdownMenuContent align="end" className="max-h-72 overflow-auto">
                <DropdownMenuLabel>Visible columns</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {fields.map(f => (
                  <DropdownMenuCheckboxItem key={f} checked={cols.includes(f)} onCheckedChange={v => {
                    setCols(c => v ? [...new Set([...c, f])] : c.filter(x => x !== f))
                  }}>{f}</DropdownMenuCheckboxItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
              <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Bookmark className="mr-1.5 h-3.5 w-3.5" />Saved</Button>} />
              <DropdownMenuContent align="end" className="max-h-72 overflow-auto">
                <DropdownMenuLabel>Saved queries</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {saved.length === 0 && <div className="px-2 py-1.5 text-xs text-muted-foreground">none saved</div>}
                {saved.map(s => (
                  <div key={s.name} className="flex items-center gap-1 px-2 py-1">
                    <button className="flex-1 text-left text-sm hover:text-primary" onClick={() => loadQuery(s)}>{s.name}</button>
                    <button className="text-muted-foreground hover:text-red-400" onClick={() => { const n = saved.filter(x => x.name !== s.name); setSaved(n); localStorage.setItem('hiyori-saved-queries', JSON.stringify(n)) }}><X className="h-3 w-3" /></button>
                  </div>
                ))}
                <DropdownMenuSeparator />
                <div className="flex items-center gap-1.5 px-2 py-1.5">
                  <Input value={queryName} onChange={e => setQueryName(e.target.value)} placeholder="query name" className="h-7 text-xs" />
                  <Button size="sm" variant="ghost" onClick={saveQuery}><Save className="h-3.5 w-3.5" /></Button>
                </div>
              </DropdownMenuContent>
            </DropdownMenu>
          </>
        }
      />

      {/* detail drawer */}
      <Dialog open={!!detail} onOpenChange={o => !o && setDetail(null)}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{titleOf(detail ?? {}) || 'Document'}</DialogTitle>
            <DialogDescription>{collection} · {stringId(detail ?? {})}</DialogDescription>
          </DialogHeader>
          <pre className="max-h-[50vh] overflow-auto rounded-lg bg-muted/40 p-3 text-xs">{JSON.stringify(detail, null, 2)}</pre>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => { void navigator.clipboard.writeText(JSON.stringify(detail, null, 2)); toast('copied') }}><Copy className="mr-1.5 h-3.5 w-3.5" />Copy JSON</Button>
            <Button size="sm" onClick={() => detail && setConfirmDel([stringId(detail)])}><Trash2 className="mr-1.5 h-3.5 w-3.5" />Delete</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* delete confirm */}
      <Dialog open={!!confirmDel} onOpenChange={o => !o && setConfirmDel(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete {confirmDel?.length} doc{confirmDel?.length === 1 ? '' : 's'}?</DialogTitle>
            <DialogDescription>This permanently removes the selected documents from <code className="rounded bg-muted px-1">{collection}</code>.</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setConfirmDel(null)}>Cancel</Button>
            <Button size="sm" variant="destructive" onClick={() => confirmDel && void done(confirmDel)}>Delete</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* diff */}
      <Dialog open={diffPair.length >= 2} onOpenChange={o => !o && setDiffPair([])}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Diff</DialogTitle>
            <DialogDescription>Fields that differ between the two selected documents.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[55vh] overflow-auto rounded-lg border">
            <table className="w-full text-xs">
              <thead className="bg-muted/40 text-left"><tr><th className="w-40 px-3 py-2">Field</th><th className="px-3 py-2">A</th><th className="px-3 py-2">B</th></tr></thead>
              <tbody>
                {diffRows.length === 0 && <tr><td colSpan={3} className="px-3 py-4 text-muted-foreground">No differing fields.</td></tr>}
                {diffRows.map(([k, a, b]) => (
                  <tr key={k} className="border-t align-top">
                    <td className="px-3 py-2 font-medium">{k}</td>
                    <td className="px-3 py-2 whitespace-pre-wrap break-all text-emerald-400">{previewVal(a)}</td>
                    <td className="px-3 py-2 whitespace-pre-wrap break-all text-amber-400">{previewVal(b)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function stringId(d: ExploreRow): string {
  const id = d._id as Record<string, unknown> | string | undefined
  if (id && typeof id === 'object') return String(id.$oid ?? id)
  return String(id ?? '')
}
function fieldOf(d: ExploreRow, key: string): unknown {
  if (key === 'title') return titleOf(d)
  const val = d[key]
  if (typeof val === 'object' && val !== null) return JSON.stringify(val)
  return val
}
function prettyVal(d: ExploreRow, key: string): ReactNode {
  const v = d[key]
  if (v === null || v === undefined || v === '') return <span className="text-muted-foreground">·</span>
  if (Array.isArray(v)) return <span className="text-muted-foreground">[…{v.length}]</span>
  if (typeof v === 'object') return <span className="text-muted-foreground">{'{…}'}</span>
  return String(v)
}
function previewVal(v: unknown): string {
  try { return typeof v === 'string' ? (v.length > 300 ? v.slice(0, 300) + '…' : v) : JSON.stringify(v) } catch { return String(v) }
}