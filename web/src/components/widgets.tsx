import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Area, AreaChart, Cell, RadialBar, RadialBarChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ChevronDown, type LucideIcon } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from 'cn'

const TOOLTIP_STYLE = {
  background: 'var(--popover)',
  border: '1px solid var(--border)',
  borderRadius: 8,
  fontSize: 12,
} as const

/** D6 — pulses green/red briefly when the underlying value changes. */
export function ChangePulse({ value, className, pulse = true }: { value: string; className?: string; pulse?: boolean }) {
  const prev = useRef(value)
  const [flash, setFlash] = useState<'up' | 'down' | null>(null)
  const flashTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (!pulse || prev.current === value) return
    const changed = prev.current !== value
    prev.current = value
    if (!changed) return
    setFlash('up')
    if (flashTimer.current) clearTimeout(flashTimer.current)
    flashTimer.current = setTimeout(() => setFlash(null), 700)
    return () => {
      if (flashTimer.current) clearTimeout(flashTimer.current)
    }
  }, [value, pulse])

  return (
    <span
      className={cn(
        'tabular-nums transition-colors duration-300',
        flash === 'up' && 'text-emerald-400',
        className,
      )}
    >
      {value}
    </span>
  )
}

export function Sparkline({ data, color, id = 's', height = 24 }: { data: { t: number; v: number }[]; color: string; id?: string; height?: number }) {
  if (!data.length) {
    return <div style={{ height }} className="w-full rounded bg-muted/40" />
  }
  const rows = data.map((d, i) => ({ i, v: d.v }))
  return (
    <div style={{ height }} className="w-full">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={rows} margin={{ top: 1, right: 0, bottom: 0, left: 0 }}>
          <defs>
            <linearGradient id={`spark-${id}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={color} stopOpacity={0.35} />
              <stop offset="100%" stopColor={color} stopOpacity={0} />
            </linearGradient>
          </defs>
          <Tooltip contentStyle={TOOLTIP_STYLE} cursor={false} labelFormatter={() => ''} formatter={(v: unknown) => [String(v), '']} />
          <Area type="monotone" dataKey="v" stroke={color} strokeWidth={1.5} fill={`url(#spark-${id})`} dot={false} isAnimationActive={false} />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

export function KpiCard({
  label, value, icon: Icon, sub, spark, color = '#4fb3ff', id, loading,
}: {
  label: string
  value: string
  icon: LucideIcon
  sub?: string
  spark?: { t: number; v: number }[]
  color?: string
  id?: string
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
        {spark && <div className="mt-3"><Sparkline data={spark} color={color} id={id ?? label} /></div>}
      </CardContent>
    </Card>
  )
}

/** Radial gauge (health meter) built on RadialBarChart. */
export function RadialGauge({ value, max = 100, label, sub, color = '#3fd06a', size = 110 }: {
  value: number
  max?: number
  label: string
  sub?: string
  color?: string
  size?: number
}) {
  const pct = Math.max(0, Math.min(max, value))
  const data = [{ name: label, value: pct }]
  return (
    <div className="flex flex-col items-center gap-1">
      <div style={{ width: size, height: size }}>
        <ResponsiveContainer width="100%" height="100%">
          <RadialBarChart data={data} innerRadius="72%" outerRadius="100%" startAngle={225} endAngle={-45} cx="50%" cy="50%">
            <RadialBar dataKey="value" cornerRadius={size / 2} fill={color} background={{ fill: 'var(--muted)' }} />
            <Tooltip contentStyle={TOOLTIP_STYLE} cursor={false} formatter={(v: unknown) => [`${Math.round(Number(v))}%`, label]} />
          </RadialBarChart>
        </ResponsiveContainer>
      </div>
      <div className="text-center">
        <div className="text-lg leading-none font-semibold tabular-nums">{pct.toFixed(0)}<span className="text-xs text-muted-foreground">%</span></div>
        <div className="mt-0.5 text-xs text-muted-foreground">{label}{sub ? ` · ${sub}` : ''}</div>
      </div>
    </div>
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
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        className="flex w-full items-center gap-2 rounded-t-xl px-4 py-3 text-left hover:bg-muted/40"
      >
        {Icon && <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />}
        <span className="text-sm font-medium uppercase tracking-wide text-muted-foreground">{title}</span>
        {badge && <span className="ml-2">{badge}</span>}
        <ChevronDown className={cn('ml-auto h-4 w-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
      </button>
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

export { TOOLTIP_STYLE, Cell }
