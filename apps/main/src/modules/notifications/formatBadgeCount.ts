/**
 * AH-108 (Q8) — how an unread count is drawn on a badge.
 *
 * `''` for zero (and for anything that is not a positive whole number, so a bad
 * value can never paint "NaN" or "-3" on a tab), the number itself up to 99, and
 * `99+` above. One helper for every badge the creator app draws (the bell and the
 * Messages tab), so the cap cannot drift between them. The app-icon badge is NOT
 * passed through this: the OS shows the number it is given.
 *
 * The accessible name keeps the REAL count; only the glyph is capped.
 */
export const BADGE_COUNT_CAP = 99

export function formatBadgeCount(count: number): string {
  if (!Number.isFinite(count) || count < 1) {
    return ''
  }
  const whole = Math.floor(count)
  return whole > BADGE_COUNT_CAP ? `${BADGE_COUNT_CAP}+` : String(whole)
}
