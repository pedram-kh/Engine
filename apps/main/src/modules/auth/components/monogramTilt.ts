/**
 * Pointer-to-tilt math for the footer monogram.
 *
 * Split out from the component so the geometry is testable without a
 * layout engine: jsdom reports a zero-sized box for every element, so a
 * mounted component can never exercise these numbers. The component owns
 * only the listener wiring; every value below is derived here.
 *
 * Mirrors the interaction on catalyst-growth.com: the artwork rotates
 * toward the cursor within ±18°, and a diagonal glare slides across it.
 */

/** The tilt and glare offsets for one pointer position. */
export interface MonogramTilt {
  /** Degrees about the vertical axis; positive turns the right edge away. */
  readonly rotateY: number
  /** Degrees about the horizontal axis; positive lifts the bottom edge. */
  readonly rotateX: number
  /** Glare offset along X, as a percentage of the glare rect. */
  readonly glareX: number
  /** Glare offset along Y, as a percentage of the glare rect. */
  readonly glareY: number
}

/** Peak rotation at the edges of the artwork, in degrees. */
const MAX_DEGREES = 18

/** Peak glare travel, as a percentage of the glare rect. */
const MAX_GLARE_PERCENT = 90

/** The at-rest state: square to the viewer, glare parked and invisible. */
export const MONOGRAM_TILT_REST: MonogramTilt = {
  rotateY: 0,
  rotateX: 0,
  glareX: 0,
  glareY: 0,
}

/**
 * Where the artwork should sit for a pointer at (`clientX`, `clientY`)
 * over `bounds`.
 *
 * A degenerate box — zero width or height, which is what jsdom reports
 * and what a `display: none` element reports in a real browser — yields
 * the rest state rather than a division by zero.
 */
export function monogramTiltFor(
  bounds: { left: number; top: number; width: number; height: number },
  clientX: number,
  clientY: number,
): MonogramTilt {
  if (bounds.width <= 0 || bounds.height <= 0) {
    return MONOGRAM_TILT_REST
  }

  // -0.5 at the left/top edge through +0.5 at the right/bottom.
  const fromCentreX = (clientX - bounds.left) / bounds.width - 0.5
  const fromCentreY = (clientY - bounds.top) / bounds.height - 0.5

  return {
    rotateY: fromCentreX * MAX_DEGREES,
    // Negated so the artwork leans toward the cursor rather than away.
    rotateX: withoutNegativeZero(fromCentreY * -MAX_DEGREES),
    glareX: fromCentreX * MAX_GLARE_PERCENT,
    glareY: fromCentreY * MAX_GLARE_PERCENT,
  }
}

/**
 * Collapses `-0` onto `0`.
 *
 * Negating the vertical term turns a dead-centre pointer into `-0`, which
 * would reach the DOM as the faintly absurd `-0deg` and makes the rest
 * state fail an equality check against a plain zero.
 */
function withoutNegativeZero(value: number): number {
  return value === 0 ? 0 : value
}
