/**
 * The single place the document theme is applied.
 *
 * Two class families have to move together or the app renders half-themed:
 * `dark` drives the Tailwind token layer, and `cds--g100` drives Carbon's own
 * components (Carbon's precompiled stylesheet keys its themes off that class).
 *
 * This is one exported function on purpose. It previously existed alongside a
 * `useTheme` hook that nothing imported and a second copy of the toggle logic
 * inside the Settings page; those two copies drifted, and Settings removed
 * `dark` without removing `cds--g100`, leaving Carbon dark while the rest of
 * the page went light. There is now one function and one caller
 * (`useSettings`, which owns the server-side preference).
 */
export function applyTheme(dark: boolean): void {
  const root = document.documentElement
  root.classList.toggle('dark', dark)
  root.classList.toggle('cds--g100', dark)
}
