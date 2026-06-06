/**
 * The Q2 → boardStatus token map (Sprint 12 Chunk 2, D-11) — net-new, FE-side.
 *
 * The Chunk 1 handoff contract: `color_token` is STORED prefixed
 * (`status-todefine`), but the `boardStatus` palette in `@catalyst/design-tokens`
 * keys are UNprefixed (`todefine`). This helper strips the `status-` prefix and
 * resolves the hex, so the column header + card color strip render the right
 * colour without re-casing the wire contract.
 *
 * The hex literal lives HERE (a `.ts`), never in a `.vue` — the strip binds via
 * `:style="{ backgroundColor: boardColorHex(token) }"`, which keeps it clear of
 * BOTH the `no-hard-coded-colors` (no `.vue` hex literal) and the
 * `no-inline-color-styles` (camelCase object-binding ≠ literal `background:`)
 * architecture tests. `boardStatus` is theme-INVARIANT status semantics (a
 * "Paid" column is green in both light + dark), so it deliberately does NOT live
 * in the Vuetify theme.
 */

import { boardStatus } from '@catalyst/design-tokens'

const STATUS_PREFIX = 'status-'

/** The neutral fallback (the "To Define" grey) for an unknown / missing token. */
const FALLBACK_HEX = boardStatus.todefine

/**
 * Resolve a stored `color_token` (e.g. `status-paid`) to its hex value
 * (`#16A34A`). Strips the `status-` prefix (D-11) and falls back to the neutral
 * grey for an unknown or absent token.
 */
export function boardColorHex(colorToken: string | null | undefined): string {
  if (colorToken === null || colorToken === undefined || colorToken === '') {
    return FALLBACK_HEX
  }
  const key = colorToken.startsWith(STATUS_PREFIX)
    ? colorToken.slice(STATUS_PREFIX.length)
    : colorToken
  const palette = boardStatus as Record<string, string>
  return palette[key] ?? FALLBACK_HEX
}

/**
 * The palette as `{ token, hex }` options for the column colour picker. The
 * `token` is the PREFIXED spelling the create/update API validates against
 * (`BoardDefaults::colorTokens()`).
 */
export function boardColorOptions(): ReadonlyArray<{ token: string; hex: string }> {
  return (Object.keys(boardStatus) as Array<keyof typeof boardStatus>).map((key) => ({
    token: `${STATUS_PREFIX}${key}`,
    hex: boardStatus[key],
  }))
}
