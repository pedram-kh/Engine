/**
 * Menus and selects opened from a dialog of this module sit above it (the PL-1.3 z-index lesson): Vuetify gives an
 * overlay the stack's top z-index plus 10 and falls back to this only when the stack is empty.
 */
export { MENU_ABOVE_DIALOG_Z } from '@/modules/waves/constants'

/** The menu props every menu and select inside a dialog of this module passes. */
export const MENU_PROPS = {
  zIndex: 2500,
  maxHeight: 'calc(100dvh - 24px)',
  contentClass: 'strategy-menu',
} as const

/**
 * The four steps that work (PL-2.1 USPs and personas, PL-2.2 the matrix, PL-2.3 the concepts) and the two that are not
 * built. Steps 5 and 6 are never locked (C6): they say "Not available yet" and carry the concepts warning.
 */
export const STEPS = [
  { n: 1, key: 'usps', state: 'live' },
  { n: 2, key: 'personas', state: 'live' },
  { n: 3, key: 'matrix', state: 'live' },
  { n: 4, key: 'concepts', state: 'live' },
  { n: 5, key: 'creators', state: 'not_built' },
  { n: 6, key: 'scripts', state: 'not_built' },
] as const

export type StepKey = (typeof STEPS)[number]['key']
