import type { ComponentProps } from 'react'
import { cn } from 'cn'

/**
 * Inline code chip.
 *
 * `rounded bg-muted px-1` was retyped at every site that needed to name a
 * collection, a CLI command or an env var, so the same snippet rendered as a
 * chip in Settings, as a `kbd` in the Explorer's shortcut list and as plain
 * running text in two places where the class had been forgotten. The recipe
 * lives here once.
 *
 * `variant="plain"` is for the sites that genuinely want code as running text —
 * a token wedged into a sentence, or a value that has to wrap and break. It is
 * an explicit opt-out rather than a missing class, so "no chip" is readable as
 * a decision.
 */
const CHIP = 'rounded bg-muted px-1'

export function Code({
  variant = 'inline',
  className,
  ...props
}: ComponentProps<'code'> & { variant?: 'inline' | 'plain' }) {
  return <code className={cn(variant === 'inline' && CHIP, className)} {...props} />
}

/** Keyboard key. Same chip geometry as `Code`, kept as `<kbd>` for semantics. */
export function Kbd({ className, ...props }: ComponentProps<'kbd'>) {
  return <kbd className={cn(CHIP, className)} {...props} />
}

/** Multi-line code / JSON dump. */
export function CodeBlock({ className, ...props }: ComponentProps<'pre'>) {
  return <pre className={cn('rounded-lg bg-muted/40 p-3 text-[11px] leading-relaxed', className)} {...props} />
}
