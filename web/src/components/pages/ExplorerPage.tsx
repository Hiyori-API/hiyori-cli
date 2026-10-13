/**
 * Explorer — browse MongoDB collections with server-driven pagination &
 * sorting, searchable by title/refs/id, multi-filter chips, favorites,
 * bulk actions, diffs, export, and a right-side detail panel.
 *
 * URL hash state keeps every view shareable/back-forwardable and supports
 * '#doc=<id>' deep links into the detail sheet.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AlignJustify, CircleDot, Database, Download, Link2, Rows3, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from 'cn'
import type { PaginationState, SortingState, Updater } from '@tanstack/react-table'
import type { ExploreRow } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { TooltipProvider } from '@/components/ui/tooltip'
import { DataTable } from '@/components/data-table/data-table'
import { EmptyState } from '@/components/empty'
import { PageHead } from '@/components/widgets'
import { apiDeleteDocs, apiFetchMeta, bulkAction, explore, undoExplore, updateDoc } from '@/lib/explorer/backend'
import { brandOf, isScalar, stringId, titleOf } from '@/lib/explorer/doc-utils'
import { addRecent, isOnboarded, loadFavs, loadRecents, markOnboarded, toggleFav as favToggle, type RecentSearch } from '@/lib/explorer/favorites'
import { isTypingTarget, mapKey } from '@/lib/explorer/keyboard'
import { findDupes, parseQueryTerm, type DupPair, type SearchField } from '@/lib/explorer/search-parse'
import { parseHash, urlFor, type ExplorerUrlState } from '@/lib/explorer/url-state'
import { buildCsv, buildJsonl, downloadText } from '@/lib/explorer/export'
import { buildColumns, emptyRate, numericMax, type ColumnCtx, type EditingTarget } from '@/components/explorer/cells'
import { DetailSheet } from '@/components/explorer/detail-sheet'
import { ExplorerDialogs } from '@/components/explorer/dialogs'
import { BulkBar } from '@/components/explorer/bulk-bar'
import { ColumnsMenu, FacetMenu, FilterChips, PREBUILT_IDS, RangesRow, SearchBox, ViewsMenu, type Ranges, type SavedQuery } from '@/components/explorer/toolbar'
import '@/components/explorer/explorer.css'

const PREFERRED_COLS = ['title', 'reference_ids', 'format', 'status', 'episodes', 'start_date', 'season', 'genres']

const EMPTY_STATES: Record<string, { title: string; desc: string }> = {
  no_mal: { title: 'No rows missing MAL', desc: 'Every row here has a MAL reference. Try a different filter or collection.' },
  no_al: { title: 'No rows missing AniList', desc: 'Every row here has an AniList reference. Try a different filter or collection.' },
  no_k: { title: 'No rows missing Kitsu', desc: 'Every row here has a Kitsu reference. Try a different filter or collection.' },
  has_mal: { title: 'No rows with MAL', desc: 'Nothing on this page carries a MAL reference yet.' },
  no_refs: { title: 'No rows without refs', desc: 'Every row here has at least one external reference.' },
  empty_title: { title: 'No untitled rows', desc: 'Every row on this page has a title.' },
  pinned: { title: 'No pinned rows here', desc: 'Pin rows with the 📌 button — they show up when the Pinned filter is on.' },
}

interface SheetState { doc: ExploreRow; collection: string }

function eqPrefs(): { zebra: boolean; mono: boolean; showSource: boolean } {
  try {
    const j = JSON.parse(localStorage.getItem('hiyori-explorer-eq') ?? '')
    return { zebra: j?.zebra !== false, mono: !!j?.mono, showSource: j?.showSource !== false }
  } catch {
    return { zebra: true, mono: false, showSource: true }
  }
}

export function ExplorerPage() {
  const urlInit = useMemo(() => parseHash(window.location.hash), [])

  const [meta, setMeta] = useState<Record<string, { count?: number; fields?: string[] }>>({})
  const [collection, setCollection] = useState(urlInit.collection || 'anilist')
  const [q, setQ] = useState(urlInit.q ?? '')
  const [qDebounced, setQDebounced] = useState(urlInit.q ?? '')
  const [field, setField] = useState<SearchField>((urlInit.field as SearchField) || 'all')
  const [filters, setFilters] = useState<string[]>(urlInit.filters ?? [])
  const [ranges, setRanges] = useState<Ranges>({ epsMin: urlInit.epsMin ?? '', epsMax: urlInit.epsMax ?? '', yearMin: urlInit.yearMin ?? '', yearMax: urlInit.yearMax ?? '' })
  const [rangesOpen, setRangesOpen] = useState(false)
  const [sort, setSort] = useState(urlInit.sort ?? '')
  const [dir, setDir] = useState<'asc' | 'desc'>(urlInit.dir ?? 'asc')
  const [page, setPage] = useState(urlInit.page ?? 1)
  const [per, setPer] = useState(urlInit.per ?? 25)
  const [cols, setCols] = useState<string[]>(urlInit.cols ?? [])
  const [view, setView] = useState(urlInit.view ?? '')

  const [data, setData] = useState<{ total?: number; docs?: ExploreRow[]; fields?: string[] } | null>(null)
  const [facets, setFacets] = useState<Record<string, Array<{ v: string; n: number }>> | null>(null)
  const [loading, setLoading] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [sel, setSel] = useState<Set<string>>(new Set())
  const [sheet, setSheet] = useState<SheetState | null>(null)
  const [confirmDel, setConfirmDel] = useState<string[] | null>(null)
  const [diffPair, setDiffPair] = useState<ExploreRow[]>([])
  const [dupOpen, setDupOpen] = useState<DupPair[] | null>(null)
  const [bulkSetOpen, setBulkSetOpen] = useState(false)
  const [bulkBusy, setBulkBusy] = useState(false)
  const [sheetEditOpen, setSheetEditOpen] = useState(false)
  const [saved, setSaved] = useState<SavedQuery[]>(() => {
    try { return JSON.parse(localStorage.getItem('hiyori-saved-queries') ?? '[]') } catch { return [] }
  })
  const [queryName, setQueryName] = useState('')
  const [resolver, setResolver] = useState('')
  const [favs, setFavs] = useState<Set<string>>(() => loadFavs(urlInit.collection || 'anilist'))
  const [recents, setRecents] = useState<RecentSearch[]>(() => loadRecents())
  const [editing, setEditing] = useState<EditingTarget | null>(null)
  const [activeIndex, setActiveIndex] = useState(-1)
  const [eq, setEq] = useState<{ zebra: boolean; mono: boolean; showSource: boolean }>(() => eqPrefs())
  const [onboarded, setOnboarded] = useState<boolean>(() => isOnboarded())

  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const seq = useRef(0)
  const colsInit = useRef((urlInit.cols?.length ?? 0) > 0)
  const skipUrlWrite = useRef(true)
  const pendingOpen = useRef<string | null>(urlInit.doc || null)
  const gRef = useRef(false)
  const searchRef = useRef<HTMLInputElement>(null)
  const tabsRef = useRef<HTMLDivElement>(null)
  const gridWrapRef = useRef<HTMLDivElement>(null)
  const cancelExport = useRef(false)
  const modalOpenRef = useRef(false)

  const collections = useMemo(() => Object.keys(meta), [meta])
  const docs = data?.docs ?? []
  const fields = data?.fields ?? []
  const total = data?.total ?? 0

  // ---------- pinned client filter (must precede the keyboard effect) ----------
  const pinnedActive = filters.includes('pinned')
  const visibleDocs = useMemo(() => {
    if (!pinnedActive) return docs
    return docs.filter(d => favs.has(stringId(d)) || favs.has(String((d._id as { $oid?: unknown } | undefined)?.$oid ?? d._id ?? '')))
  }, [docs, pinnedActive, favs])
  const totalShown = pinnedActive ? visibleDocs.length : total

  const persistEq = (next: typeof eq) => {
    setEq(next)
    try { localStorage.setItem('hiyori-explorer-eq', JSON.stringify(next)) } catch { /* ignore */ }
  }

  // ---------- meta / collection bootstrapping ----------
  useEffect(() => {
    let on = true
    void apiFetchMeta().then(m => {
      if (!on) return
      const colsMap = m.collections ?? {}
      setMeta(colsMap)
      if (!urlInit.collection) {
        const preferred = Object.keys(colsMap).find(c => !['organized', 'manga_organized'].includes(c)) ?? 'anilist'
        setCollection(preferred)
        setFavs(loadFavs(preferred))
      }
    })
    return () => { on = false }
  }, [urlInit.collection])

  useEffect(() => {
    if (collection) setFavs(loadFavs(collection))
  }, [collection])

  // ---------- URL hash sync (replaceState) ----------
  useEffect(() => {
    if (skipUrlWrite.current) { skipUrlWrite.current = false; return }
    const state: ExplorerUrlState = {
      collection, q, field, filters, sort, dir, page, per, cols, view,
      doc: sheet ? stringId(sheet.doc) : '',
      epsMin: ranges.epsMin, epsMax: ranges.epsMax, yearMin: ranges.yearMin, yearMax: ranges.yearMax,
    }
    try { history.replaceState(null, '', urlFor(state)) } catch { /* ignored */ }
  }, [collection, q, field, filters, sort, dir, page, per, cols, view, sheet, ranges])

  // ---------- deep link: open sheet when #doc=... appears on load / hash edits ----------
  const openDocById = useCallback(async (id: string) => {
    const found = docs.find(d => stringId(d) === id)
    if (found) { setSheet({ doc: found, collection }); return }
    const res = await explore({ collection, page: 1, per: 1, q: id })
    const doc = (res.docs ?? []).find(d => stringId(d) === id || titleOf(d).toLowerCase().includes(id.toLowerCase()))
    if (doc) setSheet({ doc, collection })
    else toast.error(`document ${id} not found on ${collection}`)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [docs, collection])

  useEffect(() => {
    const onHash = () => {
      const parsed = parseHash(window.location.hash)
      if (parsed.doc && parsed.doc !== (sheet ? stringId(sheet.doc) : '')) {
        void openDocById(parsed.doc)
      }
    }
    window.addEventListener('hashchange', onHash)
    window.addEventListener('popstate', onHash)
    return () => { window.removeEventListener('hashchange', onHash); window.removeEventListener('popstate', onHash) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sheet])

  // ---------- debounce q → 300ms ----------
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      setQDebounced(q)
      const parsed = parseQueryTerm(q)
      if (parsed.term.trim()) setRecents(addRecent(q, parsed.field))
    }, 300)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [q])

  // ---------- page reset + fetch (race-guarded) ----------
  const filtersKey = filters.join(',')
  const rangesKey = JSON.stringify(ranges)
  useEffect(() => { setPage(1) }, [collection, qDebounced, field, filtersKey, rangesKey])

  useEffect(() => {
    const id = ++seq.current
    setLoading(true)
    const prebuilt = filters.filter(f => PREBUILT_IDS.has(f))
    const facetFormat = filters.find(f => f.startsWith('format:'))?.slice(7)
    const facetStatus = filters.find(f => f.startsWith('status:'))?.slice(7)
    void explore({
      collection, page, per,
      q: qDebounced, field,
      filter: prebuilt[0] ?? undefined,
      filters: filters.length > 1 ? filters.join(',') : undefined,
      format: facetFormat, status: facetStatus,
      sort, dir,
      facets: 'format,status',
      epsMin: ranges.epsMin, epsMax: ranges.epsMax, yearMin: ranges.yearMin, yearMax: ranges.yearMax,
    }).then(res => {
      if (seq.current !== id) return
      setData({ total: res.total, docs: res.docs, fields: res.fields })
      setFacets(res.facets ?? null)
      if (res.fields && !colsInit.current) {
        colsInit.current = true
        setCols(PREFERRED_COLS.filter(f => res.fields?.includes(f))
          .concat((res.fields ?? []).filter(f => !PREFERRED_COLS.includes(f)))
          .slice(0, 6))
      }
      if (pendingOpen.current) {
        const pid = pendingOpen.current
        pendingOpen.current = null
        const doc = (res.docs ?? []).find(d => stringId(d) === pid)
        if (doc) setSheet({ doc, collection })
        else void openDocById(pid)
      }
      setLoading(false)
    }).catch(() => { if (seq.current === id) setLoading(false) })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [collection, page, per, qDebounced, field, filtersKey, sort, dir, rangesKey])

  // ---------- modal bookkeeping for keyboard ----------
  const modalOpen = !!(sheet || confirmDel || diffPair.length >= 2 || dupOpen || bulkSetOpen || sheetEditOpen)
  useEffect(() => { modalOpenRef.current = modalOpen }, [modalOpen])

  // ---------- keyboard ----------
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if (isTypingTarget(e.target)) return
      const a = mapKey(e)
      if (!a) return
      if (a.type === 'g') { gRef.current = true; window.setTimeout(() => { gRef.current = false }, 900); return }
      if (a.type === 'ge') {
        if (!gRef.current) return
        gRef.current = false
        tabsRef.current?.focus()
        tabsRef.current?.scrollIntoView({ block: 'nearest' })
        return
      }
      if (modalOpenRef.current) return
      switch (a.type) {
        case 'focus-search': e.preventDefault(); searchRef.current?.focus(); break
        case 'row': {
          e.preventDefault()
          const n = visibleDocs.length
          if (!n) break
          setActiveIndex(i => {
            if (i < 0) return a.delta > 0 ? 0 : n - 1
            return Math.max(0, Math.min(n - 1, i + a.delta))
          })
          break
        }
        case 'open-active': {
          e.preventDefault()
          const d = activeIndex >= 0 ? visibleDocs[activeIndex] : undefined
          if (d) { setSheet({ doc: d, collection }); setActiveIndex(-1) }
          break
        }
        case 'close':
          setActiveIndex(-1)
          break
        case 'copy-selected':
          if (sel.size > 0) {
            e.preventDefault()
            void navigator.clipboard.writeText([...sel].join(','))
            toast(`copied ${sel.size} id${sel.size === 1 ? '' : 's'}`)
          }
          break
      }
    }
    window.addEventListener('keydown', h)
    return () => window.removeEventListener('keydown', h)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visibleDocs, activeIndex, sel, modalOpen, collection])

  // clamp + highlight active row
  useEffect(() => {
    setActiveIndex(i => (i < 0 ? i : Math.min(i, Math.max(0, visibleDocs.length - 1))))
  }, [visibleDocs])
  useEffect(() => {
    const root = gridWrapRef.current
    if (!root) return
    const rowsEl = root.querySelectorAll('tbody tr')
    rowsEl.forEach((r, i) => {
      if (activeIndex >= 0 && i === activeIndex) {
        r.classList.add('explorer-row-active')
        ;(r as HTMLElement).scrollIntoView({ block: 'nearest' })
      } else {
        r.classList.remove('explorer-row-active')
      }
    })
  }, [activeIndex, visibleDocs, loading])

  // ---------- columns ----------
  const colStats = useMemo(() => {
    const out: Record<string, number> = {}
    for (const f of fields) out[f] = emptyRate(visibleDocs, f)
    return out
  }, [fields, visibleDocs])
  const maxOf = useCallback((key: string) => numericMax(visibleDocs, key), [visibleDocs])

  const onOpenRefCb = useCallback((src: string, id: string) => {
    if (!collections.includes(src)) { toast('collection not mounted'); return }
    setCollection(src)
    setFilters([])
    setField('all')
    setQ(id)
    setPage(1)
    setSheet(null)
    pendingOpen.current = id
  }, [collections])

  const onCommitEditCb = useCallback(async (id: string, key: string, value: string) => {
    setEditing(null)
    let oldVal: unknown = undefined
    setData(prev => {
      if (!prev) return prev
      const arr = prev.docs ?? []
      const target = arr.find(d => stringId(d) === id)
      if (!target) return prev
      oldVal = target[key]
      const nextDoc = { ...target, [key]: coerceScalar(value, target[key]) }
      return { ...prev, docs: arr.map(d => (stringId(d) === id ? nextDoc : d)) }
    })
    setSheet(s => (s && stringId(s.doc) === id ? { ...s, doc: { ...s.doc, [key]: coerceScalar(value, s.doc[key]) } } : s))
    const res = await updateDoc(collection, id, key, value)
    if (res.error) {
      toast.error(`update failed: ${res.error}`)
      setData(prev => prev ? { ...prev, docs: (prev.docs ?? []).map(d => (stringId(d) === id ? { ...d, [key]: oldVal } : d)) } : prev)
      setSheet(s => (s && stringId(s.doc) === id ? { ...s, doc: { ...s.doc, [key]: oldVal } } : s))
    } else {
      toast.success(`updated ${key}`, undoOptions)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [collection])

  const undoOptions = {
    action: {
      label: 'Undo',
      onClick: () => {
        void undoExplore().then(r => {
          if (r.error) toast.error(`undo failed: ${r.error}`)
          else { toast.success('undone'); setSel(new Set()); forceRefetch() }
        })
      },
    },
  }

  const forceRefetch = useCallback(() => {
    const prebuilt = filters.filter(f => PREBUILT_IDS.has(f))
    void explore({
      collection, page, per, q: qDebounced, field,
      filter: prebuilt[0] ?? undefined,
      filters: filters.length > 1 ? filters.join(',') : undefined,
      sort, dir,
    }).then(res => setData({ total: res.total, docs: res.docs, fields: res.fields }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [collection, page, per, qDebounced, field, filtersKey, sort, dir])

  const columns = useMemo<ColumnDefList>(() => {
    const ctx: ColumnCtx = {
      docs: visibleDocs,
      favs,
      collections,
      collection,
      onToggleFav: id => setFavs(prev => favToggle(collection, id, prev)),
      onOpenDoc: d => setSheet({ doc: d, collection }),
      onOpenRef: onOpenRefCb,
      editing,
      onStartEdit: (d, key) => setEditing({ id: stringId(d), key }),
      onCommitEdit: onCommitEditCb,
      onCancelEdit: () => setEditing(null),
      diffPair,
      onToggleDiff: d => setDiffPair(p => (p.includes(d) ? p.filter(x => x !== d) : [...p, d])),
      showSource: eq.showSource,
      mono: eq.mono,
      maxOf,
      onRequestDelete: ids => setConfirmDel(ids),
    }
    return buildColumns(cols, ctx)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cols, visibleDocs, favs, collections, collection, editing, diffPair, eq, maxOf])

  // ---------- delete ----------
  const done = async (ids: string[]) => {
    const r = await apiDeleteDocs(collection, ids)
    toast(`deleted ${r.deleted ?? 0} docs`, undoOptions)
    setSel(s => { const n = new Set(s); ids.forEach(i => n.delete(i)); return n })
    setConfirmDel(null)
    if (sheet && ids.includes(stringId(sheet.doc))) setSheet(null)
    forceRefetch()
  }

  // ---------- saved queries ----------
  const saveQuery = () => {
    if (!queryName.trim()) { toast('name the query'); return }
    const item: SavedQuery = { name: queryName.trim(), collection, q, filters }
    const next = [...saved.filter(s => s.name !== item.name), item]
    setSaved(next)
    localStorage.setItem('hiyori-saved-queries', JSON.stringify(next))
    setQueryName('')
    toast.success(`saved “${item.name}”`)
  }
  const loadQuery = (s: SavedQuery) => {
    setCollection(s.collection || collection)
    setQ(s.q)
    setFilters(s.filters ?? [])
    setField('all')
    setView(s.name)
    setPage(1)
  }

  // ---------- ref resolver ----------
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
    setFilters([])
    toast(`resolving ${targetSrc} #${id}`)
  }

  // ---------- export ----------
  const exportCSV = () => {
    const headings = cols.length ? cols : fields
    downloadText(buildCsv(visibleDocs, headings), 'text/csv;charset=utf-8', `hiyori-${collection}-p${page}.csv`)
    toast.success(`exported ${visibleDocs.length} rows`)
  }

  const exportAll = async (format: 'csv' | 'jsonl') => {
    if (exporting) return
    setExporting(true)
    cancelExport.current = false
    const big = total > 10000
    let toastId: string | number | undefined
    if (big) toastId = toast.loading('export starting…', { description: '0 rows' })
    const rows: ExploreRow[] = []
    const pageFields: string[] = []
    let totalN = total
    const prebuilt = filters.filter(f => PREBUILT_IDS.has(f))
    try {
      for (let p = 1; ; p++) {
        if (cancelExport.current) break
        const res = await explore({ collection, page: p, per: 500, q: qDebounced, field, filter: prebuilt[0] ?? undefined, sort, dir })
        totalN = res.total ?? totalN
        if (res.fields?.length && pageFields.length === 0) pageFields.push(...res.fields)
        const pageDocs = res.docs ?? []
        rows.push(...pageDocs)
        if (big && toastId !== undefined) {
          const pct = totalN > 0 ? Math.min(99, Math.round((rows.length / totalN) * 100)) : Math.min(99, Math.round(rows.length / 500))
          toast.loading(`exporting ${Math.round(pct)}%`, { id: toastId, description: `${rows.length.toLocaleString()} rows` })
        }
        if (cancelExport.current) break
        if (rows.length >= totalN || pageDocs.length === 0 || pageDocs.length < 500) break
      }
    } catch {
      if (big && toastId !== undefined) toast.error('export failed', { id: toastId })
      else toast.error('export failed')
      setExporting(false)
      return
    }
    setExporting(false)
    if (cancelExport.current) {
      if (big && toastId !== undefined) toast.dismiss(toastId)
      toast.info(`export cancelled at ${rows.length.toLocaleString()} rows`)
      return
    }
    if (rows.length === 0) {
      if (big && toastId !== undefined) toast.dismiss(toastId)
      toast('nothing to export')
      return
    }
    const filename = `hiyori-${collection}-all.${format}`
    if (format === 'csv') {
      const headings = cols.length ? cols : (pageFields.length ? pageFields : fields)
      downloadText(buildCsv(rows, headings), 'text/csv;charset=utf-8', filename)
    } else {
      downloadText(buildJsonl(rows), 'application/x-ndjson', filename)
    }
    if (big && toastId !== undefined) toast.success(`exported ${rows.length.toLocaleString()} rows`, { id: toastId })
    else toast.success(`exported ${rows.length.toLocaleString()} rows`)
  }

  // ---------- diff ----------
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

  // ---------- bulk ----------
  const selDocs = useMemo(() => visibleDocs.filter(d => sel.has(stringId(d))), [visibleDocs, sel])
  const scalarFields = useMemo(() => {
    const first = visibleDocs.find(d => Object.values(d).some(isScalar))
    return first ? Object.keys(first) : []
  }, [visibleDocs])

  const exportSel = () => {
    const headings = cols.length ? cols : fields
    downloadText(buildCsv(selDocs, headings), 'text/csv;charset=utf-8', `hiyori-${collection}-sel.csv`)
    toast.success(`exported ${selDocs.length} rows`)
  }
  const copySelJson = () => { void navigator.clipboard.writeText(JSON.stringify(selDocs, null, 2)); toast('copied JSON') }
  const copySelIds = () => {
    const ids = selDocs.map(stringId).filter(Boolean)
    void navigator.clipboard.writeText(ids.join(','))
    toast(`copied ${ids.length} ids`)
  }

  const runDupScan = () => {
    if (selDocs.length === 0) { toast('select at least one row'); return }
    setDupOpen(findDupes(selDocs, visibleDocs, 0.86))
  }

  const applyBulkSet = async (key: string, value: string) => {
    const ids = selDocs.map(stringId).filter(Boolean)
    setBulkBusy(true)
    const res = await bulkAction(collection, ids, 'set', key, value)
    setBulkBusy(false)
    setBulkSetOpen(false)
    if (res.error) { toast.error(`bulk update failed: ${res.error}`); return }
    toast.success(`set ${res.count ?? ids.length} rows`, undoOptions)
    setSel(new Set())
    forceRefetch()
  }

  // ---------- sheet edit ----------
  const [sheetEditField, setSheetEditField] = useState('')
  const [sheetEditValue, setSheetEditValue] = useState('')
  const openSheetEdit = () => {
    if (!sheet) return
    const keys = Object.keys(sheet.doc).filter(k => isScalar(sheet.doc[k]))
    const first = keys.find(k => k !== '_id' && k !== 'reference_ids' && k !== 'references') ?? keys[0] ?? ''
    if (!first) { toast('no editable fields in this document'); return }
    setSheetEditField(first)
    setSheetEditValue(sheet.doc[first] === null || sheet.doc[first] === undefined ? '' : String(sheet.doc[first]))
    setSheetEditOpen(true)
  }
  const applySheetEdit = async () => {
    if (!sheet) return
    if (!sheetEditField) { toast('pick a field to edit'); return }
    await onCommitEditCb(stringId(sheet.doc), sheetEditField, sheetEditValue)
    setSheetEditOpen(false)
  }

  // ---------- TanStack bridges ----------
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
    if (next.pageSize !== per) { setPer(next.pageSize); setPage(1) }
    else if (next.pageIndex + 1 !== page) { setPage(next.pageIndex + 1) }
  }, [paginationState, per, page])

  // ---------- empty state & facets ----------
  const clearAllFilters = () => {
    setQ(''); setFilters([]); setRanges({ epsMin: '', epsMax: '', yearMin: '', yearMax: '' }); setField('all')
  }
  const hasActiveFilters = q !== '' || filters.length > 0 || Object.values(ranges).some(Boolean)
  const emptyState = useMemo(() => {
    const key = filters.find(f => EMPTY_STATES[f])
    const st = EMPTY_STATES[key ?? ''] ?? (q ? { title: 'No search results', desc: `Nothing matches “${q}”.` } : null)
    return (
      <EmptyState
        icon={Database}
        title={st?.title ?? 'No documents match'}
        description={total === 0 ? (st?.desc ?? 'Nothing in this collection for the current filters.') : 'Narrow your search or clear filters.'}
        action={hasActiveFilters ? clearAllFilters : undefined}
        actionLabel="Clear filters"
      />
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, q, total, hasActiveFilters])

  const facetPick = (axis: 'format' | 'status', v: string) => {
    const chip = `${axis}:${v}`
    setFilters(f => (f.includes(chip) ? f.filter(x => x !== chip) : [...f, chip]))
  }

  // ---------- render ----------
  return (
    <TooltipProvider delay={300}>
      <div className="space-y-4">
        <PageHead
          title="Explorer"
          desc="Browse MongoDB collections — search, filter, inspect, compare."
          actions={
            <>
              <div className="flex items-center gap-1.5">
                <Input value={resolver} onChange={e => setResolver(e.target.value)} placeholder="paste URL or src/id…" className="h-8 w-52" />
                <Button size="sm" variant="outline" onClick={resolveRef}><Link2 className="mr-1.5 h-3.5 w-3.5" />Resolve</Button>
              </div>
              <DropdownMenu>
                <DropdownMenuTrigger render={<Button size="sm" variant="outline" disabled={!visibleDocs.length && !total}><Download className="mr-1.5 h-3.5 w-3.5" />Export</Button>} />
                <DropdownMenuContent align="end">
                  <DropdownMenuLabel>Export</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={exportCSV} disabled={!visibleDocs.length}>Export page (CSV)</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => void exportAll('csv')} disabled={exporting || !total}>Export all matching (CSV)</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => void exportAll('jsonl')} disabled={exporting || !total}>Export all matching (JSONL)</DropdownMenuItem>
                  {exporting && <DropdownMenuItem onClick={() => { cancelExport.current = true }}>Cancel export</DropdownMenuItem>}
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          }
        />

        {/* collection switch (g e focuses this) */}
        <div ref={tabsRef} tabIndex={-1} className="collection-tabs flex flex-wrap items-center gap-2 outline-none" role="group" aria-label="Collections">
          {collections.map(c => {
            const brand = brandOf(c)
            const active = collection === c
            return (
              <button
                key={c}
                onClick={() => { setCollection(c); setPage(1) }}
                className={cn('flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm', active ? 'border-primary/60 bg-primary/10 font-medium' : 'text-muted-foreground hover:bg-muted/40')}
              >
                {brand && <span className="brand-chip" style={{ background: brand.color }}>{brand.label}</span>}
                <span className="max-w-[140px] truncate">{c}</span>
                <span className="ml-1 text-xs tabular-nums text-muted-foreground">{meta[c]?.count?.toLocaleString() ?? '…'}</span>
              </button>
            )
          })}
        </div>

        {/* view options */}
        <div className="flex items-center gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Rows3 className="mr-1.5 h-3.5 w-3.5" />View</Button>} />
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Table options</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuCheckboxItem checked={eq.zebra} onCheckedChange={v => persistEq({ ...eq, zebra: v })}>
                <Rows3 className="h-3.5 w-3.5" />Zebra rows
              </DropdownMenuCheckboxItem>
              <DropdownMenuCheckboxItem checked={eq.mono} onCheckedChange={v => persistEq({ ...eq, mono: v })}>
                <AlignJustify className="h-3.5 w-3.5" />Mono id/ref columns
              </DropdownMenuCheckboxItem>
              <DropdownMenuCheckboxItem checked={eq.showSource} onCheckedChange={v => persistEq({ ...eq, showSource: v })}>
                <CircleDot className="h-3.5 w-3.5" />Source dots
              </DropdownMenuCheckboxItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <div ref={gridWrapRef}>
          <DataTable<ExploreRow>
            columns={columns}
            data={visibleDocs}
            getRowId={(d, i) => stringId(d) || `row-${i}`}
            manualSorting
            sorting={sortingState}
            onSortingChange={handleSortingChange}
            manualPagination
            pagination={paginationState}
            onPaginationChange={handlePaginationChange}
            pageCount={Math.max(1, Math.ceil(totalShown / per))}
            enableSelection
            selected={sel}
            onSelectionChange={setSel}
            onRowClick={d => setSheet({ doc: d, collection })}
            maxHeight="62vh"
            loading={loading}
            resizable
            ariaLabel="Explorer collection"
            multiSort
            stickySelection
            className={cn('explorer-grid', eq.zebra && 'zebra')}
            empty={emptyState}
            footerLeft={
              <span className="flex items-center gap-2">
                <span>{totalShown.toLocaleString()} docs in <code className="rounded bg-muted px-1">{collection}</code></span>
                {pinnedActive && <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">pinned ({favs.size})</span>}
                {filters.length > 1 && (
                  <span title="Combined filters are sent as a list — older backends apply the first filter only." className="cursor-help underline decoration-dotted">multi-filter</span>
                )}
                {view && view !== '__all' && <span className="text-muted-foreground">· view: {view}</span>}
              </span>
            }
            toolbar={
              <>
                <SearchBox
                  q={q}
                  onQ={setQ}
                  field={field}
                  onField={f => setField(f)}
                  resultCount={totalShown}
                  loading={loading && !docs.length}
                  recents={recents}
                  onPickRecent={r => { setQ(r.q); setField(r.field as SearchField) }}
                  inputRef={searchRef}
                />
                <FilterChips
                  filters={filters}
                  onAdd={f => setFilters(fs => [...fs, f])}
                  onRemove={f => setFilters(fs => fs.filter(x => x !== f))}
                  onClear={() => setFilters([])}
                  extra={favs.size > 0 ? [{ id: 'pinned', label: `Pinned (${favs.size})` }] : []}
                />
                {facets?.format && <FacetMenu title="Format" groups={facets.format} onPick={v => facetPick('format', v)} />}
                {facets?.status && <FacetMenu title="Status" groups={facets.status} onPick={v => facetPick('status', v)} />}
                <ColumnsMenu
                  fields={fields}
                  cols={cols}
                  onToggle={f => setCols(c => (c.includes(f) ? c.filter(x => x !== f) : [...c, f]))}
                  onReset={() => setCols(PREFERRED_COLS.filter(f => fields.includes(f)).slice(0, 6))}
                  emptyStats={colStats}
                />
                <ViewsMenu
                  saved={saved}
                  onLoad={loadQuery}
                  onDelete={name => {
                    const n = saved.filter(s => s.name !== name)
                    setSaved(n)
                    localStorage.setItem('hiyori-saved-queries', JSON.stringify(n))
                  }}
                  queryName={queryName}
                  onQueryName={setQueryName}
                  onSave={saveQuery}
                />
                <RangesRow open={rangesOpen} onToggle={() => setRangesOpen(o => !o)} ranges={ranges} onRanges={setRanges} />
              </>
            }
          />
        </div>

        {/* bulk actions (sticky bottom) */}
        <BulkBar
          count={sel.size}
          onClear={() => setSel(new Set())}
          onExportCsv={exportSel}
          onCopyIds={copySelIds}
          onCopyJson={copySelJson}
          onDelete={() => setConfirmDel([...sel])}
          onBulkSet={() => setBulkSetOpen(true)}
          onDupScan={runDupScan}
        />

        {/* onboarding hint */}
        {!onboarded && (
          <div className="relative z-10 max-w-sm rounded-xl border border-primary/30 bg-primary/5 p-3 text-xs shadow-sm">
            <button type="button" className="absolute top-2 right-2 cursor-pointer text-muted-foreground hover:text-foreground" aria-label="Dismiss tip" onClick={() => { setOnboarded(true); markOnboarded() }}><X className="h-3.5 w-3.5" /></button>
            <p className="font-medium">Explorer tips</p>
            <p className="mt-1 text-muted-foreground">
              <kbd className="rounded bg-muted px-1">/</kbd> search · <kbd className="rounded bg-muted px-1">j</kbd><kbd className="rounded bg-muted px-1">k</kbd> move rows · <kbd className="rounded bg-muted px-1">Enter</kbd> inspect · <kbd className="rounded bg-muted px-1">c</kbd> copy ids · <kbd className="rounded bg-muted px-1">g e</kbd> switch collection
            </p>
          </div>
        )}

        {/* detail sheet */}
        <DetailSheet
          doc={sheet?.doc ?? null}
          collection={sheet?.collection ?? collection}
          collections={collections}
          onOpenChange={d => { setSheet(d ? { doc: d, collection } : null) }}
          onOpenRef={onOpenRefCb}
          onDelete={id => { setConfirmDel([id]); setSheet(null) }}
          onEdit={openSheetEdit}
        />

        {/* delete confirm + diff + dup scan + bulk set + sheet edit */}
        <ExplorerDialogs
          collection={collection}
          confirmDel={confirmDel}
          onConfirmDelChange={setConfirmDel}
          onDeleteConfirm={() => confirmDel && void done(confirmDel)}
          diffPair={diffPair}
          diffRows={diffRows}
          onDiffChange={setDiffPair}
          dupOpen={dupOpen}
          onDupChange={setDupOpen}
          onOpenDoc={d => setSheet({ doc: d, collection })}
          bulkSetOpen={bulkSetOpen}
          bulkBusy={bulkBusy}
          bulkFields={scalarFields}
          onBulkSetChange={setBulkSetOpen}
          onBulkApply={applyBulkSet}
          sheetEditOpen={sheetEditOpen}
          sheetDoc={sheet?.doc ?? null}
          sheetEditField={sheetEditField}
          sheetEditValue={sheetEditValue}
          onSheetEditFieldChange={f => {
            setSheetEditField(f)
            const cur = sheet?.doc[f]
            setSheetEditValue(cur === null || cur === undefined ? '' : String(cur))
          }}
          onSheetEditValueChange={setSheetEditValue}
          onSheetEditSave={() => void applySheetEdit()}
          onSheetEditClose={() => setSheetEditOpen(false)}
        />
      </div>
    </TooltipProvider>
  )
}

/** Column def list type alias to keep the page readable. */
type ColumnDefList = ReturnType<typeof buildColumns>

function coerceScalar(value: string, prev: unknown): unknown {
  if (typeof prev === 'number') {
    const n = Number(value)
    return Number.isFinite(n) ? n : prev
  }
  if (typeof prev === 'boolean') return value === 'true' ? true : value === 'false' ? false : prev
  return value
}