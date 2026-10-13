/**
 * Right side-panel detail Sheet for a single Explorer row.
 *
 * Tabs: structured key/value tree (collapsible nodes, copy-per-value) with a
 * reference map section and best-effort per-doc validation issues, plus a raw
 * JSON view. Footer carries copy / edit / delete actions.
 */

import { safeCopy } from '../../lib/explorer/clipboard'
import { useEffect, useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Copy, ExternalLink, Pencil as PencilIcon, Trash2 as TrashIcon, X } from 'lucide-react'
import { cn } from 'cn'
import type { ExploreRow } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { fetchValidation, type ValidationEvent } from '@/lib/explorer/backend'
import { collectImages, externalUrl, isImageUrl, longestImage, refsOf, sourceCollection, stringId, titleOf } from '@/lib/explorer/doc-utils'

function preview(v: unknown, cap = 200): string {
  try {
    if (typeof v === 'string') return v.length > cap ? v.slice(0, cap) + '…' : v
    if (v === null) return 'null'
    if (v === undefined) return 'undefined'
    if (typeof v === 'object') return JSON.stringify(v)
    return String(v)
  } catch {
    return String(v)
  }
}

function normTitle(s: string): string {
  return s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').replace(/\s+/g, ' ').trim()
}

function CopyBtn({ get }: { get: () => string }) {
  return (
    <button
      type="button"
      className="row-reveal shrink-0 cursor-pointer rounded p-0.5 text-muted-foreground hover:text-foreground"
      title="Copy value"
      aria-label="Copy value"
      onClick={e => {
        e.stopPropagation()
        void safeCopy(get())
      }}
    >
      <Copy className="h-3 w-3" />
    </button>
  )
}

// ---------- image gallery (F2) ----------

function ImageStrip({ images, onOpen }: { images: string[]; onOpen: (i: number) => void }) {
  const [broken, setBroken] = useState<Set<number>>(new Set())
  return (
    <div className="mb-3 flex gap-1.5 overflow-x-auto pb-1" aria-label="Image gallery">
      {images.map((src, i) =>
        broken.has(i) ? null : (
          <button
            key={`${src}-${i}`}
            type="button"
            className="shrink-0 cursor-pointer overflow-hidden rounded-md border border-border/70 bg-muted/20 hover:border-primary/50"
            aria-label={`Open image ${i + 1} of ${images.length}`}
            onClick={() => onOpen(i)}
          >
            <img loading="lazy" src={src} alt="" className="h-16 w-12 object-cover" onError={() => setBroken(s => new Set(s).add(i))} />
          </button>
        )
      )}
    </div>
  )
}

/** Owns the lightbox state; keyed by the doc id so it resets per document. */
function GallerySection({ images }: { images: string[] }) {
  const [lightbox, setLightbox] = useState<number | null>(null)
  const nav = (dir: 1 | -1) => {
    setLightbox(i => (i === null || images.length === 0 ? i : (i + dir + images.length) % images.length))
  }
  return (
    <>
      <ImageStrip images={images} onOpen={setLightbox} />
      {lightbox !== null && (
        <Lightbox images={images} index={lightbox} onClose={() => setLightbox(null)} onNav={nav} />
      )}
    </>
  )
}

/** Full-screen dark lightbox with prev/next + keyboard (Esc / arrows). */
function Lightbox({ images, index, onClose, onNav }: {
  images: string[]
  index: number
  onClose: () => void
  onNav: (dir: 1 | -1) => void
}) {
  const n = images.length
  useEffect(() => {
    const prevOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const h = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault()
        e.stopPropagation()
        onClose()
      } else if (e.key === 'ArrowLeft' && n > 1) {
        e.preventDefault()
        e.stopPropagation()
        onNav(-1)
      } else if (e.key === 'ArrowRight' && n > 1) {
        e.preventDefault()
        e.stopPropagation()
        onNav(1)
      }
    }
    window.addEventListener('keydown', h, { capture: true })
    return () => {
      window.removeEventListener('keydown', h, { capture: true })
      document.body.style.overflow = prevOverflow
    }
  }, [n, onClose, onNav])
  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/85" role="dialog" aria-modal="true" aria-label="Image lightbox">
      <button type="button" className="absolute inset-0" aria-label="Close lightbox" onClick={onClose} />
      <img key={images[index]} src={images[index]} alt="" className="relative max-h-[85vh] max-w-[90vw] rounded-lg object-contain shadow-2xl" />
      <button type="button" className="absolute top-4 right-4 z-10 cursor-pointer rounded-full bg-black/40 p-2 text-white hover:bg-black/60" aria-label="Close lightbox" onClick={onClose}><X className="h-4 w-4" /></button>
      {n > 1 && (
        <>
          <button type="button" className="absolute left-3 z-10 cursor-pointer rounded-full bg-black/40 p-2 text-white hover:bg-black/60" aria-label="Previous image" onClick={() => onNav(-1)}><ChevronLeft className="h-5 w-5" /></button>
          <button type="button" className="absolute right-3 z-10 cursor-pointer rounded-full bg-black/40 p-2 text-white hover:bg-black/60" aria-label="Next image" onClick={() => onNav(1)}><ChevronRight className="h-5 w-5" /></button>
          <span className="absolute bottom-3 left-1/2 z-10 -translate-x-1/2 rounded-full bg-black/50 px-2.5 py-1 text-xs text-white tabular-nums">{index + 1} / {n}</span>
        </>
      )}
    </div>
  )
}

