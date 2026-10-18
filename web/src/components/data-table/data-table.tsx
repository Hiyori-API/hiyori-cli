import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react'
import { useVirtualizer } from '@tanstack/react-virtual'
import {
  createPaginatedRowModel,
  createSortedRowModel,
  flexRender,
  rowPaginationFeature,
  rowSelectionFeature,
  rowSortingFeature,
  sortFn_alphanumeric,
  sortFn_basic,
  tableFeatures,
  useTable,
  type CellData,
  type ColumnDef,
  type OnChangeFn,
  type PaginationState,
  type RowData,
  type RowSelectionState,
  type SortingState,
  type TableFeatures,
} from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Table2, X } from 'lucide-react'
import { cn } from 'cn'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { EmptyState } from '@/components/empty'

/**
 * Per-column presentation hints read by the DataTable renderer.
 * Augmented onto TanStack's global `ColumnMeta` so callers can set
 * `meta: { stickyRight: true }` on any column def.
 */
declare module '@tanstack/react-table' {
  interface ColumnMeta<
    TFeatures extends TableFeatures,
    TData extends RowData,
    TValue extends CellData = CellData,
  > {
    align?: 'left' | 'right' | 'center'
    /** Renders the column pinned to the right edge of the scroll container. */
    stickyRight?: boolean
    /**
     * Renders the column pinned to the left edge of the scroll container.
     * Consecutive pinned columns stack to the right automatically: the `left`
     * offset of each pinned column accumulates the measured widths of the
     * pinned columns that precede it.
     */
    stickyLeft?: boolean
    /** Adds `truncate` + a max width so long cell content ellipsizes. */
    truncate?: boolean
    /** Extra classes for both the `<th>` and `<td>` of this column. */
    className?: string
  }
}

/**
 * Feature set shared by every table. Registered at module scope (stable) as
 * recommended by the v9 adapter. `manualSorting` / `manualPagination` table
 * options switch these client row models off for server-driven tables.
 */
const features = tableFeatures({
  rowSortingFeature,
  rowPaginationFeature,
  rowSelectionFeature,
  sortedRowModel: createSortedRowModel(),
  paginatedRowModel: createPaginatedRowModel(),
  sortFns: { alphanumeric: sortFn_alphanumeric, basic: sortFn_basic },
})

export type DataTableFeatures = typeof features

const SELECT_COLUMN = 'select'
const DEFAULT_PAGE_SIZE_OPTIONS = [10, 25, 50, 100]

/** Column-resize bounds in px (only used when `resizable` is enabled). */
const RESIZE_MIN_WIDTH = 64
const RESIZE_MAX_WIDTH = 640
const RESIZE_DEFAULT_WIDTH = 160

/**
 * Estimated pixel height of one row, used by the virtualizer until rows are
 * measured from the DOM (`px-2 py-2 text-sm` ≈ 37px including the top border).
 */
const VIRTUAL_ROW_ESTIMATE_HEIGHT = 36
/** Rows rendered above and below the visible window while virtualized. */
const VIRTUAL_OVERSCAN = 8
/**
 * Extra spacing added between adjacent left-pinned columns. Pinned cell
 * borders are already included in the measured (border-box) widths, so 0 keeps
 * stacked pinned columns perfectly flush. Bump it if you add custom gutters.
 */
const STICKY_LEFT_GUTTER = 0

