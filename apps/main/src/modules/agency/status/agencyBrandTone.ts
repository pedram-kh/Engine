/**
 * AH-114 (S3.1) — which colour a brand's mark takes. A brand has no colour in the data, so it
 * is chosen from the brand's id by a fixed hash: the same brand is always the same colour, on
 * every page and in both themes.
 *
 * Four tones, all of which are in the colour-parity spec's pill tones (so the letter in the
 * `*-text` colour on its 15 % tint is AA-checked in both themes, over the canvas, a card and a
 * raised surface). Not red (reads as danger) and not the brand mint (its light text value
 * falls just under 4.5:1 on a tint over the canvas).
 */

export const BRAND_MARK_TONES = ['info', 'success', 'warning', 'ai'] as const
export type BrandMarkTone = (typeof BRAND_MARK_TONES)[number]

/** FNV-1a over UTF-16 code units, folded onto the tone list. */
export function brandMarkTone(seed: string): BrandMarkTone {
  let hash = 0x811c9dc5
  for (let i = 0; i < seed.length; i += 1) {
    hash ^= seed.charCodeAt(i)
    hash = Math.imul(hash, 0x01000193)
  }
  return BRAND_MARK_TONES[(hash >>> 0) % BRAND_MARK_TONES.length] as BrandMarkTone
}
