/**
 * Modal dialogs for the Explorer page: delete confirm (with backup guard),
 * diff view, duplicate-scan results, bulk-set-field and the sheet's inline
 * edit dialog. Kept out of ExplorerPage to stay under its size budget.
 */

import { useEffect, useState } from 'react'
import type { ExploreRow } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { isScalar, titleOf } from '@/lib/explorer/doc-utils'
import type { DupPair } from '@/lib/explorer/search-parse'

export interface ExplorerDialogsProps {
  collection: string
  // delete
  confirmDel: string[] | null
  onConfirmDelChange: (v: string[] | null) => void
  onDeleteConfirm: () => void
  // diff
  diffPair: ExploreRow[]
  diffRows: Array<[string, unknown, unknown]>
  onDiffChange: (rows: ExploreRow[]) => void
  // dup scan
  dupOpen: DupPair[] | null
  onDupChange: (v: DupPair[] | null) => void
  onOpenDoc: (d: ExploreRow) => void
  // bulk set
  bulkSetOpen: boolean
  bulkBusy: boolean
  bulkFields: string[]
  onBulkSetChange: (o: boolean) => void
  onBulkApply: (key: string, value: string) => void
  // sheet edit
  sheetEditOpen: boolean
  sheetDoc: ExploreRow | null
  sheetEditField: string
  sheetEditValue: string
  onSheetEditFieldChange: (f: string) => void
  onSheetEditValueChange: (v: string) => void
  onSheetEditSave: () => void
  onSheetEditClose: () => void
}

export function ExplorerDialogs(props: ExplorerDialogsProps) {
  const {
    collection, confirmDel, onConfirmDelChange, onDeleteConfirm,
    diffPair, diffRows, onDiffChange,
    dupOpen, onDupChange, onOpenDoc,
    bulkSetOpen, bulkBusy, bulkFields, onBulkSetChange, onBulkApply,
    sheetEditOpen, sheetDoc, sheetEditField, sheetEditValue,
    onSheetEditFieldChange, onSheetEditValueChange, onSheetEditSave, onSheetEditClose,
  } = props

  return (
    <>
      {/* delete confirm + backup guard */}
      <Dialog open={!!confirmDel} onOpenChange={o => !o && onConfirmDelChange(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete {confirmDel?.length} doc{confirmDel?.length === 1 ? '' : 's'}?</DialogTitle>
            <DialogDescription>
              Removes the selected documents from <code className="rounded bg-muted px-1">{collection}</code>.
            </DialogDescription>
          </DialogHeader>
          {/* Say what actually happens. The deleted documents are snapshotted
              server-side and restorable via Undo on the toast that follows —
              the old "cannot be undone" copy contradicted that button and made
              the one reassurance that matters at this moment untrustworthy. */}
          <p className="rounded-lg border border-border bg-muted/40 p-2 text-xs text-muted-foreground">
            Audited, and restorable straight after via <strong>Undo</strong> on the confirmation.
            Once you delete another batch, this one is gone. Back up first from Ingest → Backups.
          </p>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => onConfirmDelChange(null)}>Cancel</Button>
            <Button size="sm" variant="destructive" onClick={onDeleteConfirm}>Delete</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* diff */}
      <Dialog open={diffPair.length >= 2} onOpenChange={o => !o && onDiffChange([])}>
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
                    <td className="px-3 py-2 break-all whitespace-pre-wrap text-emerald-400">{previewVal(a)}</td>
                    <td className="px-3 py-2 break-all whitespace-pre-wrap text-warn">{previewVal(b)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </DialogContent>
      </Dialog>

      {/* duplicate scan results */}
      <Dialog open={!!dupOpen} onOpenChange={o => !o && onDupChange(null)}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Possible duplicates</DialogTitle>
            <DialogDescription>Client-side title similarity (Jaro–Winkler ≥ 0.86) vs the current page.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[55vh] overflow-auto">
            {dupOpen && dupOpen.length === 0 && <p className="text-sm text-muted-foreground">No likely duplicates found.</p>}
            {dupOpen?.map((p, i) => (
              <button
                key={i}
                type="button"
                className="flex w-full items-center justify-between gap-2 border-t px-1 py-2 text-left text-xs hover:bg-muted/40"
                onClick={() => onOpenDoc(p.a)}
              >
                <span className="min-w-0 flex-1 truncate">{titleOf(p.a)}</span>
                <span className="shrink-0 px-1 text-muted-foreground">⇄</span>
                <span className="min-w-0 flex-1 truncate text-right">{titleOf(p.b)}</span>
                <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">{Math.round(p.score * 100)}%</span>
              </button>
            ))}
          </div>
        </DialogContent>
      </Dialog>

      {/* bulk set field */}
      <BulkSetDialog open={bulkSetOpen} busy={bulkBusy} fields={bulkFields} onOpenChange={onBulkSetChange} onApply={onBulkApply} />

      {/* sheet edit */}
      <Dialog open={sheetEditOpen} onOpenChange={o => { if (!o) onSheetEditClose() }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit field</DialogTitle>
            <DialogDescription>Save posts to /api/explore/update — audited server-side.</DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Select value={sheetEditField} onValueChange={v => onSheetEditFieldChange(v ?? '')}>
              <SelectTrigger className="w-full" aria-label="Field to edit"><SelectValue /></SelectTrigger>
              <SelectContent>
                {sheetDoc && Object.keys(sheetDoc).filter(k => isScalar(sheetDoc[k])).map(k => (
                  <SelectItem key={k} value={k}>{k}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Input value={sheetEditValue} onChange={e => onSheetEditValueChange(e.target.value)} className="w-full" placeholder="value" />
          </div>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={onSheetEditClose}>Cancel</Button>
            <Button size="sm" onClick={onSheetEditSave}>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function previewVal(v: unknown): string {
  try { return typeof v === 'string' ? (v.length > 300 ? v.slice(0, 300) + '…' : v) : JSON.stringify(v) } catch { return String(v) }
}

/** Bulk set-field dialog. */
function BulkSetDialog({ open, busy, fields, onOpenChange, onApply }: {
  open: boolean
  busy: boolean
  fields: string[]
  onOpenChange: (o: boolean) => void
  onApply: (key: string, value: string) => void
}) {
  const [key, setKey] = useState('')
  const [value, setValue] = useState('')
  useEffect(() => {
    if (open) {
      setKey(fields.find(f => f !== '_id' && f !== 'reference_ids' && f !== 'references' && f !== 'title') ?? fields[0] ?? '')
      setValue('')
    }
  }, [open, fields])
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Bulk set field</DialogTitle>
          <DialogDescription>POST /api/explore/bulk {'{action: set}'} — applies to the current selection.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-2">
          <Select value={key} onValueChange={v => setKey(v ?? '')}>
            <SelectTrigger className="w-full" aria-label="Field to set"><SelectValue /></SelectTrigger>
            <SelectContent>
              {fields.map(f => <SelectItem key={f} value={f}>{f}</SelectItem>)}
            </SelectContent>
          </Select>
          <Input value={value} onChange={e => setValue(e.target.value)} placeholder="value" className="w-full" />
        </div>
        <DialogFooter>
          <Button size="sm" variant="outline" disabled={busy} onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button size="sm" disabled={busy || !key} onClick={() => key && onApply(key, value)}>{busy ? 'Applying…' : 'Apply'}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}