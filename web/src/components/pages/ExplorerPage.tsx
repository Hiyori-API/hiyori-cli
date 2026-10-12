import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  ArrowDown, ArrowUp, Bookmark, ChevronLeft, ChevronRight, Copy, Database, Download,
  GitCompareArrows, Link2, ListFilter, Save, Settings2, Trash2, X,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { deleteDocs, fetchExplore, fetchMeta, type ExploreRow } from '@/lib/api'
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
  const docs = data?.docs ?? []
  const fields = data?.fields ?? []

  const toggleSel = (id: string) => {
    setSel(s => {
      const n = new Set(s)
      if (n.has(id)) {
        n.delete(id)
      } else {
        n.add(id)
      }
      return n
    })
  }
  const allSel = docs.length > 0 && docs.every(d => sel.has(stringId(d)))
  const toggleAll = () => {
    const ids = docs.map(stringId)
    setSel(s => {
      const n = new Set(s)
      if (allSel) {
        ids.forEach(i => n.delete(i))
      } else {
        ids.forEach(i => n.add(i))
      }
      return n
    })
  }

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
    const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const lines = [headings.map(esc).join(',')]
    docs.forEach(d => lines.push(headings.map(h => esc(fieldOf(d, h))).join(',')))
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `hiyori-${collection}-p${page}.csv`
    a.click()
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
            <Button size="sm" variant="outline" onClick={exportCSV} disabled={!docs.length}><Download className="mr-1.5 h-3.5 w-3.5" />CSV</Button>
            <Button size="sm" variant="outline" onClick={() => setSel(new Set())} disabled={!sel.size}><X className="mr-1.5 h-3.5 w-3.5" />Clear ({sel.size})</Button>
            <Button size="sm" variant="destructive" onClick={() => setConfirmDel([...sel])} disabled={!sel.size}><Trash2 className="mr-1.5 h-3.5 w-3.5" />Delete ({sel.size})</Button>
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1.5">
          {Object.keys(meta).filter(c => !c.includes('_manga') || true).map(c => (
            <button key={c} onClick={() => setCollection(c)} className={`rounded-lg border px-3 py-1.5 text-sm ${collection === c ? 'border-primary/60 bg-primary/10 font-medium' : 'text-muted-foreground hover:bg-muted/40'}`}>
              {c}
              <span className="ml-1.5 text-xs tabular-nums text-muted-foreground">{meta[c]?.count?.toLocaleString() ?? '…'}</span>
            </button>
          ))}
        </div>
        <div className="ml-auto flex items-center gap-2">
          <Input value={q} onChange={e => setQ(e.target.value)} placeholder="search title / refs / id…" className="h-8 w-52" />
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
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border">
        {loading && docs.length === 0 ? (
          <div className="space-y-2 p-4">{[0, 1, 2, 3].map(i => <Skeleton key={i} className="h-8 w-full" />)}</div>
        ) : docs.length === 0 ? (
          <div className="p-4">
            <EmptyState icon={Database} title="No documents match" description={total === 0 ? 'Nothing in this collection for the current filters.' : 'Narrow your search or clear filters.'} />
          </div>
        ) : (
          <div className="max-h-[62vh] overflow-auto">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-background text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="w-8 px-3 py-2"><input type="checkbox" checked={allSel} onChange={toggleAll} /></th>
                  {cols.map(c => (
                    <th key={c} className="whitespace-nowrap px-2 py-2">
                      <button className="inline-flex items-center gap-1 hover:text-foreground" onClick={() => {
                        if (sort === c) setDir(d => (d === 'asc' ? 'desc' : 'asc'))
                        else { setSort(c); setDir('asc') }
                      }}>
                        {c}
                        {sort === c && (dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
                      </button>
                    </th>
                  ))}
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {docs.map((d, i) => {
                  const id = stringId(d)
                  const refs = refsOf(d)
                  return (
                    <tr key={id + i} className="border-t hover:bg-muted/30">
                      <td className="px-3 py-2"><input type="checkbox" checked={sel.has(id)} onChange={() => toggleSel(id)} /></td>
                      {cols.map(c => (
                        <td key={c} className="max-w-[220px] truncate px-2 py-2" title={c === 'title' ? titleOf(d) : undefined}>
                          {c === 'title' ? (titleOf(d) || <span className="text-muted-foreground italic">(no title)</span>) : prettyVal(d, c)}
                        </td>
                      ))}
                      <td className="px-3 py-2">
                        <div className="flex items-center justify-end gap-1">
                          <span className="mr-1 text-[10px] text-muted-foreground">{refs.slice(0, 3).map(([sh]) => sh).join('·') || 'no refs'}</span>
                          <Button size="icon-xs" variant="ghost" title="Inspect" aria-label={`Inspect row ${id}`} onClick={() => setDetail(d)}><ListFilter className="h-3.5 w-3.5" /></Button>
                          <Button size="icon-xs" variant="ghost" title="Add to diff" aria-label="Toggle diff pair" disabled={diffPair.length >= 2 && !diffPair.includes(d)} onClick={() => setDiffPair(p => p.includes(d) ? p.filter(x => x !== d) : [...p, d])}>
                            <GitCompareArrows className={`h-3.5 w-3.5 ${diffPair.includes(d) ? 'text-primary' : ''}`} />
                          </Button>
                          <Button size="icon-xs" variant="ghost" title="Delete" aria-label={`Delete row ${id}`} onClick={() => setConfirmDel([id])}><Trash2 className="h-3.5 w-3.5" /></Button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="flex items-center justify-between gap-2 text-sm">
        <span className="text-xs text-muted-foreground">{total.toLocaleString()} docs in {collection} · page {page}/{pages}</span>
        <div className="flex items-center gap-2">
          <Select value={String(per)} onValueChange={v => { if (v) { setPer(Number(v)); setPage(1) } }}>
            <SelectTrigger className="h-8 w-20"><SelectValue /></SelectTrigger>
            <SelectContent>
              {[10, 25, 50, 100].map(n => <SelectItem key={n} value={String(n)}>{n}/pg</SelectItem>)}
            </SelectContent>
          </Select>
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(p => p - 1)}><ChevronLeft className="h-4 w-4" /></Button>
          <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage(p => p + 1)}><ChevronRight className="h-4 w-4" /></Button>
        </div>
      </div>

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
