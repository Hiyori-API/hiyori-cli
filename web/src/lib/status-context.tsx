import { createContext, useContext, useEffect, type ReactNode } from 'react'
import type { StatusPayload } from './api'
import { pushAlerts } from './alerts'
import { useSettings, useSmartPolling } from './hooks'

interface StatusCtx {
  data: StatusPayload | null
  error: string | null
  intervalMs: number
  refreshMs: number
}

const Ctx = createContext<StatusCtx>({ data: null, error: null, intervalMs: 3000, refreshMs: 3000 })

/**
 * Single /api/status poller for the whole app (audit A3/B4): pages read this
 * context instead of mounting their own poller, and alerts are broadcast from
 * here so the top-right overlay works on every page.
 */
export function StatusProvider({ children }: { children: ReactNode }) {
  const { settings } = useSettings()
  const refreshMs = settings.prefs?.refresh ?? 3000
  const { data, error, intervalMs } = useSmartPolling(refreshMs, true)

  useEffect(() => {
    if (data) pushAlerts(data.alerts ?? [])
  }, [data])

  return <Ctx.Provider value={{ data, error, intervalMs, refreshMs }}>{children}</Ctx.Provider>
}

export function useStatus(): StatusCtx {
  return useContext(Ctx)
}
