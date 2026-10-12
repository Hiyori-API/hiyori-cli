import { useEffect, useState } from 'react'
import type { AlertItem } from './api'

/** Tiny pub/sub so the shell can render alerts as a fixed overlay (no layout shift). */
let current: AlertItem[] = []
const listeners = new Set<(a: AlertItem[]) => void>()

export function pushAlerts(items: AlertItem[]): void {
  current = items
  listeners.forEach(l => l(items))
}

export function useAlerts(): AlertItem[] {
  const [alerts, setAlerts] = useState<AlertItem[]>(current)
  useEffect(() => {
    const l = (a: AlertItem[]) => setAlerts(a)
    listeners.add(l)
    return () => { listeners.delete(l) }
  }, [])
  return alerts
}
