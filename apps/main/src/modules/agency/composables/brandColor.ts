/**
 * AH-119 — a brand's palette key as a CSS colour. The values live in `agency.css`
 * (`--agency-brand-*`, agency themes only); an unset or unknown key reads as the slate neutral,
 * so a brand with no colour still gets a dot.
 */

import type { BrandColorKey } from '@catalyst/api-client'

export const BRAND_COLOR_KEYS: readonly BrandColorKey[] = [
  'violet',
  'green',
  'amber',
  'blue',
  'pink',
  'teal',
  'red',
  'slate',
]

export function brandColorVar(key: string | null | undefined): string {
  const known = BRAND_COLOR_KEYS.find((k) => k === key) ?? 'slate'
  return `var(--agency-brand-${known})`
}

/**
 * The palette key a brand shows in the inbox: its own when set, else one derived from its id by a
 * fixed hash (A4: existing brands keep `color` null, nothing is written). The same brand is always
 * the same colour.
 */
export function effectiveBrandColor(key: string | null | undefined, seed: string): BrandColorKey {
  const own = BRAND_COLOR_KEYS.find((k) => k === key)
  if (own !== undefined) {
    return own
  }
  let hash = 0
  for (const ch of seed) {
    hash = (hash * 31 + ch.codePointAt(0)!) >>> 0
  }
  return BRAND_COLOR_KEYS[hash % BRAND_COLOR_KEYS.length] ?? 'slate'
}
