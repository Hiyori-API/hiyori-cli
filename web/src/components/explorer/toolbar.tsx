/**
 * Toolbar pieces for the Explorer: search box with an in-input scope popover
 * + recent searches, multi-filter chips (fed from the Views menu), column
 * picker with empty-rate, a single merged Views menu (prebuilt + saved views
 * + export-all + save-as), the numeric range row, facet menus and a small
 * '?' help popover.
 */

import { useEffect, useRef, useState } from 'react'
import { Bookmark, ChevronDown, CircleHelp, Cloud, FileDown, FileJson, Filter, ListFilter, Pin, Regex, Save, Search, Settings2, SlidersHorizontal, X } from 'lucide-react'
import { cn } from 'cn'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { fmtCount } from '@/lib/explorer/doc-utils'
import type { SavedView } from '@/lib/explorer/views'
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

export function filterLabel(id: string): string {
  return PREBUILTS.find(p => p.id === id)?.label ?? (id === 'pinned' ? 'Pinned' : id)
}

// ---------- search box ----------

export function SearchBox({ q, onQ, field, onField, resultCount, loading, recents, onPickRecent, inputRef, regex, onRegex }: {
  q: string
  onQ: (v: string) => void
  field: SearchField
  onField: (f: SearchField) => void
  resultCount: number | null
  loading: boolean
  recents: RecentSearch[]
  onPickRecent: (r: RecentSearch) => void
  inputRef: React.Ref<HTMLInputElement>
  regex: boolean
  onRegex: (v: boolean) => void
}) {
  const [focused, setFocused] = useState(false)
  const [fieldOpen, setFieldOpen] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)
  const cur = SEARCH_FIELDS.find(f => f.value === field) ?? SEARCH_FIELDS[0]

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) {
        setFocused(false)
        setFieldOpen(false)
      }
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
            if (e.key === 'Escape') { setFocused(false); setFieldOpen(false); (e.target as HTMLInputElement).blur() }
          }}
          placeholder="search title / refs / id…  ( / )"
          className="h-8 w-64 pl-7 pr-24"
          aria-label="Search"
        />
        <span className="pointer-events-none absolute top-1/2 right-[3.8rem] -translate-y-1/2 text-[10px] text-muted-foreground tabular-nums">
          {loading ? '…' : resultCount !== null ? fmtCount(resultCount) : ''}
        </span>
        <div className="absolute top-1/2 right-1 -translate-y-1/2">
          <button
            type="button"
            className="flex h-6 cursor-pointer items-center gap-0.5 rounded-md border border-transparent px-1.5 text-[10px] font-medium text-muted-foreground hover:border-border hover:bg-muted/50 hover:text-foreground"
            aria-label={`Search scope: ${cur.label}`}
            aria-expanded={fieldOpen}
            onClick={() => { setFocused(true); setFieldOpen(o => !o) }}
          >
            {cur.short}
            <ChevronDown className="h-3 w-3" />
          </button>
          {fieldOpen && (
            <div className="absolute top-8 right-0 z-40 w-40 rounded-lg border bg-popover p-1 text-sm shadow-lg">
              {SEARCH_FIELDS.map(f => (
                <button
                  key={f.value}
                  type="button"
                  className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-xs hover:bg-accent"
                  onClick={() => { onField(f.value); setFieldOpen(false) }}
                >
                  <span>{f.label}</span>
                  {f.value === field && <span className="text-[10px] text-primary">✓</span>}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
      <Tooltip>
        <TooltipTrigger render={
          <Button
            size="sm"
            variant={regex ? 'default' : 'outline'}
            aria-pressed={regex}
            title={regex ? 'Regex search on' : 'Regex search off'}
            className={cn('h-8 w-8 shrink-0 px-0', !regex && 'text-muted-foreground')}
            onClick={() => onRegex(!regex)}
          >
            <Regex className="h-3.5 w-3.5" />
          </Button>
        } />
        <TooltipContent>Regex search — treat the box as a regular expression. Requires server support; falls back to plain search if unsupported.</TooltipContent>
      </Tooltip>
      {focused && !fieldOpen && recents.length > 0 && !q && (
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

export function FilterChips({ filters, onRemove, onClear }: {
  filters: string[]
  onRemove: (f: string) => void
  onClear: () => void
}) {
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
      {filters.length === 0 && (
        <span className="text-xs text-muted-foreground">filters come from the Views menu</span>
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
        <DropdownMenuGroup>
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
      </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

// ---------- one merged views menu (prebuilt + saved + save-as + export-all) ----------

export function ViewsMenu({ saved, onLoad, onDelete, queryName, onQueryName, onSave, exporting, exportDisabled, onExportCsv, onExportJsonl, onCancelExport }: {
  saved: SavedView[]
  onLoad: (v: SavedView) => void
  onDelete: (name: string) => void
  queryName: string
  onQueryName: (v: string) => void
  onSave: () => void
  /** True while an export-all is in flight (shows cancel + disables re-export). */
  exporting: boolean
  /** Nothing to export (no matching rows). */
  exportDisabled: boolean
  onExportCsv: () => void
  onExportJsonl: () => void
  onCancelExport: () => void
}) {
  const prebuilt = (name: string, filter?: string): SavedView => ({
    name,
    state: { collection: '', q: '', field: 'all', filters: filter ? [filter] : [], per: 25, sort: '', dir: 'asc', cols: [] },
    source: 'local',
  })
  return (
    <DropdownMenu>
      <DropdownMenuTrigger render={<Button size="sm" variant="outline"><Bookmark className="mr-1.5 h-3.5 w-3.5" />Views</Button>} />
      <DropdownMenuContent align="end" className="max-h-96 overflow-auto">
        <DropdownMenuGroup>
        <DropdownMenuLabel>Prebuilt views</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => onLoad(prebuilt('__all'))}>
          <ListFilter className="h-3.5 w-3.5" />All entries
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => onLoad(prebuilt('Pinned', 'pinned'))}>
          <Pin className="h-3.5 w-3.5" />Pinned only
        </DropdownMenuItem>
        {PREBUILTS.map(p => (
          <DropdownMenuItem key={p.id} onClick={() => onLoad(prebuilt(p.id, p.id))}>
            <ListFilter className="h-3.5 w-3.5" />{p.label}
          </DropdownMenuItem>
        ))}
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
        <DropdownMenuLabel>Saved views</DropdownMenuLabel>
        {saved.length === 0 && <div className="px-2 py-1.5 text-xs text-muted-foreground">none saved</div>}
        {saved.map(s => (
          <div key={s.name} className="flex items-center gap-1 px-2 py-1">
            <button type="button" className="flex min-w-0 flex-1 cursor-pointer items-center gap-1.5 text-left text-sm hover:text-primary" onClick={() => onLoad(s)}>
              <span className="truncate">{s.name}</span>
              <span
                className={cn(
                  'inline-flex shrink-0 items-center gap-0.5 rounded px-1 py-px text-[9px] font-medium',
                  s.source === 'cloud' ? 'bg-sky-500/15 text-sky-500' : 'bg-muted text-muted-foreground'
                )}
                title={s.source === 'cloud' ? 'Saved on the server' : 'Saved locally (offline)'}
              >
                {s.source === 'cloud' && <Cloud className="h-2.5 w-2.5" />}
                {s.source === 'cloud' ? 'cloud' : 'local'}
              </span>
            </button>
            <button type="button" className="cursor-pointer text-muted-foreground hover:text-red-400" aria-label={`Delete saved view ${s.name}`} onClick={() => onDelete(s.name)}><X className="h-3 w-3" /></button>
          </div>
        ))}
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
        <DropdownMenuLabel>Export all matching</DropdownMenuLabel>
        <DropdownMenuItem disabled={exportDisabled || exporting} onClick={onExportCsv}>
          <FileDown className="h-3.5 w-3.5" />CSV
        </DropdownMenuItem>
        <DropdownMenuItem disabled={exportDisabled || exporting} onClick={onExportJsonl}>
          <FileJson className="h-3.5 w-3.5" />JSONL
        </DropdownMenuItem>
        {exporting && <DropdownMenuItem onClick={onCancelExport}><X className="h-3.5 w-3.5" />Cancel export</DropdownMenuItem>}
        </DropdownMenuGroup>
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
        <DropdownMenuGroup>
        <DropdownMenuLabel>{title} facets</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {groups.map(g => (
          <DropdownMenuItem key={g.v} onClick={() => onPick(g.v)}>
            <span className="flex w-full items-center gap-2">
              <span className="min-w-0 flex-1 truncate">{g.v || '(unknown)'}</span>
              <span className="text-[10px] text-muted-foreground tabular-nums">{fmtCount(g.n)}</span>
            </span>
          </DropdownMenuItem>
        ))}
      </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

// ---------- '?' help popover (replaces the onboarding tour) ----------

export function HelpPopover() {
  const [open, setOpen] = useState(false)
  const wrapRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  return (
    <div ref={wrapRef} className="relative">
      <Button size="sm" variant="outline" aria-label="Explorer help" aria-expanded={open} title="Explorer help" className="h-8 w-8 px-0" onClick={() => setOpen(o => !o)}>
        <CircleHelp className="h-3.5 w-3.5" />
      </Button>
      {open && (
        <div className="absolute top-9 right-0 z-40 w-64 rounded-lg border bg-popover p-3 text-xs shadow-lg">
          <p className="mb-2 font-medium text-foreground">Explorer help</p>
          <ul className="space-y-1.5 text-muted-foreground">
            <li><kbd className="rounded bg-muted px-1">/</kbd> focus search</li>
            <li><kbd className="rounded bg-muted px-1">j</kbd><kbd className="rounded bg-muted px-1">k</kbd> move row selection</li>
            <li><kbd className="rounded bg-muted px-1">Enter</kbd> inspect selected row</li>
            <li><kbd className="rounded bg-muted px-1">c</kbd> copy selected ids</li>
            <li><kbd className="rounded bg-muted px-1">g</kbd> <kbd className="rounded bg-muted px-1">e</kbd> switch collection</li>
            <li><kbd className="rounded bg-muted px-1">Esc</kbd> close sheet / dialogs</li>
          </ul>
          <p className="mt-2 text-muted-foreground">Filters &amp; pinned rows come from the <span className="font-medium text-foreground">Views</span> menu; edit a document inside its detail sheet.</p>
        </div>
      )}
    </div>
  )
}