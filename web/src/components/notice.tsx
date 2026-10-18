import type { ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react'
import { cn } from 'cn'

export type NoticeTone = 'info' | 'warn' | 'error' | 'success'

/**
 * Inline message block.
 *
 * There were five visual recipes for one concept: a bordered card, a one-line
 * bar, a plain coloured paragraph, a centred error panel, and a full-bleed
 * strip. The same event class also changed ink — a failed Publish call rendered
 * its message in muted grey while a failed Ingest call rendered the identical
 * event in warn amber, so "something broke" looked like two different things on
 * two pages.
 *
 * Tone owns the ink here, once. `variant` owns only geometry:
 *   line — full-bleed strip (the shell's connection banner)
 *   box  — the default inline block
 *   card — a standalone panel with more padding (a whole surface failed)
 */
const TONE: Record<NoticeTone, { box: string; icon: string; Icon: typeof Info }> = {
  info: { box: 'border-border bg-muted/40 text-muted-foreground', icon: 'text-muted-foreground', Icon: Info },
  warn: { box: 'border-warn/40 bg-warn/5 text-warn', icon: 'text-warn', Icon: AlertTriangle },
  error: { box: 'border-destructive/40 bg-destructive/5 text-destructive', icon: 'text-destructive', Icon: XCircle },
  success: { box: 'border-emerald-500/40 bg-emerald-500/5 text-emerald-600 dark:text-emerald-400', icon: 'text-emerald-600 dark:text-emerald-400', Icon: CheckCircle2 },
}

const VARIANT = {
  line: 'border-b px-4 py-2',
  box: 'rounded-lg border px-3 py-2',
  card: 'rounded-xl border p-4',
} as const

export function Notice({
  tone = 'warn',
  variant = 'box',
  title,
  children,
  icon,
  action,
  role,
  className,
}: {
  tone?: NoticeTone
  variant?: keyof typeof VARIANT
  title?: ReactNode
  children?: ReactNode
  /** Pass `false` to omit the leading icon. */
  icon?: ReactNode | false
  action?: ReactNode
  role?: 'alert' | 'status'
  className?: string
}) {
  const t = TONE[tone]
  const Glyph = t.Icon
  return (
    <div
      role={role ?? (tone === 'success' || tone === 'info' ? 'status' : 'alert')}
      className={cn(
        'flex gap-2 text-xs',
        variant === 'card' ? 'items-start' : 'items-center',
        VARIANT[variant],
        t.box,
        className,
      )}
    >
      {icon !== false && (
        <span className={cn('shrink-0', t.icon)}>
          {icon ?? <Glyph className={variant === 'card' ? 'mt-0.5 h-4 w-4' : 'h-3.5 w-3.5'} />}
        </span>
      )}
      <div className="min-w-0 flex-1">
        {title && <p className="font-medium">{title}</p>}
        {children && <div className={cn(title && 'mt-0.5', 'leading-relaxed')}>{children}</div>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}
