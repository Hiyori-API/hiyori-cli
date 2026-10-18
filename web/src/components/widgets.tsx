import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Sparkline } from './charts'
import { cn } from 'cn'

const TOOLTIP_STYLE = {
  background: 'var(--popover)',
  border: '1px solid var(--border)',
  borderRadius: 8,
  fontSize: 12,
} as const

/** Pulses green briefly when the underlying value changes. */
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
    <span
      className={cn(
        'tabular-nums transition-colors duration-300',
        flash && 'text-emerald-400',
        className,
      )}
    >
      {value}
    </span>
  )
}

export function StatStrip({ items }: {
  items: { label: string; value: string; icon: LucideIcon; sub?: string; tone?: 'ok' | 'warn' | 'bad'; spark?: number[] }[]
}) {
  return (
    <div className="grid grid-cols-1 divide-y divide-border rounded-lg border border-border bg-card sm:grid-cols-3 sm:divide-x sm:divide-y-0">
      {items.map(it => (
        <div key={it.label} className="flex items-center gap-3 px-4 py-3">
          <it.icon className={cn(
            'h-4 w-4 shrink-0 text-muted-foreground',
            it.tone === 'warn' && 'text-chart-5',
            it.tone === 'bad' && 'text-destructive',
          )} />
          <div className="min-w-0">
            <p className="truncate text-xs text-muted-foreground">{it.label}</p>
            <p className={cn(
              'truncate text-xl font-medium leading-tight tabular-nums',
              it.tone === 'warn' && 'text-chart-5',
              it.tone === 'bad' && 'text-destructive',
            )}>{it.value}</p>
          </div>
          {it.spark && it.spark.length >= 2 && (
            <span className="ml-auto shrink-0"><Sparkline points={it.spark} color="var(--chart-1)" width={72} height={22} label={`${it.label} trend`} /></span>
          )}
          {it.sub && <span className={cn('shrink-0 truncate text-xs text-muted-foreground', !it.spark && 'ml-auto')}>{it.sub}</span>}
        </div>
      ))}
    </div>
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
            <p className="truncate text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="mt-1.5 text-2xl font-semibold leading-none">
              {loading ? (
                <span className="inline-block h-7 w-20 animate-pulse rounded bg-muted" />
              ) : (
                <ChangePulse value={value} />
              )}
            </p>
            {sub && <p className="mt-1.5 truncate text-xs text-muted-foreground">{sub}</p>}
          </div>
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted/60 text-muted-foreground">
            <Icon className="h-4 w-4" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

/**
 * Panel heading.
 *
 * Vertical rhythm comes from the Card itself (`--card-spacing`), so this adds no
 * vertical padding — an earlier version stacked `py-3` on the Card's own `py-4`
 * and the header read bloated. Horizontal padding is ours because the Card only
 * sets vertical; without it the heading sits flush against the card edge while
 * the body below it is inset.
 */
export function PanelHead({ title, icon: Icon, badge, action, className }: {
  title: string
  icon?: LucideIcon
  badge?: ReactNode
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex items-center gap-2 px-(--card-spacing)', className)}>
      {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
      <h2 className="text-sm font-medium uppercase tracking-wide text-muted-foreground">{title}</h2>
      {badge && <span className="shrink-0">{badge}</span>}
      {action}
    </div>
  )
}

/**
 * Panel section. Collapsible by default; pass `collapsible={false}` for a panel
 * whose body is short enough that collapsing it only adds a step.
 */
export function Section({ title, icon: Icon, children, defaultOpen = true, badge, className, collapsible = true }: {
  title: string
  icon?: LucideIcon
  children: ReactNode
  defaultOpen?: boolean
  badge?: ReactNode
  className?: string
  collapsible?: boolean
}) {
  const [open, setOpen] = useState(defaultOpen)

  if (!collapsible) {
    return (
      <Card className={cn('overflow-hidden', className)}>
        <PanelHead title={title} icon={Icon} badge={badge} />
        <CardContent className="mt-4">{children}</CardContent>
      </Card>
    )
  }

  return (
    <Card className={cn('overflow-hidden', className)}>
      {/* The whole header band is the hit target. The negative margins cancel
          the Card's own padding on all four sides at once so the tint reaches
          the card edge evenly — bleeding only on the inline axis left the right
          side visibly short. `py-4` keeps the label on the same baseline as a
          non-collapsible PanelHead. */}
      <div className="flex items-stretch">
        <button
          type="button"
          onClick={() => setOpen(o => !o)}
          aria-expanded={open}
          className="-mx-4 -my-4 flex flex-1 items-center gap-2 px-4 py-4 text-left transition-colors hover:bg-muted/40"
        >
          {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
          <h2 className="text-sm font-medium uppercase tracking-wide text-muted-foreground">{title}</h2>
          <ChevronDown className={cn('ml-auto h-4 w-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
        </button>
        {badge && <span className="ml-2 flex shrink-0 items-center">{badge}</span>}
      </div>
      {open && <CardContent className="mt-4">{children}</CardContent>}
    </Card>
  )
}

export function PageHead({ title, desc, actions }: { title: string; desc?: string; actions?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-center gap-3">
      <div className="min-w-0">
        <h1 className="text-lg font-semibold">{title}</h1>
        {desc && <p className="text-xs text-muted-foreground">{desc}</p>}
      </div>
      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
    </div>
  )
}

export function StatusDot({ ok, pulse }: { ok: boolean; pulse?: boolean }) {
  return (
    <span className={cn('inline-block h-2 w-2 rounded-full', ok ? 'bg-emerald-500' : 'bg-red-500', pulse && ok && 'animate-pulse')} />
  )
}

export { TOOLTIP_STYLE }