export interface DataTableProps<TData extends RowData> {
  columns: ColumnDef<DataTableFeatures, TData, any>[]
  data: TData[]
  getRowId: (row: TData, index: number) => string
  /** Server-side sorting. Pass `sorting` + `onSortingChange` and set to true. */
  manualSorting?: boolean
  sorting?: SortingState
  onSortingChange?: OnChangeFn<SortingState>
  /** Server-side pagination. Pass `pagination` + `onPaginationChange` (+ `pageCount`) and set to true. */
  manualPagination?: boolean
  pagination?: PaginationState
  onPaginationChange?: OnChangeFn<PaginationState>
  pageCount?: number
  /** Initial sort for client-side sorting (uncontrolled mode). */
  initialSorting?: SortingState
  /** Initial page size for client-side pagination. */
  initialPageSize?: number
  pageSizeOptions?: number[]
  /** Enables the selection column + selected-count bar. */
  enableSelection?: boolean
  /**
   * Pins the built-in selection column to the left edge, stacking it before
   * any data columns that opt into `meta.stickyLeft`. Defaults to false.
   */
  stickySelection?: boolean
  /** When provided, selection is controlled externally (a Set of row ids). */
  selected?: ReadonlySet<string>
  onSelectionChange?: (next: Set<string>) => void
  /** Extra actions rendered in the selected-count bar (e.g. bulk delete). */
  selectionActions?: (count: number) => ReactNode
  /** Slot above the table (search, filters, view switches…). */
  toolbar?: ReactNode
  /** Slot on the left of the footer (totals / page context). */
  footerLeft?: ReactNode
  /** Max height for the sticky-header scroll container. */
  maxHeight?: string
  loading?: boolean
  loadingRows?: number
  empty?: ReactNode
  onRowClick?: (row: TData) => void
  showPagination?: boolean
  /** Column headers cycle asc → desc (never unsorted). Defaults to false. */
  enableSortingRemoval?: boolean
  sortDescFirst?: boolean
  ariaLabel?: string
  className?: string
  /** When true, each sortable header gets a drag handle to resize column widths. */
  resizable?: boolean
  /**
   * Enables shift-click multi-column sorting. Sorted headers show their
   * direction arrow plus an ordinal ("1", "2", …) reflecting sort priority.
   * Defaults to false, preserving the existing single-column toggle behavior.
   */
  multiSort?: boolean
  /**
   * Virtualizes client-side rows (ignored when `manualPagination` is true)
   * inside the `maxHeight` scroll container, rendering only the visible
   * window (+ `VIRTUAL_OVERSCAN` rows). The header and pinned columns remain
   * sticky; selection, row-click and hover only apply to rendered rows.
   * Defaults to false.
   */
  virtual?: boolean
}

/**
 * Reusable data table built on TanStack Table v9 (headless) and the shadcn /
 * Base UI primitives in this repo. Supports server-side (manual) sorting &
 * pagination plus client-side row models, row selection, a toolbar slot,
 * sticky-header scrolling, aria-sort and loading/empty states.
 */
