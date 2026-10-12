import { useCallback, useMemo, useState, type ReactNode } from 'react'
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
}: DataTableProps<TData>) {
  const [intSorting, setIntSorting] = useState<SortingState>(initialSorting ?? [])
  const [intPagination, setIntPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: initialPageSize })
  const [intRowSelection, setIntRowSelection] = useState<RowSelectionState>({})

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
  }, [enableSelection])

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
    enableRowSelection: enableSelection,
    autoResetPageIndex: !manualPagination,
  })

  const rows = table.getRowModel().rows

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
          <div className="overflow-auto" style={maxHeight ? { maxHeight } : undefined}>
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
                      return (
                        <th
                          key={header.id}
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
                            meta?.stickyRight && 'sticky right-0 z-20 bg-background',
                            meta?.className,
                            isSelect && 'w-8 px-3',
                          )}
                        >
                          {canSort ? (
                            <button
                              type="button"
                              className={cn(
                                'inline-flex items-center gap-1 hover:text-foreground',
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
                            </button>
                          ) : (
                            flexRender(column.columnDef.header, header.getContext())
                          )}
                        </th>
                      )
                    })}
                  </tr>
                ))}
              </thead>
              <tbody>
                {rows.map((row) => {
                  const original = row.original
                  const clickable = !!onRowClick
                  return (
                    <tr
                      key={row.id}
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
                        return (
                          <td
                            key={cell.id}
                            onClick={isSelect || stickyActions ? (e) => e.stopPropagation() : undefined}
                            className={cn(
                              'px-2 py-2 align-middle',
                              meta?.align === 'right' && 'text-right',
                              meta?.align === 'center' && 'text-center',
                              meta?.stickyRight &&
                                'sticky right-0 z-10 bg-background group-hover/row:bg-muted/30 dark:group-hover/row:bg-muted/30 dark:bg-background',
                              meta?.truncate && 'max-w-[220px] truncate',
                              meta?.className,
                              isSelect && 'w-8 px-3',
                            )}
                          >
                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                          </td>
                        )
                      })}
                    </tr>
                  )
                })}
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
            <Button size="sm" variant="outline" disabled={!table.getCanPreviousPage()} onClick={() => table.previousPage()}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button size="sm" variant="outline" disabled={!table.getCanNextPage()} onClick={() => table.nextPage()}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}