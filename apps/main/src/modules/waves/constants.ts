/** Mirrors `WaveController`: a wave has 1 to 20 markets. */
export const MAX_MARKETS = 20

/**
 * Vuetify gives an overlay the stack's top z-index plus 10, and falls back to its own `zIndex` prop only when the
 * stack is empty. A menu's default is 2000 and a dialog's is 2400, so a menu that activates while the dialog is not
 * in the stack sits behind it. Menus inside the New wave dialog pass this so that case is still above the dialog.
 */
export const MENU_ABOVE_DIALOG_Z = 2500
