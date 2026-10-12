import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, X } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useAlerts } from '@/lib/alerts'

/**
 * Non-layout-shifting alert overlay: fixed to the top-right of the viewport.
 * Warn-level toasts auto-dismiss after ~12s; critical ones persist until dismissed.
 */
export function AlertToasts() {
  const alerts = useAlerts()
  const [acked, setAcked] = useState<Set<string>>(new Set())

  const critical = alerts.filter(a => a.level === 'critical')
  const warns = alerts.filter(a => a.level !== 'critical')

  useEffect(() => {
    if (warns.length === 0) return
    const t = setTimeout(() => {
      setAcked(s => new Set([...s, ...warns.map(w => w.msg ?? w.type ?? 'w')]))
    }, 12000)
    return () => clearTimeout(t)
  }, [warns])

  const visible = [...critical, ...warns].filter(a => !acked.has(a.msg ?? a.type ?? 'w'))
  if (visible.length === 0) return null

  return (
    <div role="status" aria-live="assertive" className="pointer-events-none fixed top-16 right-5 z-[60] flex w-80 flex-col gap-2">
      {visible.map((a, i) => {
        const id = a.msg ?? a.type ?? `a${i}` + (a.source ?? '')
        return (
          <div
            key={id}
            className={`pointer-events-auto flex items-start gap-2 rounded-xl border px-3 py-2.5 text-sm shadow-lg backdrop-blur ${
              a.level === 'critical'
                ? 'border-red-500/60 bg-red-500/15 text-red-400'
                : 'border-amber-500/60 bg-amber-500/15 text-amber-400'
            }`}
          >
            {a.level === 'critical' ? <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />}
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1.5">
                <span className="font-medium">{a.level === 'critical' ? 'Outage' : 'Degraded'}</span>
                {a.source && a.source !== '*' && <Badge variant="outline">{a.source}</Badge>}
              </div>
              <p className="mt-0.5 text-xs">{a.msg}</p>
            </div>
            <Button size="icon-xs" variant="ghost" aria-label="Dismiss alert" onClick={() => setAcked(s => new Set(s).add(id))}><X className="h-3 w-3" /></Button>
          </div>
        )
      })}
    </div>
  )
}
