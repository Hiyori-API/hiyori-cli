/** Timezone-aware time formatting for absolute timestamps.
 *  Settings → Live dashboard → Timezone stores prefs.tz ('local' | 'utc');
 *  pages render timestamps through these helpers so the pref actually applies. */

export type Tz = 'local' | 'utc'

function zoneOptions(tz: Tz): { timeZone?: string } {
  return tz === 'utc' ? { timeZone: 'UTC' } : {}
}

function toDate(ts: string | number | Date | null | undefined): Date | null {
  if (ts === undefined || ts === null || ts === '') return null
  const d = ts instanceof Date ? ts : new Date(ts)
  return Number.isNaN(d.getTime()) ? null : d
}

/** Current instant. The zone matters only when rendering; pass it to tzStr/tzDateTime. */
export function tzNow(_tz: Tz): Date {
  return new Date()
}

/** Time-of-day, e.g. "14:32:05", formatted in the active zone. */
export function tzStr(ts: string | number | Date | null | undefined, tz: Tz): string {
  const d = toDate(ts)
  return d ? d.toLocaleTimeString(undefined, zoneOptions(tz)) : '—'
}

/** Date + time, e.g. "10/13/2026, 2:32:05 PM", formatted in the active zone. */
export function tzDateTime(ts: string | number | Date | null | undefined, tz: Tz): string {
  const d = toDate(ts)
  return d ? d.toLocaleString(undefined, zoneOptions(tz)) : '—'
}
