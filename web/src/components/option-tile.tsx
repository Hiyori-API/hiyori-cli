import type { ReactNode } from 'react'
import { cn } from 'cn'

/**
 * Bordered row whose whole surface toggles one control.
 *
 * The same tile was written by hand with three label sizes (`text-sm`,
 * `text-xs`, and `text-sm` at `opacity-60` for a disabled option) and two
 * alignments (`items-center` for a one-line label, `items-start` for a label
 * with a description under it), so the same control read differently depending
 * on which page it was on. One geometry, one label size, one alignment — the
 * variants only ever encoded sloppiness, not intent.
 *
 * The control is passed in rather than derived from `checked` so the tile stays
 * a layout primitive: it cannot guess whether this row is a `Switch` (an
 * immediate effect) or a `Checkbox` (a staged selection).
 */
export function OptionTile({ control, children, className }: {
  /** The control itself, already labelled for screen readers. */
  control: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <label className={cn('flex items-center gap-2 rounded-lg border px-3 py-2 text-sm', className)}>
      {control}
      <span>{children}</span>
    </label>
  )
}
