/** PL-3.1 F2 — a ceiling is never shown as a target; the count is, and the ceiling only near it. */
/** A ceiling is shown only this close to it (25 of 30); farther away the page says how many there are, not how many fit. */
export const NEAR_LIMIT_MARGIN = 5

/** True when no more than the margin of places is left (so 25 or more of 30). */
export function nearLimit(left: number): boolean {
  return left <= NEAR_LIMIT_MARGIN
}
