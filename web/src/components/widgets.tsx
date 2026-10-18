import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
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

/** Collapsible panel section. */
export function Section({ title, icon: Icon, children, defaultOpen = true, badge, className }: {
  title: string
  icon?: LucideIcon
  children: ReactNode
  defaultOpen?: boolean
  badge?: ReactNode
  className?: string
}) {
  const [open, setOpen] = useState(defaultOpen)
  return (
    <Card className={cn('overflow-hidden', className)}>
      <div className="flex w-full items-center gap-2 rounded-t-xl px-4 py-3">
        <button
          type="button"
          onClick={() => setOpen(o => !o)}
          aria-expanded={open}
          className="flex flex-1 items-center gap-2 text-left hover:bg-muted/40"
        >
          {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
          <span className="text-sm font-medium uppercase tracking-wide text-muted-foreground">{title}</span>
          <ChevronDown className={cn('ml-auto h-4 w-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
        </button>
        {badge && <span className="ml-2 shrink-0">{badge}</span>}
      </div>
      {open && <CardContent className="border-t p-4">{children}</CardContent>}
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