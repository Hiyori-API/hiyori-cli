/**
 * Sticky bottom bulk-actions bar for selected rows: export CSV, copy ids/
 * JSON, duplicate scan, bulk set field, delete (with backup guard dialog in
 * the page).
 */

import { ClipboardCopy, Code2, FileDown, Trash2, Wand2, X } from 'lucide-react'
import { Button } from '@/components/ui/button'

export function BulkBar({ count, onClear, onExportCsv, onCopyIds, onCopyJson, onDelete, onBulkSet, onDupScan }: {
  count: number
  onClear: () => void
  onExportCsv: () => void
  onCopyIds: () => void
  onCopyJson: () => void
  onDelete: () => void
  onBulkSet: () => void
  onDupScan: () => void
}) {
  if (count === 0) return null
  return (
    <div className="bulk-bar" role="status" aria-live="polite">
      <span className="font-medium tabular-nums">{count} selected</span>
      <Button size="sm" variant="ghost" onClick={onClear}><X className="h-3.5 w-3.5" />Clear</Button>
      <span className="h-4 w-px bg-border" />
      <Button size="sm" variant="outline" onClick={onExportCsv} title="Export selection as CSV"><FileDown className="h-3.5 w-3.5" />CSV</Button>
      <Button size="sm" variant="outline" onClick={onCopyIds} title="Copy selected ids"><ClipboardCopy className="h-3.5 w-3.5" />IDs</Button>
      <Button size="sm" variant="outline" onClick={onCopyJson} title="Copy selected rows as JSON"><Code2 className="h-3.5 w-3.5" />JSON</Button>
      <Button size="sm" variant="outline" onClick={onDupScan} title="Scan selection for similar titles vs the current page"><Wand2 className="h-3.5 w-3.5" />Dup scan</Button>
      <Button size="sm" variant="secondary" onClick={onBulkSet} title="Bulk-set a field on the selection"><Code2 className="h-3.5 w-3.5" />Set field</Button>
      <Button size="sm" variant="destructive" onClick={onDelete} title="Delete selection"><Trash2 className="h-3.5 w-3.5" />Delete</Button>
    </div>
  )
}