import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, fetchStatus, postSettings, type SettingsResponse, type StatusPayload, type UiSettings } from './api'

/**
 * Smart polling (D7): adaptive interval. Base `ms` normally; when replies stop
 * changing, back off by `factor` up to `capMs`; snap back on any change.
 *
 * A hidden tab does no polling at all. This used to keep a 2.5 s timer running
 * while `document.hidden` purely to notice that the tab had become visible
 * again — one wasted request every 2.5 s, forever, for a tab nobody is looking
 * at. The `visibilitychange` listener below is the resume signal (it already
 * existed), so the timer is now cleared while hidden and the first thing the
 * tab does on becoming visible is a fresh tick.
 */
export function useSmartPolling(ms = 3000, enabled = true, factor = 3, capMs = 30000) {
  const [data, setData] = useState<StatusPayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [intervalMs, setIntervalMs] = useState(ms)
  const intervalRef = useRef(ms)
  const lastSig = useRef('')
  const msRef = useRef(ms)
  msRef.current = ms

  useEffect(() => {
    intervalRef.current = intervalMs
  }, [intervalMs])

  useEffect(() => {
    if (!enabled) return
    let stop = false
    let timer: ReturnType<typeof setTimeout> | null = null

    const clearTimer = () => {
      if (timer !== null) {
        clearTimeout(timer)
        timer = null
      }
    }

    const tick = async () => {
      if (stop) return
      // Nothing to poll for while hidden — `onVis` restarts us on return.
      if (document.hidden) { clearTimer(); return }
      let sigChanged = true
      try {
        const d = await fetchStatus()
        if (stop) return
        setData(d)
        setError(null)
        const sig = JSON.stringify(d.counts) + JSON.stringify(d.jobs) + (d.alerts?.length ?? 0)
        sigChanged = sig !== lastSig.current
        lastSig.current = sig
      } catch (e) {
        if (stop) return
        setError(String(e))
      }
      if (stop) return
      // The tab went away mid-request: drop the schedule, don't add a timer.
      if (document.hidden) { clearTimer(); return }
      setIntervalMs(m => (sigChanged ? msRef.current : Math.min(capMs, m * factor)))
      clearTimer()
      timer = setTimeout(tick, intervalRef.current)
    }

    const onVis = () => {
      if (document.hidden) {
        clearTimer()
        return
      }
      // Already scheduled (or in flight) — the pending tick will refresh.
      if (timer !== null) return
      void tick()
    }
    document.addEventListener('visibilitychange', onVis)

    void tick()
    return () => {
      stop = true
      clearTimer()
      document.removeEventListener('visibilitychange', onVis)
    }
  }, [enabled, factor, capMs, ms])

  return { data, error, intervalMs, setData }
}

const settingsCache: { data: UiSettings | null; fetched: boolean } = { data: null, fetched: false }

export function useSettings(): { settings: UiSettings; update: (patch: object) => Promise<SettingsResponse>; refresh: () => Promise<void> } {
  const [settings, setSettings] = useState<UiSettings>(settingsCache.data ?? {})
  const [ver, setVer] = useState(0)

  const refresh = useCallback(async () => {
    try {
      const res = await fetchSettings()
      settingsCache.data = res.settings ?? {}
      setSettings(settingsCache.data)
      setVer(v => v + 1)
    } catch {
      /* offline — keep cached */
    }
  }, [])

  useEffect(() => {
    if (settingsCache.fetched) return
    settingsCache.fetched = true
    void refresh()
  }, [refresh])

  const update = useCallback(async (patch: object) => {
    try {
      const res = await postSettings(patch)
      const merged: UiSettings = { ...settingsCache.data, ...res.settings }
      settingsCache.data = merged
      setSettings(merged)
      setVer(v => v + 1)
      return res
    } catch (e) {
      // postSettings rejects on non-2xx now. Most callers fire this off without
      // awaiting (sliders, ack buttons, toggles), so a failure used to vanish as
      // an unhandled rejection while the UI kept showing the new value. Surface
      // it, and answer with `{ error }` so `void update(...)` stays safe.
      const message = e instanceof Error ? e.message : String(e)
      toast.error(`settings not saved — ${message}`)
      return { error: message }
    }
  }, [])

  void ver
  return { settings, update, refresh }
}


/** Debounce high-frequency callbacks (e.g. slider onValueChange -> POST). */
export function useDebouncedCallback<A extends unknown[]>(fn: (...args: A) => void, delay = 400) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const fnRef = useRef(fn)
  fnRef.current = fn
  return useCallback((...args: A) => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => fnRef.current(...args), delay)
  }, [delay])
}
