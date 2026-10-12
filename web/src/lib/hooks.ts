import { useEffect, useRef, useState } from 'react'
import { fetchStatus, type StatusPayload } from './api'

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