function LeafValue({ v }: { v: unknown }) {
  if (v === null || v === undefined) {
    return <span className="text-muted-foreground italic">null</span>
  }
  const img = typeof v === 'string' ? (isImageUrl(v) ? v : null) : longestImage(v)
  if (img) {
    return (
      <span className="inline-flex items-center gap-1.5">
        <img loading="lazy" src={img} alt="" className="h-8 w-6 rounded border object-cover" />
        <span className="truncate text-xs text-muted-foreground">{preview(v, 60)}</span>
      </span>
    )
  }
  if (typeof v === 'string' && /^https?:\/\//i.test(v)) {
    return (
      <a href={v} target="_blank" rel="noreferrer" className="inline-flex min-w-0 items-center gap-1 text-primary underline-offset-2 hover:underline">
        <span className="truncate text-xs">{v}</span>
        <ExternalLink className="h-3 w-3 shrink-0" />
      </a>
    )
  }
  if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
    return <span className={cn('truncate text-xs', typeof v === 'number' && 'font-mono tabular-nums', typeof v === 'string' && 'text-foreground/90')}>{preview(v)}</span>
  }
  return <span className="text-muted-foreground">{preview(v)}</span>
}

function JsonNode({ k, v, depth }: { k: string; v: unknown; depth: number }) {
  const [open, setOpen] = useState(depth < 2)
  const isObj = !!v && typeof v === 'object' && !Array.isArray(v)
  const isArr = Array.isArray(v)
  const expandable = isObj || isArr
  const summary = expandable ? (isArr ? `[${v.length}]` : `{${Object.keys(v as object).length}}`) : undefined
  if (!expandable) {
    return (
      <div className="group/json-row flex items-center gap-1 py-0.5">
        <span className="shrink-0 pl-4 font-mono text-[11px] text-sky-500">{k}</span>
        <span className="min-w-0 flex-1"><LeafValue v={v} /></span>
        <CopyBtn get={() => (typeof v === 'string' ? v : JSON.stringify(v))} />
      </div>
    )
  }
  const entries = Object.entries(v as object)
  return (
    <div className="group/json-row">
      <div className="flex items-center gap-1 py-0.5">
        <button type="button" onClick={() => setOpen(o => !o)} className="flex min-w-0 items-center gap-1 text-left hover:text-foreground" aria-expanded={open}>
          <ChevronRight className={cn('h-3 w-3 shrink-0 text-muted-foreground transition-transform', open && 'rotate-90')} />
          <span className="font-mono text-[11px] text-sky-500">{k}</span>
          <span className="text-[11px] text-muted-foreground">{summary}</span>
        </button>
        <span className="flex-1" />
        <CopyBtn get={() => JSON.stringify(v)} />
      </div>
      {open && (
        <div className="ml-1.5 border-l border-border/70 pl-2.5">
          {entries.map(([ck, cv]) => <JsonNode key={ck} k={ck} v={cv} depth={depth + 1} />)}
        </div>
      )}
    </div>
  )
}

