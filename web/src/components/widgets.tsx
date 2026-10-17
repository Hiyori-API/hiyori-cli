import { useEffect, useRef, useState, type ReactNode } from 'react'
import { type LucideIcon } from 'lucide-react'
import { Accordion, AccordionItem } from '@carbon/react'
import { Card, CardContent } from '@/components/ui/card'
import { CARBON } from './charts'
import { cn } from 'cn'

/** Recharts tooltip surface, on Carbon's flat/square layer styling. */
const TOOLTIP_STYLE = {
  background: 'var(--popover)',
  border: '1px solid var(--border)',
  borderRadius: 0,
  fontSize: 12,
  fontFamily: 'var(--font-sans)',
} as const

/** Pulses briefly when the underlying value changes. */
export function ChangePulse({ value, className, pulse = true }: { value: string; className?: string; pulse?: boolean }) {
  const prev = useRef(value)
  const [flash, setFlash] = useState<boolean>(false)
  const flashTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (!pulse || prev.current === value) return
    prev.current = value
    setFlash(true)
    if (flashTimer.current) clearTimeout(flashTimer.current)
    flashTimer.current = setTimeout(() => setFlash(false), 700)
    return () => {
      if (flashTimer.current) clearTimeout(flashTimer.current)
    }
  }, [value, pulse])

  return (
    <span className={cn('tabular-nums transition-colors duration-300', flash && 'text-chart-2', className)}>
      {value}
    </span>
  )
}

export function KpiCard({
  label, value, icon: Icon, sub, loading,
}: {
  label: string
  value: string
  icon: LucideIcon
  sub?: string
  loading?: boolean
}) {
  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-start justify-between">
          <div className="min-w-0">
            {/* Carbon's label treatment: small, tracked-out, muted. */}
            <p className="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="mt-1.5 text-3xl font-light leading-none">{loading ? (
              <span className="inline-block h-8 w-24 animate-pulse bg-muted" />
            ) : (
              <ChangePulse value={value} />
            )}</p>
            {sub && <p className="mt-1.5 truncate text-xs text-muted-foreground">{sub}</p>}
          </div>
          {/* Carbon squares its icon wells; a pill reads as a different system. */}
          <div className="flex h-9 w-9 shrink-0 items-center justify-center bg-muted text-muted-foreground">
            <Icon className="h-4 w-4" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * Collapsible panel section, on Carbon `Accordion`.
 *
 * This was a hand-rolled disclosure (a Card wrapping a button and a chevron);
 * Carbon has exactly this control, so the hand-rolled version is gone in
 * favour of the real one. The title is a node rather than a string because
 * these panels need a leading icon and a trailing badge, and Carbon's
 * `title` accepts one. The badge is padded clear of Carbon's own chevron.
 */
export function Section({ title, icon: Icon, children, defaultOpen = true, badge, className }: {
  title: string
  icon?: LucideIcon
  children: ReactNode
  defaultOpen?: boolean
  badge?: ReactNode
  className?: string
}) {
  return (
    <Card className={cn('overflow-hidden', className)}>
      <Accordion
        align="start"
        size="sm"
        className="carbon-accordion"
      >
        <AccordionItem
          open={defaultOpen}
          title={
            <span className="flex items-center gap-2 pr-8">
              {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
              <span>{title}</span>
              {badge && <span className="ml-auto shrink-0">{badge}</span>}
            </span>
          }
        >
          {children}
        </AccordionItem>
      </Accordion>
    </Card>
  )
}

/**
 * Page title block.
 *
 * Carbon ships no PageHead component — its idiom is a heading plus supporting
 * text with actions right-aligned — so this keeps the layout and picks up
 * Carbon's type scale and spacing instead.
 */
export function PageHead({ title, desc, actions }: { title: string; desc?: string; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-start gap-3">
      <div className="min-w-0">
        <h1 className="text-xl font-normal leading-tight">{title}</h1>
        {desc && <p className="mt-1 text-sm text-muted-foreground">{desc}</p>}
      </div>
      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
    </div>
  )
}

export function StatusDot({ ok, pulse }: { ok: boolean; pulse?: boolean }) {
  return (
    <span
      className={cn(
        'inline-block h-2 w-2 rounded-full',
        ok ? 'bg-chart-2' : 'bg-destructive',
        pulse && ok && 'animate-pulse',
      )}
    />
  )
}

export { CARBON, TOOLTIP_STYLE }
