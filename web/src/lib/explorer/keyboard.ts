/**
 * Keyboard map for the Explorer page. Pure mapping — the page owns refs and
 * side effects. Returns null when the keypress should be ignored (e.g. while
 * typing in an input).
 */

export type KeyAction =
  | { type: 'focus-search' }
  | { type: 'row'; delta: 1 | -1 }
  | { type: 'open-active' }
  | { type: 'close' }
  | { type: 'copy-selected' }
  | { type: 'g' } // first half of the `g e` chord
  | { type: 'ge' } // full `g e` chord → focus the collection switch

const TYPING_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT'])

export function isTypingTarget(t: EventTarget | null): boolean {
  if (!(t instanceof Element)) return false
  if (TYPING_TAGS.has(t.tagName)) return true
  return t instanceof HTMLElement && t.isContentEditable
}

export function mapKey(e: KeyboardEvent): KeyAction | null {
  if (e.ctrlKey || e.metaKey || e.altKey) return null
  const k = e.key
  if (k === '/') return { type: 'focus-search' }
  if (k === 'ArrowDown' || k === 'j') return { type: 'row', delta: 1 }
  if (k === 'ArrowUp' || k === 'k') return { type: 'row', delta: -1 }
  if (k === 'Enter') return { type: 'open-active' }
  if (k === 'Escape') return { type: 'close' }
  if (k === 'c' || k === 'C') return { type: 'copy-selected' }
  if (k === 'g') return { type: 'g' }
  if (k === 'e') return { type: 'ge' }
  return null
}