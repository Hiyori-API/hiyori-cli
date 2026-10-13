import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
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
  sseOpen: boolean
  sseReconnects: number
  batterySaver: boolean
}

const Ctx = createContext<StatusCtx>({ data: null, error: null, intervalMs: 3000, refreshMs: 3000, realtime: false, setRealtime: () => {}, sseOpen: false, sseReconnects: 0, batterySaver: false })

/**
 * Single source of truth for /api/status.
 *  - Default: smart polling at the Settings refresh interval.
 *  - Realtime: SSE (/api/events) pushes counts/jobs instantly on top of a
 *    full payload fetched at a slow 15s heartbeat (keeps health/alerts fresh).
 * Alerts are broadcast from here so the toast overlay works on every page.
 */
export function StatusProvider({ children }: { children: ReactNode }) {
  const { settings, update } = useSettings()
  // Realtime (SSE) is the default; users can turn it off in the header/Settings.
  const realtime = settings.prefs?.realtime !== false
  const refreshMs = settings.prefs?.refresh ?? 3000

  // Battery saver: slow the poll when on battery < 20% and not charging.
  const [batterySaver, setBatterySaver] = useState(false)
  useEffect(() => {
    const nav = navigator as Navigator & { getBattery?: () => Promise<{ charging: boolean; level: number; addEventListener: (t: string, cb: () => void) => void; removeEventListener: (t: string, cb: () => void) => void }> }
    if (!nav.getBattery) return
    let battery: (ReturnType<NonNullable<typeof nav.getBattery>>) extends Promise<infer B> ? B : never | undefined
    void nav.getBattery().then((b) => {
      battery = b
      const upd = () => setBatterySaver(!b.charging && b.level < 0.2)
      upd()
      b.addEventListener('levelchange', upd)
      b.addEventListener('chargingchange', upd)
    })
    return () => {
      if (battery) {
        battery.removeEventListener('levelchange', () => setBatterySaver(false))
      }
    }
  }, [])

  const [sseOpen, setSseOpen] = useState(false)
  const [sseReconnects, setSseReconnects] = useState(0)

  const pollInterval = batterySaver ? 30000 : refreshMs
  const { data, error, intervalMs, setData } = useSmartPolling(realtime ? 15000 : pollInterval, true)

  useEffect(() => {
    if (!realtime) return
    setSseOpen(false)
    setSseReconnects(0)
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
      () => {
        setSseOpen(false)
        setSseReconnects(n => n + 1)
      },
    )
    es.addEventListener('open', () => setSseOpen(true))
    return () => es.close()
  }, [realtime, setData])

  useEffect(() => {
    if (data) pushAlerts(data.alerts ?? [])
  }, [data])

  const setRealtime = (v: boolean) => void update({ prefs: { ...settings.prefs, realtime: v } })

  return (
    <Ctx.Provider value={{ data, error, intervalMs, refreshMs, realtime, setRealtime, sseOpen, sseReconnects, batterySaver }}>
      {children}
    </Ctx.Provider>
  )
}

export function useStatus(): StatusCtx {
  return useContext(Ctx)
}