function RefMapSection({ doc, collections, onOpenRef }: {
  doc: ExploreRow
  collections: string[]
  onOpenRef: (src: string, id: string) => void
}) {
  const refs = refsOf(doc)
  const urls = (Array.isArray(doc.references) ? doc.references : []).filter((s): s is string => typeof s === 'string' && /^https?:\/\//i.test(s))
  if (refs.length === 0 && urls.length === 0) return null
  return (
    <div className="mb-3 rounded-lg border bg-muted/20 p-3">
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">References</p>
      <div className="flex flex-wrap items-center gap-1.5">
        {refs.map(([sh, val]) => {
          const url = externalUrl(sh, val)
          const target = sourceCollection(sh)
          const canNav = !!target && collections.includes(target)
          return (
            <span key={sh} className="inline-flex items-center gap-1.5 rounded-md border bg-background px-1.5 py-1 text-xs">
              <span className="font-mono text-[10px] text-muted-foreground">{sh}</span>
              <span className="max-w-[200px] truncate font-mono text-[11px]">{preview(val, 40)}</span>
              {canNav && target ? (
                <button type="button" className="text-primary hover:underline" onClick={() => onOpenRef(target, val)}>
                  open {target}
                </button>
              ) : url ? (
                <a href={url} target="_blank" rel="noreferrer" className="text-muted-foreground hover:text-foreground" title="Open external">
                  <ExternalLink className="h-3 w-3" />
                </a>
              ) : null}
            </span>
          )
        })}
        {urls.map(u => (
          <a key={u} href={u} target="_blank" rel="noreferrer" className="inline-flex max-w-[220px] items-center gap-1 rounded-md border bg-background px-1.5 py-1 text-xs text-primary underline-offset-2 hover:underline">
            <span className="truncate">{u.replace(/^https?:\/\//, '').slice(0, 32)}</span>
            <ExternalLink className="h-3 w-3 shrink-0" />
          </a>
        ))}
      </div>
    </div>
  )
}

/** Best-effort validation issues: /api/validate tail matched by source + title. */
function useValidationIssues(doc: ExploreRow | null, collection: string): ValidationEvent[] {
  const [all, setAll] = useState<ValidationEvent[] | null>(null)
  useEffect(() => {
    let on = true
    void fetchValidation().then(v => { if (on) setAll(v) })
    return () => { on = false }
  }, [])
  return useMemo(() => {
    if (!all || !doc) return []
    const t = normTitle(titleOf(doc))
    if (!t) return []
    const organized = collection.includes('organized')
    return all.filter(e => {
      const srcOk = !e.source || (organized ? !['anilist', 'kitsu', 'myanimelist'].includes(e.source) : e.source === collection)
      return srcOk && normTitle(e.title ?? '') === t
    })
  }, [all, doc, collection])
}

export function DetailSheet({ doc, collection, collections, onOpenChange, onOpenRef, onDelete, onEdit }: {
  doc: ExploreRow | null
  collection: string
  collections: string[]
  onOpenChange: (d: ExploreRow | null) => void
  onOpenRef: (src: string, id: string) => void
  onDelete: (id: string) => void
  onEdit: () => void
}) {
  const [tab, setTab] = useState<'fields' | 'raw'>('fields')
  const issues = useValidationIssues(doc, collection)
  const d = doc ?? ({} as ExploreRow)
  const images = useMemo(() => collectImages(doc), [doc])
  return (
    <Sheet open={!!doc} onOpenChange={o => { if (!o) onOpenChange(null) }}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-xl md:max-w-2xl" side="right">
        <SheetHeader className="border-b pb-3">
          <SheetTitle className="pr-6">{titleOf(d) || 'Document'}</SheetTitle>
          <SheetDescription className="font-mono text-[11px]">{collection} · {stringId(d)}</SheetDescription>
        </SheetHeader>
        {images.length > 0 && <GallerySection key={stringId(doc)} images={images} />}
        <Tabs value={tab} onValueChange={v => setTab(v as 'fields' | 'raw')} className="min-h-0 flex-1">
          <TabsList className="ml-1">
            <TabsTrigger value="fields">Fields</TabsTrigger>
            <TabsTrigger value="raw">Raw JSON</TabsTrigger>
          </TabsList>
          <TabsContent value="fields" className="overflow-y-auto pr-1">
            <RefMapSection doc={d} collections={collections} onOpenRef={onOpenRef} />
            {issues.length > 0 && (
              <div className="mb-3 rounded-lg border border-amber-400/30 bg-amber-400/5 p-3">
                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-amber-500">Validation issues ({issues.length})</p>
                {issues.map((iss, i) => (
                  <div key={i} className="mb-1 text-xs">
                    {Object.entries(iss.errors ?? {}).map(([f, msgs]) => (
                      <p key={f}><span className="font-mono text-amber-400">{f}</span>: {(Array.isArray(msgs) ? msgs : [String(msgs)]).join('; ')}</p>
                    ))}
                  </div>
                ))}
              </div>
            )}
            <div className="rounded-lg border p-2">
              {Object.entries(d).map(([k, v]) => <JsonNode key={k} k={k} v={v} depth={0} />)}
            </div>
          </TabsContent>
          <TabsContent value="raw" className="overflow-y-auto pr-1">
            <pre className="rounded-lg bg-muted/40 p-3 text-[11px] leading-relaxed">{JSON.stringify(d, null, 2)}</pre>
          </TabsContent>
        </Tabs>
        <SheetFooter className="border-t pt-3">
          <div className="flex items-center justify-end gap-2">
            <Button size="sm" variant="outline" onClick={() => { void safeCopy(JSON.stringify(d, null, 2)) }}>
              <Copy className="mr-1.5 h-3.5 w-3.5" />Copy JSON
            </Button>
            <Button size="sm" variant="outline" onClick={onEdit}>
              <PencilIcon />Edit
            </Button>
            <Button size="sm" variant="destructive" onClick={() => onDelete(stringId(d))}>
              <TrashIcon />Delete
            </Button>
          </div>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}