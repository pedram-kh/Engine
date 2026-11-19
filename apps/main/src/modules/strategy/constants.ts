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

/** The two steps that work in PL-2.1, and the four that are shown but not built. */
export const STEPS = [
  { n: 1, key: 'usps', state: 'live' },
  { n: 2, key: 'personas', state: 'live' },
  { n: 3, key: 'matrix', state: 'not_built' },
  { n: 4, key: 'concepts', state: 'not_built' },
  { n: 5, key: 'creators', state: 'locked' },
  { n: 6, key: 'scripts', state: 'locked' },
] as const

export type StepKey = (typeof STEPS)[number]['key']
