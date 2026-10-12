import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchSettings, fetchStatus, postSettings, type SettingsResponse, type StatusPayload, type UiSettings } from './api'

export function usePolling(ms = 3000, enabled = true) {
  const [data, setData] = useState<StatusPayload | null>(null)
  const [error, setError] = useState<string | null>(null)
  const busy = useRef(false)

  useEffect(() => {
    if (!enabled) return
    let stop = false
    let hidden = document.hidden
    const onVis = () => { hidden = document.hidden }
    document.addEventListener('visibilitychange', onVis)
    const tick = async () => {
      if (hidden) return
      if (busy.current) return
      busy.current = true
      try {
        const d = await fetchStatus()
        if (!stop) {
          setData(d)
          setError(null)
        }
      } catch (e) {
        if (!stop) setError(String(e))
      } finally {
        busy.current = false
      }
    }
    tick()
    const id = setInterval(tick, ms)
    return () => {
      stop = true
      clearInterval(id)
      document.removeEventListener('visibilitychange', onVis)
    }
  }, [ms, enabled])

  return { data, error }
}

/**
 * Smart polling (D7): adaptive interval. Base `ms` normally; when replies stop
 * changing, back off by `factor` up to `capMs`; snap back on any change.
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
    let hidden = document.hidden
    const onVis = () => { hidden = document.hidden }
    document.addEventListener('visibilitychange', onVis)

    const tick = async () => {
      if (stop) return
      if (hidden) {
        timer = setTimeout(tick, 2500)
        return
      }
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
      setIntervalMs(m => (sigChanged ? msRef.current : Math.min(capMs, m * factor)))
      timer = setTimeout(tick, intervalRef.current)
    }

    tick()
    return () => {
      stop = true
      if (timer) clearTimeout(timer)
      document.removeEventListener('visibilitychange', onVis)
    }
  }, [enabled, factor, capMs, ms])

  return { data, error, intervalMs }
}

/** Rolling per-widget time window (D8): append value to a shared per-key buffer. */
const windowStore = new Map<string, { t: number; v: number }[]>()
export function useWindow(key: string, value?: number | null, max = 90): { t: number; v: number }[] {
  const [arr, setArr] = useState<{ t: number; v: number }[]>(() => windowStore.get(key) ?? [])

  useEffect(() => {
    if (value === undefined || value === null || Number.isNaN(value)) return
    const cur = windowStore.get(key) ?? []
    const next = [...cur, { t: Date.now(), v: value as number }]
    while (next.length > max) next.shift()
    windowStore.set(key, next)
    setArr(next)
  }, [key, value, max])

  return arr
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
    const res = await postSettings(patch)
    const merged: UiSettings = { ...settingsCache.data, ...res.settings }
    settingsCache.data = merged
    setSettings(merged)
    setVer(v => v + 1)
    return res
  }, [])

  void ver
  return { settings, update, refresh }
}

export function usePrefs(): UiSettings['prefs'] {
  const { settings } = useSettings()
  return settings.prefs ?? {}
}
