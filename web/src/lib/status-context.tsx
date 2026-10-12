import { createContext, useContext, useEffect, type ReactNode } from 'react'
import type { StatusPayload } from './api'
import { openStatusStream } from './api'
import { pushAlerts } from './alerts'
import { useSettings, useSmartPolling } from './hooks'

interface StatusCtx {
  data: StatusPayload | null
  error: string | null
  intervalMs: number
  refreshMs: number
  realtime: boolean
  setRealtime: (v: boolean) => void
}

const Ctx = createContext<StatusCtx>({ data: null, error: null, intervalMs: 3000, refreshMs: 3000, realtime: false, setRealtime: () => {} })

/**
 * Single source of truth for /api/status.
 *  - Default: smart polling at the Settings refresh interval.
 *  - Realtime: SSE (/api/events) pushes counts/jobs instantly on top of a
 *    full payload fetched at a slow 15s heartbeat (keeps health/alerts fresh).
 * Alerts are broadcast from here so the toast overlay works on every page.
 */
export function StatusProvider({ children }: { children: ReactNode }) {
  const { settings, update } = useSettings()
  const realtime = settings.prefs?.realtime === true
  const refreshMs = settings.prefs?.refresh ?? 3000
  const { data, error, intervalMs, setData } = useSmartPolling(realtime ? 15000 : refreshMs, true)

  useEffect(() => {
    if (!realtime) return
    const es = openStatusStream(
      (ev) =>
        setData(prev =>
          prev
            ? {
                ...prev,
                counts: ev.counts ?? prev.counts,
                jobs: ev.jobs ?? prev.jobs,
                timestamp: ev.timestamp ?? prev.timestamp,
              }
            : prev,
        ),
      () => undefined, // EventSource reconnects automatically
    )
    return () => es.close()
  }, [realtime, setData])

  useEffect(() => {
    if (data) pushAlerts(data.alerts ?? [])
  }, [data])

  const setRealtime = (v: boolean) => void update({ prefs: { ...settings.prefs, realtime: v } })

  return (
    <Ctx.Provider value={{ data, error, intervalMs, refreshMs, realtime, setRealtime }}>
      {children}
    </Ctx.Provider>
  )
}

export function useStatus(): StatusCtx {
  return useContext(Ctx)
}
