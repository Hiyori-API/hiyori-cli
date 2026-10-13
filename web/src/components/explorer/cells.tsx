/**
 * Column defs + cell renderers for the Explorer DataTable.
 *
 * Handles numeric inline bars, lazy image thumbnails, expandable reference
 * accordions, clickable links, favorites (pin) toggle, source dots and the
 * row actions ⋮ menu (inspect / diff / pin / copy / delete). Editing lives in
 * the detail sheet, not in cells.
 */

import { safeCopy } from '../../lib/explorer/clipboard'
import { useState } from 'react'
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table'
import { Code2, Copy, ExternalLink, GitCompareArrows, ListFilter, MoreHorizontal, Pin, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from 'cn'
import type { ExploreRow } from '@/lib/api'
import type { DataTableFeatures } from '@/components/data-table/data-table'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import {
  brandOf, describeField, externalUrl, isImageUrl, isMissing, linkUrl, longestImage,
  refsOf, sourceCollection, stringId, titleOf, type Brand,
} from '@/lib/explorer/doc-utils'

const columnHelper = createColumnHelper<DataTableFeatures, ExploreRow>()

/** Numeric fields that get the tiny inline bar. */
const BAR_FIELDS = new Set([
  'episodes', 'episode_count', 'chapters', 'volumes', 'score', 'popularity',
  'favorites', 'count', 'total', 'ref_count', 'reference_count', 'mal_count', 'al_count', 'k_count',
])

/** id-ish columns rendered mono when the mono toggle is on. */
const ID_LIKE = /(^id$|^_id$|_id$|reference_ids|mal_|al_|k_|url|site)/i

export interface ColumnCtx {
  docs: ExploreRow[]
  favs: ReadonlySet<string>
  collections: string[] // collection names that exist in the app (for related-row nav)
  collection: string
  onToggleFav: (id: string) => void
  onOpenDoc: (d: ExploreRow) => void
  onOpenRef: (src: string, id: string) => void
  diffPair: ExploreRow[]
  onToggleDiff: (d: ExploreRow) => void
  showSource: boolean
  mono: boolean
  maxOf: (key: string) => number
  /** Raise the delete-confirm dialog for one or more ids. */
  onRequestDelete: (ids: string[]) => void
}

/** Best-effort source of a row: the collection brand, else inferred from refs. */
function rowSource(d: ExploreRow, collection: string): Brand | null {
  const direct = brandOf(collection)
  if (direct && ['anilist', 'kitsu', 'myanimelist', 'manga'].some(s => collection.toLowerCase().includes(s))) {
    return direct
  }
  for (const [sh] of refsOf(d)) {
    if (sh === 'al') return brandOf('anilist')
    if (sh === 'k') return brandOf('kitsu')
    if (sh === 'mal') return brandOf('myanimelist')
  }
  return direct
}

function prettyPreview(v: unknown, cap = 300): string {
  try {
    if (typeof v === 'string') return v.length > cap ? v.slice(0, cap) + '…' : v
    if (v === null || v === undefined) return ''
    if (typeof v === 'object') return JSON.stringify(v)
    return String(v)
  } catch {
    return String(v)
  }
}

// ---------- leaf renderers ----------

function NumCell({ value, max, mono, field }: { value: number; max: number; mono: boolean; field: string }) {
  const txt = <span className={cn('tabular-nums', mono && 'font-mono text-[11px]')}>{value.toLocaleString()}</span>
  if (!BAR_FIELDS.has(field)) return txt
  const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0
  return (
    <span className="num-bar">
      {txt}
      <span className="num-bar-track"><span className="num-bar-fill" style={{ width: `${pct}%` }} /></span>
    </span>
  )
}

function Thumb({ src }: { src: string }) {
  const [err, setErr] = useState(false)
  if (err) return <span className="text-muted-foreground text-[10px]">img</span>
  return <img loading="lazy" className="cell-thumb" src={src} alt="" onError={() => setErr(true)} />
}

function LinkCell({ url, mono }: { url: string; mono: boolean }) {
  return (
    <a
      href={url}
      target="_blank"
      rel="noreferrer"
      onClick={e => e.stopPropagation()}
      className={cn('inline-flex max-w-[220px] items-center gap-1 truncate text-primary underline-offset-2 hover:underline', mono && 'font-mono text-[11px]')}
    >
      <span className="truncate">{url.replace(/^https?:\/\//, '').slice(0, 40)}</span>
      <ExternalLink className="h-3 w-3 shrink-0" />
    </a>
  )
}

/** reference_ids → expandable shorthand chips. */
function RefAccordion({ v, collections, onOpenRef, mono, maxShow = 4 }: {
  v: unknown
  collections: string[]
  onOpenRef: (src: string, id: string) => void
  mono: boolean
  maxShow?: number
}) {
  const [open, setOpen] = useState(false)
  if (!v || typeof v !== 'object') return <span className="text-muted-foreground">·</span>
  const entries = Object.entries(v as Record<string, unknown>).filter(([, val]) => val) as Array<[string, string]>
  if (entries.length === 0) return <span className="text-muted-foreground">·</span>
  const visible = open ? entries : entries.slice(0, maxShow)
  return (
    <span className="ref-acc">
      {visible.map(([sh, val]) => {
        const url = externalUrl(sh, val)
        const target = sourceCollection(sh)
        const canNav = target && collections.includes(target)
        return (
          <a
            key={sh}
            href={url ?? undefined}
            target={url && !canNav ? '_blank' : undefined}
            rel="noreferrer"
            title={val}
            onClick={e => {
              e.stopPropagation()
              if (canNav) {
                e.preventDefault()
                onOpenRef(target as string, val)
              }
            }}
            className={cn('ref-chip', mono && 'font-mono')}
          >
            {sh}:{String(val).split('/').filter(Boolean).pop()?.slice(0, 10) ?? val}
          </a>
        )
      })}
      {entries.length > maxShow && (
        <button
          type="button"
          className="row-reveal cursor-pointer text-[10px] text-muted-foreground hover:text-foreground"
          onClick={e => { e.stopPropagation(); setOpen(o => !o) }}
        >
          {open ? '−' : `+${entries.length - maxShow}`}
        </button>
      )}
    </span>
  )
}

/** references / siteUrl → clickable URL list. */
function LinksList({ v, mono }: { v: unknown; mono: boolean }) {
  let urls: string[] = []
  if (typeof v === 'string' && /^https?:\/\//i.test(v)) urls = [v]
  else if (Array.isArray(v)) urls = v.filter((x): x is string => typeof x === 'string' && /^https?:\/\//i.test(x))
  if (urls.length === 0) return <span className="text-muted-foreground">·</span>
  const shown = urls.slice(0, 3)
  return (
    <span className="ref-acc">
      {shown.map(u => <LinkCell key={u} url={u} mono={mono} />)}
      {urls.length > 3 && <span className="text-[10px] text-muted-foreground">+{urls.length - 3}</span>}
    </span>
  )
}

function FieldHeader({ f }: { f: string }) {
  const desc = describeField(f)
  if (!desc) return <span>{f}</span>
  return (
    <Tooltip>
      <TooltipTrigger render={<span className="cursor-help underline decoration-dotted underline-offset-2">{f}</span>} />
      <TooltipContent>{desc}</TooltipContent>
    </Tooltip>
  )
}

// ---------- value dispatching ----------

function CellInner({ v, field, ctx }: { v: unknown; field: string; ctx: ColumnCtx }) {
  if (isMissing(v)) return <span className="text-muted-foreground">·</span>
  const mono = ctx.mono && ID_LIKE.test(field)
  if (field === 'reference_ids') return <RefAccordion v={v} collections={ctx.collections} onOpenRef={ctx.onOpenRef} mono={mono} />
  if (field === 'references') return <LinksList v={v} mono={mono} />
  if (field === 'siteUrl') return <LinksList v={v} mono={mono} />
  if (typeof v === 'number') return <NumCell value={v} max={ctx.maxOf(field)} mono={mono} field={field} />
  if (typeof v === 'boolean') return <code className={cn('text-[11px] text-muted-foreground', mono && 'font-mono')}>{String(v)}</code>
  const img = isImageUrl(v) ? (v as string) : (typeof v === 'object' && v ? longestImage(v) : null)
  if (img) return <Thumb src={img} />
  const link = linkUrl(v)
  if (link && typeof v === 'string') return <LinkCell url={link} mono={mono} />
  if (Array.isArray(v)) {
    const strs = v.filter((x): x is string => typeof x === 'string')
    if (strs.length === v.length && strs.length > 0) {
      return (
        <span className="line-clamp-1 text-xs">
          {strs.slice(0, 3).join(', ')}
          {strs.length > 3 && <span className="text-muted-foreground"> +{strs.length - 3}</span>}
        </span>
      )
    }
    return <span className="text-muted-foreground">[…{v.length}]</span>
  }
  if (typeof v === 'object') return <span className="text-muted-foreground">{'{…}'}</span>
  return <span className={cn('text-xs', mono && 'font-mono text-[11px]')}>{prettyPreview(v)}</span>
}

// ---------- column builder ----------

export function buildColumns(cols: string[], ctx: ColumnCtx): ColumnDef<DataTableFeatures, ExploreRow, unknown>[] {
  const dataCols: ColumnDef<DataTableFeatures, ExploreRow, unknown>[] = cols.map(c => columnHelper.accessor(c, {
    id: c,
    header: () => <FieldHeader f={c} />,
    enableSorting: c !== 'reference_ids' && c !== 'references' && c !== 'images',
    meta: {
      truncate: c === 'title' || c === 'synonyms',
      className: c === 'title' ? 'max-w-[240px]' : undefined,
      align: ctx.mono && ID_LIKE.test(c) ? 'left' : undefined,
      stickyLeft: c === 'title' ? true : undefined,
    },
    cell: ({ row }) => {
      const d = row.original
      const srcDot = c === 'title' && ctx.showSource ? rowSource(d, ctx.collection) : null
      return (
        <div className="group/cell flex items-center gap-1">
          <span className="min-w-0 flex-1">
            {c === 'title' && (
              <span className="flex min-w-0 items-center gap-1.5">
                {srcDot && <span className="src-dot shrink-0" style={{ background: srcDot.color }} />}
                <span className="truncate">{titleOf(d) || <span className="text-muted-foreground italic">(no title)</span>}</span>
              </span>
            )}
            {c !== 'title' && <CellInner v={d[c]} field={c} ctx={ctx} />}
          </span>
        </div>
      )
    },
  }))

  const actions: ColumnDef<DataTableFeatures, ExploreRow, unknown> = columnHelper.display({
    id: 'actions',
    enableSorting: false,
    meta: { align: 'right', stickyRight: true, className: 'w-28 pr-1' },
    cell: ({ row }) => {
      const d = row.original
      const id = stringId(d)
      const refs = refsOf(d)
      const inDiff = ctx.diffPair.includes(d)
      const diffFull = ctx.diffPair.length >= 2 && !inDiff
      const fav = ctx.favs.has(id)
      const toggleDiff = () => ctx.onToggleDiff(d)
      const copyId = () => { void safeCopy(id); toast('copied id') }
      const copyJson = () => { void safeCopy(JSON.stringify(d, null, 2)); toast('copied json') }
      return (
        <div data-column-actions className="flex items-center justify-end gap-0.5">
          <span className="mr-1 hidden max-w-[80px] truncate text-[10px] text-muted-foreground sm:inline">
            {refs.slice(0, 3).map(([sh]) => sh).join('·') || 'no refs'}
          </span>
          <Button size="icon-xs" variant="ghost" title={fav ? 'Unpin' : 'Pin row'} aria-label={fav ? 'Unpin row' : 'Pin row'} onClick={e => { e.stopPropagation(); ctx.onToggleFav(id) }}>
            <Pin className={`h-3 w-3 ${fav ? 'text-primary' : 'text-muted-foreground'}`} />
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger render={<Button size="icon-xs" variant="ghost" aria-label={`Row actions for ${id}`}><MoreHorizontal className="h-3.5 w-3.5" /></Button>} />
            <DropdownMenuContent align="end">
              <DropdownMenuGroup>
              <DropdownMenuLabel>Row {prettyPreview(id).slice(0, 24)}</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => ctx.onOpenDoc(d)}><ListFilter className="h-3.5 w-3.5" />Inspect</DropdownMenuItem>
              <DropdownMenuItem disabled={diffFull} onClick={toggleDiff}><GitCompareArrows className={`h-3.5 w-3.5 ${inDiff ? 'text-primary' : ''}`} />{inDiff ? 'Remove from diff' : 'Add to diff'}</DropdownMenuItem>
              <DropdownMenuItem onClick={ctx.onToggleFav.bind(null, id)}><Pin className="h-3.5 w-3.5" />{fav ? 'Unpin' : 'Pin'}</DropdownMenuItem>
              <DropdownMenuItem onClick={copyId}><Copy className="h-3.5 w-3.5" />Copy ID</DropdownMenuItem>
              <DropdownMenuItem onClick={copyJson}><Code2 className="h-3.5 w-3.5" />Copy JSON</DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onClick={() => ctx.onRequestDelete([id])}><Trash2 className="h-3.5 w-3.5" />Delete</DropdownMenuItem>
              </DropdownMenuGroup>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      )
    },
  })

  return [...dataCols, actions]
}

// ---------- misc helpers for toolbar ----------

/** % of rows missing this field on the current page (for the column picker). */
export function emptyRate(docs: ExploreRow[], field: string): number {
  if (docs.length === 0) return 0
  let missing = 0
  for (const d of docs) if (isMissing(d[field])) missing++
  return Math.round((missing / docs.length) * 100)
}

/** Max numeric value for a field across the page (bar scaling). */
export function numericMax(docs: ExploreRow[], field: string): number {
  let max = 0
  for (const d of docs) {
    const v = d[field]
    if (typeof v === 'number' && Number.isFinite(v) && v > max) max = v
  }
  return max
}