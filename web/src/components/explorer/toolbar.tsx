/**
 * Toolbar pieces for the Explorer: search box with scope select + recent
 * searches, multi-filter chips, column picker with empty-rate, view menu
 * (prebuilt + saved queries) and the numeric range row.
 */

import { useEffect, useRef, useState } from 'react'
import { Bookmark, Filter, ListFilter, Save, Search, Settings2, SlidersHorizontal, X } from 'lucide-react'
import { cn } from 'cn'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { SEARCH_FIELDS, type SearchField } from '@/lib/explorer/search-parse'
import type { RecentSearch } from '@/lib/explorer/favorites'

export const PREBUILTS: Array<{ id: string; label: string }> = [
  { id: 'no_mal', label: 'Missing MAL ref' },
  { id: 'no_al', label: 'Missing AniList ref' },
  { id: 'no_k', label: 'Missing Kitsu ref' },
  { id: 'has_mal', label: 'Has MAL ref' },
  { id: 'no_refs', label: 'No refs at all' },
  { id: 'empty_title', label: 'Empty title' },
]

export const PREBUILT_IDS = new Set(PREBUILTS.map(p => p.id))

export interface SavedQuery { name: string; collection: string; q: string; filters: string[] }

export function filterLabel(id: string): string {
  return PREBUILTS.find(p => p.id === id)?.label ?? id
}

// ---------- search box ----------