export function DataTable<TData extends RowData>({
  columns,
  data,
  getRowId,
  manualSorting = false,
  sorting: sortingProp,
  onSortingChange: onSortingChangeProp,
  manualPagination = false,
  pagination: paginationProp,
  onPaginationChange: onPaginationChangeProp,
  pageCount,
  initialSorting,
  initialPageSize = 10,
  pageSizeOptions,
  enableSelection = false,
  stickySelection = false,
  selected,
  onSelectionChange,
  selectionActions,
  toolbar,
  footerLeft,
  maxHeight,
  loading = false,
  loadingRows,
  empty,
  onRowClick,
  showPagination = true,
  enableSortingRemoval = false,
  sortDescFirst,
  ariaLabel,
  className,
  resizable = false,
  multiSort = false,
  virtual = false,
}: DataTableProps<TData>) {
  const [intSorting, setIntSorting] = useState<SortingState>(initialSorting ?? [])
  const [intPagination, setIntPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: initialPageSize })
  const [intRowSelection, setIntRowSelection] = useState<RowSelectionState>({})
  const [columnWidths, setColumnWidths] = useState<Record<string, number>>({})
  const resizeSessionRef = useRef<{ columnId: string; startX: number; startWidth: number } | null>(null)

  const sortState = manualSorting ? (sortingProp ?? []) : intSorting
  const paginationState = manualPagination
    ? (paginationProp ?? { pageIndex: 0, pageSize: initialPageSize })
    : intPagination

  const externalSelection = enableSelection && selected !== undefined

  const selectedRowState = useMemo<RowSelectionState>(() => {
    const rec: RowSelectionState = {}
    if (selected) for (const id of selected) rec[id] = true
    return rec
  }, [selected])

  const rowSelectionState = externalSelection ? selectedRowState : intRowSelection

  const selectionCount = useMemo(
    () => (enableSelection ? Object.keys(rowSelectionState).length : 0),
    [enableSelection, rowSelectionState],
  )

  const handleSortingChange: OnChangeFn<SortingState> = manualSorting
    ? (onSortingChangeProp ?? (() => {}))
    : setIntSorting

  const handlePaginationChange: OnChangeFn<PaginationState> = manualPagination
    ? (onPaginationChangeProp ?? (() => {}))
    : setIntPagination

  const handleRowSelectionChange = useCallback<OnChangeFn<RowSelectionState>>(
    (updater) => {
      const next = typeof updater === 'function' ? updater(rowSelectionState) : updater
      if (externalSelection) onSelectionChange?.(new Set(Object.keys(next)))
      else setIntRowSelection(next)
    },
    [externalSelection, onSelectionChange, rowSelectionState],
  )

  const clearSelection = useCallback(() => {
    if (externalSelection) onSelectionChange?.(new Set())
    else setIntRowSelection({})
  }, [externalSelection, onSelectionChange])

  const selectColumn = useMemo<ColumnDef<DataTableFeatures, TData, unknown> | null>(() => {
    if (!enableSelection) return null
    return {
      id: SELECT_COLUMN,
      meta: stickySelection ? { stickyLeft: true } : undefined,
      enableSorting: false,
      header: ({ table }) => (
        <Checkbox
          checked={table.getIsAllPageRowsSelected()}
          indeterminate={table.getIsSomePageRowsSelected()}
          onCheckedChange={(checked) => table.toggleAllPageRowsSelected(checked)}
          aria-label="Select all rows on this page"
        />
      ),
      cell: ({ row }) => (
        <Checkbox
          checked={row.getIsSelected()}
          onCheckedChange={(checked) => row.toggleSelected(checked)}
          aria-label={`Select row ${row.id}`}
        />
      ),
    }
  }, [enableSelection, stickySelection])

  const table = useTable({
    features,
    columns: selectColumn ? [selectColumn, ...columns] : columns,
    data,
    getRowId: (row, index) => getRowId(row, index),
    state: {
      sorting: sortState,
      pagination: paginationState,
      ...(enableSelection ? { rowSelection: rowSelectionState } : {}),
    },
    onSortingChange: handleSortingChange,
    onPaginationChange: handlePaginationChange,
    onRowSelectionChange: handleRowSelectionChange,
    manualSorting,
    manualPagination,
    ...(manualPagination && pageCount !== undefined ? { pageCount } : {}),
    enableSorting: true,
    enableSortingRemoval,
    sortDescFirst,
    ...(multiSort ? { enableMultiSort: true } : {}),
    enableRowSelection: enableSelection,
    autoResetPageIndex: !manualPagination,
  })

  const rows = table.getRowModel().rows

  /* Scroll container + row virtualization (client-side rows only). */
  const scrollContainerRef = useRef<HTMLDivElement | null>(null)
  const virtualized = virtual && !manualPagination
  /* oxlint-disable-next-line react/incompatible-library -- TanStack Virtual inherently opts out of React Compiler memoization; nothing memoized consumes it here. */
  const virtualizer = useVirtualizer<HTMLDivElement, HTMLTableRowElement>({
    count: rows.length,
    enabled: virtualized,
    getScrollElement: () => scrollContainerRef.current,
    estimateSize: () => VIRTUAL_ROW_ESTIMATE_HEIGHT,
    overscan: VIRTUAL_OVERSCAN,
    getItemKey: (index) => rows[index]?.id ?? index,
  })
  const virtualRows = virtualizer.getVirtualItems()
  const renderedRows = virtualized
    ? virtualRows.map((vr) => ({ row: rows[vr.index], index: vr.index }))
    : rows.map((row, index) => ({ row, index }))
  const firstVirtual = virtualized ? virtualRows[0] : undefined
  const lastVirtual = virtualized ? virtualRows[virtualRows.length - 1] : undefined
  const topPad = firstVirtual ? firstVirtual.start : 0
  const bottomPad = lastVirtual ? virtualizer.getTotalSize() - (lastVirtual.start + lastVirtual.size) : 0

  /* DOM refs + measured layout for left-pinned (`meta.stickyLeft`) columns. */
  const stickyLeftRefs = useRef(new Map<string, HTMLTableCellElement>())
  const [stickyLeftLayout, setStickyLeftLayout] = useState<{
    offsets: Record<string, number>
    widths: Record<string, number>
  }>({ offsets: {}, widths: {} })

  /**
   * Measures the left-pinned columns and keeps their `left` offsets / widths
   * in state. Runs after every commit on purpose: pinned widths can change
   * from data swaps, column resizing, density or font loads, so each commit
   * re-measures and the equality guard below makes the setState idempotent
   * (no render loop). The same offsets/widths are mirrored onto body cells.
   *
   * oxlint: exhaustive-deps/set-state-in-effect suppressed — this is an
   * intentional every-commit, self-guarding measurement, not a missing-dep bug.
   */
  /* oxlint-disable-next-line react-hooks/exhaustive-deps, react/set-state-in-effect */
  useLayoutEffect(() => {
    const group = table.getHeaderGroups()[0]
    const pinnedIds = group
      ? group.headers
          .filter((header) => !header.isPlaceholder && header.column.columnDef.meta?.stickyLeft)
          .map((header) => header.column.id)
      : []
    if (pinnedIds.length === 0) return
    const nextOffsets: Record<string, number> = {}
    const nextWidths: Record<string, number> = {}
    let accumulated = 0
    for (const columnId of pinnedIds) {
      const element = stickyLeftRefs.current.get(columnId)
      if (!element) return // not mounted yet — retry on the next commit
      const width = element.getBoundingClientRect().width
      if (!width) return // zero/undisplayed — retry on the next commit
      nextOffsets[columnId] = accumulated
      accumulated += width + STICKY_LEFT_GUTTER
      nextWidths[columnId] = width
    }
    setStickyLeftLayout((previous) => {
      if (
        Object.keys(previous.offsets).length === Object.keys(nextOffsets).length &&
        Object.keys(previous.widths).length === Object.keys(nextWidths).length &&
        Object.keys(nextOffsets).every(
          (columnId) =>
            columnId in previous.offsets &&
            Math.abs(previous.offsets[columnId] - nextOffsets[columnId]) < 0.5,
        ) &&
        Object.keys(nextWidths).every(
          (columnId) =>
            columnId in previous.widths &&
            Math.abs(previous.widths[columnId] - nextWidths[columnId]) < 0.5,
        )
      ) {
        return previous
      }
      return { offsets: nextOffsets, widths: nextWidths }
    })
  })

  /* Jump back to the top when the paged row set changes under virtualization. */
  useEffect(() => {
    if (virtualized) virtualizer.scrollToOffset(0)
  }, [paginationState.pageIndex, paginationState.pageSize, virtualized, virtualizer])

  const handleWindowPointerMove = useCallback((e: PointerEvent) => {
    const session = resizeSessionRef.current
    if (!session) return
    const nextWidth = Math.min(
      RESIZE_MAX_WIDTH,
      Math.max(RESIZE_MIN_WIDTH, session.startWidth + (e.clientX - session.startX)),
    )
    setColumnWidths((prev) => (prev[session.columnId] === nextWidth ? prev : { ...prev, [session.columnId]: nextWidth }))
  }, [])

  const handleWindowPointerUp = useCallback(() => {
    resizeSessionRef.current = null
    window.removeEventListener('pointermove', handleWindowPointerMove)
    window.removeEventListener('pointerup', handleWindowPointerUp)
    window.removeEventListener('pointercancel', handleWindowPointerUp)
  }, [handleWindowPointerMove])

  const handleResizePointerDown = useCallback(
    (e: ReactPointerEvent<HTMLDivElement>, columnId: string) => {
      e.preventDefault()
      resizeSessionRef.current = {
        columnId,
        startX: e.clientX,
        startWidth: columnWidths[columnId] ?? RESIZE_DEFAULT_WIDTH,
      }
      try {
        e.currentTarget.setPointerCapture(e.pointerId)
      } catch {
        // Capture unavailable (unlikely) — the window listeners below still track the drag.
      }
      window.addEventListener('pointermove', handleWindowPointerMove)
      window.addEventListener('pointerup', handleWindowPointerUp)
      window.addEventListener('pointercancel', handleWindowPointerUp)
    },
    [columnWidths, handleWindowPointerMove, handleWindowPointerUp],
  )

  useEffect(
    () => () => {
      window.removeEventListener('pointermove', handleWindowPointerMove)
      window.removeEventListener('pointerup', handleWindowPointerUp)
      window.removeEventListener('pointercancel', handleWindowPointerUp)
    },
    [handleWindowPointerMove, handleWindowPointerUp],
  )

  return (
    <div className={cn('data-table w-full', className)}>
      {toolbar ? <div className="mb-2 flex flex-wrap items-center gap-2">{toolbar}</div> : null}

      {enableSelection && selectionCount > 0 ? (
        <div className="mb-2 flex items-center justify-between gap-2 rounded-lg border border-primary/25 bg-primary/5 px-3 py-1.5 text-xs">
          <span className="font-medium tabular-nums">{selectionCount} selected</span>
          <div className="flex items-center gap-1.5">
            <Button size="sm" variant="ghost" onClick={clearSelection}>
              <X className="h-3.5 w-3.5" />
              Clear
            </Button>
            {selectionActions?.(selectionCount)}
          </div>
        </div>
      ) : null}

      <div className="overflow-hidden rounded-xl border">
        {loading && rows.length === 0 ? (
          <div role="status" aria-live="polite" className="space-y-2 p-4">
            {Array.from({ length: loadingRows ?? 6 }, (_, i) => (
              <Skeleton key={i} className="h-8 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div className="p-4">
            {empty ?? <EmptyState icon={Table2} title="No rows" description="Nothing to show for the current view." />}
          </div>
        ) : (
          <div ref={scrollContainerRef} className="overflow-auto" style={maxHeight ? { maxHeight } : undefined}>
            <table className="w-full text-sm" aria-label={ariaLabel}>
              <thead className="sticky top-0 z-10 bg-background text-left text-xs uppercase text-muted-foreground">
                {table.getHeaderGroups().map((headerGroup) => (
                  <tr key={headerGroup.id}>
                    {headerGroup.headers.map((header) => {
                      const { column } = header
                      const meta = column.columnDef.meta
                      const canSort = !header.isPlaceholder && header.column.getCanSort()
                      const sorted = canSort ? header.column.getIsSorted() : false
                      const isSelect = column.id === SELECT_COLUMN
                      const canResize = resizable && canSort && !isSelect && !meta?.stickyRight
                      const columnWidth = canResize ? (columnWidths[column.id] ?? RESIZE_DEFAULT_WIDTH) : undefined
                      const isStickyLeft = !!meta?.stickyLeft
                      const leftOffset = isStickyLeft ? stickyLeftLayout.offsets[column.id] : undefined
                      const leftWidth = isStickyLeft ? stickyLeftLayout.widths[column.id] : undefined
                      const effectiveWidth = leftWidth ?? columnWidth
                      const thStyle: CSSProperties | undefined =
                        effectiveWidth !== undefined || leftOffset !== undefined
                          ? {
                              ...(effectiveWidth !== undefined ? { width: effectiveWidth, minWidth: effectiveWidth } : null),
                              ...(leftOffset !== undefined ? { left: leftOffset } : null),
                            }
                          : undefined
                      return (
                        <th
                          key={header.id}
                          ref={
                            isStickyLeft
                              ? (element) => {
                                  if (element) stickyLeftRefs.current.set(column.id, element)
                                  else stickyLeftRefs.current.delete(column.id)
                                }
                              : undefined
                          }
                          aria-sort={
                            canSort
                              ? sorted === 'asc'
                                ? 'ascending'
                                : sorted === 'desc'
                                  ? 'descending'
                                  : 'none'
                              : undefined
                          }
                          className={cn(
                            'whitespace-nowrap px-2 py-2 font-medium',
                            meta?.align === 'right' && 'text-right',
                            meta?.align === 'center' && 'text-center',
                            meta?.stickyRight && 'sticky right-0 z-20 border-l border-border bg-background',
                            isStickyLeft && 'sticky z-20 border-r border-border bg-background',
                            meta?.className,
                            isSelect && 'w-8 px-3',
                            canResize && 'relative',
                          )}
                          style={thStyle}
                        >
                          {canSort ? (
                            <button
                              type="button"
                              className={cn(
                                // min-h-6 (24px) is the smallest reliably
                                // clickable target; compact density squeezes
                                // the cell padding to 2px, which left the sort
                                // control at 16px tall.
                                'inline-flex min-h-6 items-center gap-1 px-1 hover:text-foreground',
                                meta?.align === 'right' && 'flex-row-reverse',
                              )}
                              onClick={header.column.getToggleSortingHandler()}
                            >
                              {flexRender(column.columnDef.header, header.getContext())}
                              {sorted === 'asc' ? (
                                <ArrowUp className="h-3 w-3" />
                              ) : sorted === 'desc' ? (
                                <ArrowDown className="h-3 w-3" />
                              ) : null}
                              {sorted !== false && multiSort ? (
                                <span className="ml-0.5 inline-flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-muted px-1 text-[10px] font-semibold leading-none tabular-nums">
                                  {column.getSortIndex() + 1}
                                </span>
                              ) : null}
                            </button>
                          ) : (
                            flexRender(column.columnDef.header, header.getContext())
                          )}
                          {canResize ? (
                            <div
                              role="separator"
                              aria-orientation="vertical"
                              aria-label={`Resize column ${column.id}`}
                              onPointerDown={(e) => handleResizePointerDown(e, column.id)}
                              className="group absolute inset-y-0 right-0 flex w-2.5 cursor-col-resize items-stretch justify-center"
                              style={{ touchAction: 'none' }}
                            >
                              <div className="w-px bg-border transition-colors group-hover:bg-primary/70" />
                            </div>
                          ) : null}
                        </th>
                      )
                    })}
                  </tr>
                ))}
              </thead>
              <tbody>
                {virtualized && topPad > 0 ? <tr aria-hidden="true" style={{ height: topPad }} /> : null}
                {renderedRows.map(({ row, index }) => {
                  const original = row.original
                  const clickable = !!onRowClick
                  return (
                    <tr
                      key={row.id}
                      ref={virtualized ? virtualizer.measureElement : undefined}
                      data-index={virtualized ? index : undefined}
                      className={cn('group/row border-t', clickable && 'cursor-pointer hover:bg-muted/30')}
                      onClick={
                        clickable
                          ? (e) => {
                              const t = e.target as HTMLElement
                              if (
                                !t.closest(
                                  'button, a, input, label, [role="menuitem"], [data-column-actions], [data-slot="checkbox"]',
                                )
                              ) {
                                onRowClick(original)
                              }
                            }
                          : undefined
                      }
                    >
                      {row.getAllCells().map((cell) => {
                        const meta = cell.column.columnDef.meta
                        const isSelect = cell.column.id === SELECT_COLUMN
                        const stickyActions = !!meta?.stickyRight
                        const isStickyLeft = !!meta?.stickyLeft
                        const canResizeCell =
                          resizable && !isSelect && !stickyActions && cell.column.getCanSort()
                        const columnWidth = canResizeCell
                          ? (columnWidths[cell.column.id] ?? RESIZE_DEFAULT_WIDTH)
                          : undefined
                        const leftOffset = isStickyLeft ? stickyLeftLayout.offsets[cell.column.id] : undefined
                        const leftWidth = isStickyLeft ? stickyLeftLayout.widths[cell.column.id] : undefined
                        const effectiveWidth = leftWidth ?? columnWidth
                        const cellStyle: CSSProperties | undefined =
                          effectiveWidth !== undefined || leftOffset !== undefined
                            ? {
                                ...(effectiveWidth !== undefined ? { width: effectiveWidth, minWidth: effectiveWidth } : null),
                                ...(leftOffset !== undefined ? { left: leftOffset } : null),
                              }
                            : undefined
                        return (
                          <td
                            key={cell.id}
                            onClick={isSelect || stickyActions ? (e) => e.stopPropagation() : undefined}
                            className={cn(
                              'px-2 py-2 align-middle',
                              meta?.align === 'right' && 'text-right',
                              meta?.align === 'center' && 'text-center',
                              meta?.stickyRight &&
                                'sticky right-0 z-10 border-l border-border bg-background group-hover/row:bg-muted/30 dark:group-hover/row:bg-muted/30 dark:bg-background',
                              isStickyLeft &&
                                'sticky z-10 border-r border-border bg-background group-hover/row:bg-muted/30 dark:group-hover/row:bg-muted/30 dark:bg-background',
                              meta?.truncate && 'max-w-[220px] truncate',
                              meta?.className,
                              isSelect && 'w-8 px-3',
                            )}
                            style={cellStyle}
                          >
                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                          </td>
                        )
                      })}
                    </tr>
                  )
                })}
                {virtualized && bottomPad > 0 ? <tr aria-hidden="true" style={{ height: bottomPad }} /> : null}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {showPagination ? (
        <div className="flex items-center justify-between gap-2 pt-2 text-sm">
          <div className="min-w-0 text-xs text-muted-foreground">{footerLeft}</div>
          <div className="ml-auto flex shrink-0 items-center gap-2">
            <Select
              value={String(paginationState.pageSize)}
              onValueChange={(v) => {
                const n = Number(v)
                if (n) table.setPageSize(n)
              }}
            >
              <SelectTrigger className="h-8 w-20" aria-label="Rows per page">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {(pageSizeOptions ?? DEFAULT_PAGE_SIZE_OPTIONS).map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {n}/pg
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="whitespace-nowrap text-xs text-muted-foreground">
              page {paginationState.pageIndex + 1}/{Math.max(1, table.getPageCount())}
            </span>
            <Button
              size="sm"
              variant="outline"
              aria-label="Previous page"
              disabled={!table.getCanPreviousPage()}
              onClick={() => table.previousPage()}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button
              size="sm"
              variant="outline"
              aria-label="Next page"
              disabled={!table.getCanNextPage()}
              onClick={() => table.nextPage()}
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}