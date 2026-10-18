/**
 * Dashboard widget registry and visibility.
 *
 * The list used to live in SettingsPage while DashboardPage hardcoded the same
 * ids inline, so the two could drift — and the stored preference was an opt-IN
 * list of visible ids. That combination has a bug: a widget added after a
 * preference was saved is absent from the list, so it renders nothing and the
 * toggle that would reveal it sits next to a label for a panel that never
 * appears. Adding "Backups" did exactly that to every existing user.
 *
 * Stored state is therefore opt-OUT (`hiddenWidgets`), where a new id defaults
 * to visible. The legacy `widgets` list is migrated once: ids that predate it
 * keep their saved state, ids added since default to shown.
 */
export const WIDGETS: Array<[string, string]> = [
  ['kpi', 'KPI row'],
  ['fleet', 'Sources fleet'],
  ['dataset', 'Dataset donut'],
  ['health', 'Merge quality'],
  ['backups', 'Backups'],
]

export const WIDGET_IDS = WIDGETS.map(([id]) => id)

/**
 * Ids that existed when the legacy opt-in `widgets` list was still being
 * written. Anything outside this set was added afterwards and must default to
 * visible rather than being treated as deliberately hidden.
 */
const LEGACY_IDS = ['kpi', 'fleet', 'dataset', 'health']

/** Widget ids the user has switched off. */
export function hiddenWidgets(prefs: { widgets?: unknown; hiddenWidgets?: unknown }): string[] {
  if (Array.isArray(prefs.hiddenWidgets)) {
    return (prefs.hiddenWidgets as string[]).filter(id => WIDGET_IDS.includes(id))
  }
  if (Array.isArray(prefs.widgets)) {
    const shown = prefs.widgets as string[]
    return LEGACY_IDS.filter(id => !shown.includes(id))
  }
  return []
}

/** Toggle one widget, returning the id list to persist. */
export function toggleWidgetId(prefs: { widgets?: unknown; hiddenWidgets?: unknown }, id: string): string[] {
  const hidden = hiddenWidgets(prefs)
  return hidden.includes(id) ? hidden.filter(x => x !== id) : [...hidden, id]
}