export function SearchBox({ q, onQ, field, onField, resultCount, loading, recents, onPickRecent, inputRef }: {
  q: string
  onQ: (v: string) => void
  field: SearchField
  onField: (f: SearchField) => void
  resultCount: number | null
  loading: boolean
  recents: RecentSearch[]
  onPickRecent: (r: RecentSearch) => void
  inputRef: React.Ref<HTMLInputElement>
}) {
  const [focused, setFocused] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setFocused(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  return (
    <div ref={wrapRef} className="relative flex items-center gap-1.5">
      <div className="relative">
        <Search className="pointer-events-none absolute top-1/2 left-2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input
          ref={inputRef}
          value={q}
          onChange={e => onQ(e.target.value)}
          onFocus={() => setFocused(true)}
          onKeyDown={e => {
            if (e.key === 'Escape') { setFocused(false); (e.target as HTMLInputElement).blur() }
          }}
          placeholder="search title / refs / id…  ( / )"
          className="h-8 w-52 pl-7 pr-16"
          aria-label="Search"
        />
        <span className="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-[10px] text-muted-foreground tabular-nums">
          {loading ? '…' : resultCount !== null ? `${resultCount.toLocaleString()}` : ''}
        </span>
      </div>
      <Select value={field} onValueChange={v => onField((v ?? 'all') as SearchField)}>
        <SelectTrigger size="sm" className="h-8 w-[104px]" aria-label="Search scope">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {SEARCH_FIELDS.map(f => <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>)}
        </SelectContent>
      </Select>
      {focused && recents.length > 0 && !q && (
        <div className="absolute top-9 left-0 z-40 w-72 rounded-lg border bg-popover p-1.5 text-sm shadow-lg">
          <p className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Recent searches</p>
          {recents.map(r => (
            <button
              key={`${r.q}-${r.field}-${r.ts}`}
              type="button"
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs hover:bg-accent"
              onClick={() => onPickRecent(r)}
            >
              <Search className="h-3 w-3 shrink-0 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{r.q}</span>
              {r.field !== 'all' && <span className="shrink-0 rounded bg-muted px-1 py-0.5 font-mono text-[9px] text-muted-foreground">{r.field}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

// ---------- filter chips ----------

export function FilterChips({ filters, onAdd, onRemove, onClear, disabledIds, extra }: {
  filters: string[]
  onAdd: (f: string) => void
  onRemove: (f: string) => void
  onClear: () => void
  disabledIds?: (f: string) => boolean
  /** Extra ad-hoc filters (e.g. the 'pinned' favourite filter). */
  extra?: Array<{ id: string; label: string }>
}) {
  const avail = [...(extra ?? []), ...PREBUILTS].filter(p => !filters.includes(p.id))
  const [addVal, setAddVal] = useState('__select')
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {filters.map(f => (
        <span key={f} className="inline-flex items-center gap-1 rounded-full border border-primary/40 bg-primary/10 px-2 py-0.5 text-xs">
          {filterLabel(f)}
          <button type="button" className="cursor-pointer text-muted-foreground hover:text-foreground" aria-label={`Remove ${f} filter`} onClick={() => onRemove(f)}>
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}
      {avail.length > 0 ? (
        <Select value={addVal} onValueChange={v => { if (v && v !== '__select') { onAdd(v); setAddVal('__select') } }}>
          <SelectTrigger size="sm" className="h-7 text-xs" aria-label="Add filter">
            <SelectValue placeholder="+ filter" />
          </SelectTrigger>
          <SelectContent>
            {filters.length === 0 && <div className="px-2 py-1 text-xs text-muted-foreground">combine filters (e.g. no_mal + has_mal)</div>}
            {avail.map(p => (
              <SelectItem key={p.id} value={p.id} disabled={disabledIds?.(p.id)}>{p.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      ) : (
        <span className="text-xs text-muted-foreground">all filters active</span>
      )}
      {filters.length > 0 && (
        <button type="button" className="cursor-pointer text-xs text-muted-foreground hover:text-foreground" onClick={onClear}>
          clear all
        </button>
      )}
    </div>
  )
}

// ---------- columns menu ----------

export function ColumnsMenu({ fields, cols, onToggle, onReset, emptyStats }: {
  fields: string[]
  cols: string[]
  onToggle: (f: string) => void
  onReset: () => void
  emptyStats?: Record<string, number>
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger render={<Button size="sm" variant="outline" title="Visible columns"><Settings2 className="mr-1.5 h-3.5 w-3.5" />Columns</Button>} />
      <DropdownMenuContent align="end" className="min-w-56">
        <div className="flex items-center justify-between px-2 py-1">
          <DropdownMenuLabel className="p-0">Visible columns</DropdownMenuLabel>
          <button type="button" className="cursor-pointer text-[10px] text-muted-foreground hover:text-foreground" onClick={onReset}>reset</button>
        </div>
        <DropdownMenuSeparator />
        <div className="max-h-72 overflow-auto">
          {fields.map(f => {
            const pct = emptyStats?.[f] ?? 0
            return (
              <DropdownMenuCheckboxItem key={f} checked={cols.includes(f)} onCheckedChange={() => onToggle(f)}>
                <span className="flex w-full items-center gap-2 pr-6">
                  <span className="min-w-0 flex-1 truncate">{f}</span>
                  {pct > 0 && <span className={cn('shrink-0 text-[10px] tabular-nums', pct >= 50 ? 'text-amber-500' : 'text-muted-foreground')}>{pct}% empty</span>}
                </span>
              </DropdownMenuCheckboxItem>
            )
          })}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

// ---------- views menu (prebuilt + saved queries) ----------

export function ViewsMenu({ saved, onLoad, onDelete, queryName, onQueryName, onSave }: {
  saved: SavedQuery[]
  onLoad: (s: SavedQuery) => void
  onDelete: (name: string) => void
  queryName: string
  onQueryName: (v: string) => void
  onSave: () => void
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Bookmark className="mr-1.5 h-3.5 w-3.5" />Views</Button>} />
      <DropdownMenuContent align="end" className="max-h-96 overflow-auto">
        <DropdownMenuLabel>Prebuilt views</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => onLoad({ name: '__all', collection: '', q: '', filters: [] })}>
          <ListFilter className="h-3.5 w-3.5" />All entries
        </DropdownMenuItem>
        {PREBUILTS.map(p => (
          <DropdownMenuItem key={p.id} onClick={() => onLoad({ name: p.id, collection: '', q: '', filters: [p.id] })}>
            <ListFilter className="h-3.5 w-3.5" />{p.label}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuLabel>Saved queries</DropdownMenuLabel>
        {saved.length === 0 && <div className="px-2 py-1.5 text-xs text-muted-foreground">none saved</div>}
        {saved.map(s => (
          <div key={s.name} className="flex items-center gap-1 px-2 py-1">
            <button type="button" className="flex-1 cursor-pointer text-left text-sm hover:text-primary" onClick={() => onLoad(s)}>{s.name}</button>
            <button type="button" className="cursor-pointer text-muted-foreground hover:text-red-400" aria-label={`Delete saved query ${s.name}`} onClick={() => onDelete(s.name)}><X className="h-3 w-3" /></button>
          </div>
        ))}
        <DropdownMenuSeparator />
        <div className="flex items-center gap-1.5 px-2 py-1.5">
          <Input value={queryName} onChange={e => onQueryName(e.target.value)} placeholder="save current as…" className="h-7 text-xs" />
          <Button size="sm" variant="ghost" onClick={onSave}><Save className="h-3.5 w-3.5" /></Button>
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

// ---------- numeric ranges row ----------

export interface Ranges { epsMin: string; epsMax: string; yearMin: string; yearMax: string }

export function RangesRow({ open, onToggle, ranges, onRanges }: {
  open: boolean
  onToggle: () => void
  ranges: Ranges
  onRanges: (r: Ranges) => void
}) {
  const set = (k: keyof Ranges) => (e: { target: { value: string } }) => onRanges({ ...ranges, [k]: e.target.value.replace(/[^\d]/g, '') })
  const any = Object.values(ranges).some(Boolean)
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Tooltip>
        <TooltipTrigger render={<Button size="sm" variant={any ? 'default' : 'outline'} onClick={onToggle} className={cn(!open && any && 'border-primary/50')}><SlidersHorizontal className="mr-1.5 h-3.5 w-3.5" />Ranges{any ? ' ·' : ''}</Button>} />
        <TooltipContent>Filter by episodes / year. Sent as episodes_min…year_max — ignored by older backends.</TooltipContent>
      </Tooltip>
      {open && (
        <span className="flex flex-wrap items-center gap-1.5 rounded-lg border bg-muted/20 px-2 py-1.5">
          <span className="text-[10px] text-muted-foreground">Episodes</span>
          <Input className="h-7 w-16 text-xs" placeholder="min" aria-label="Min episodes" value={ranges.epsMin} onChange={set('epsMin')} />
          <span className="text-muted-foreground">–</span>
          <Input className="h-7 w-16 text-xs" placeholder="max" aria-label="Max episodes" value={ranges.epsMax} onChange={set('epsMax')} />
          <span className="ml-1 text-[10px] text-muted-foreground">Year</span>
          <Input className="h-7 w-16 text-xs" placeholder="min" aria-label="Min year" value={ranges.yearMin} onChange={set('yearMin')} />
          <span className="text-muted-foreground">–</span>
          <Input className="h-7 w-16 text-xs" placeholder="max" aria-label="Max year" value={ranges.yearMax} onChange={set('yearMax')} />
          {any && <button type="button" className="cursor-pointer text-muted-foreground hover:text-foreground" onClick={() => onRanges({ epsMin: '', epsMax: '', yearMin: '', yearMax: '' })}><X className="h-3 w-3" /></button>}
        </span>
      )}
    </div>
  )
}

// ---------- facets (rendered only when the backend returns them) ----------

export function FacetMenu({ title, groups, onPick }: {
  title: string
  groups: Array<{ v: string; n: number }>
  onPick: (v: string) => void
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Filter className="mr-1.5 h-3.5 w-3.5" />{title}</Button>} />
      <DropdownMenuContent align="end" className="max-h-72 overflow-auto">
        <DropdownMenuLabel>{title} facets</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {groups.map(g => (
          <DropdownMenuItem key={g.v} onClick={() => onPick(g.v)}>
            <span className="flex w-full items-center gap-2">
              <span className="min-w-0 flex-1 truncate">{g.v || '(unknown)'}</span>
              <span className="text-[10px] text-muted-foreground tabular-nums">{g.n.toLocaleString()}</span>
            </span>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}