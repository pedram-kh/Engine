/**
 * Unit tests for the footer monogram's pointer geometry.
 *
 * These numbers are unreachable from a mounted component: jsdom gives
 * every element a zero-sized bounding box, which is exactly the
 * degenerate case the function guards. Testing the maths directly is the
 * only way to pin the sign conventions — a flipped sign is invisible in a
 * screenshot but makes the artwork lean away from the cursor.
 */

import { describe, expect, it } from 'vitest'

import { MONOGRAM_TILT_REST, monogramTiltFor } from './monogramTilt'

/** A 600×600 box at the viewport origin, so client coords read directly. */
const BOX = { left: 0, top: 0, width: 600, height: 600 }

describe('monogramTiltFor', () => {
  it('sits at rest when the pointer is dead centre', () => {
    expect(monogramTiltFor(BOX, 300, 300)).toEqual(MONOGRAM_TILT_REST)
  })

  it('reaches the full ±18° at the corners', () => {
    // Bottom-right: rotateY positive (turns the right edge back) and
    // rotateX negative (drops the near edge toward the cursor).
    expect(monogramTiltFor(BOX, 600, 600)).toEqual({
      rotateY: 9,
      rotateX: -9,
      glareX: 45,
      glareY: 45,
    })
    // Top-left mirrors it exactly.
    expect(monogramTiltFor(BOX, 0, 0)).toEqual({
      rotateY: -9,
      rotateX: 9,
      glareX: -45,
      glareY: -45,
    })
  })

  it('leans toward the cursor, not away from it', () => {
    // The vertical axis is the one that is negated; asserting the sign
    // relationship directly is what catches an accidental flip.
    const below = monogramTiltFor(BOX, 300, 600)
    expect(below.rotateX).toBeLessThan(0)
    const above = monogramTiltFor(BOX, 300, 0)
    expect(above.rotateX).toBeGreaterThan(0)

    const right = monogramTiltFor(BOX, 600, 300)
    expect(right.rotateY).toBeGreaterThan(0)
  })

  it('scales linearly between the centre and the edge', () => {
    const quarter = monogramTiltFor(BOX, 450, 300)
    const edge = monogramTiltFor(BOX, 600, 300)
    expect(quarter.rotateY).toBeCloseTo(edge.rotateY / 2, 10)
    expect(quarter.glareX).toBeCloseTo(edge.glareX / 2, 10)
  })

  it('accounts for a box that is offset in the viewport', () => {
    // Same relative position as the centre case, just scrolled/offset —
    // the result must be identical, which is what proves left/top are
    // subtracted rather than ignored.
    const offset = { left: 200, top: 1000, width: 600, height: 600 }
    expect(monogramTiltFor(offset, 500, 1300)).toEqual(MONOGRAM_TILT_REST)
  })

  // §5.34 negative set: boxes that cannot yield a meaningful ratio. Each
  // would be a division by zero (NaN transforms, which browsers drop
  // silently) rather than an obvious failure.
  it.each([
    ['zero width', { left: 0, top: 0, width: 0, height: 600 }],
    ['zero height', { left: 0, top: 0, width: 600, height: 0 }],
    ['zero both', { left: 0, top: 0, width: 0, height: 0 }],
    ['negative width', { left: 0, top: 0, width: -10, height: 600 }],
  ])('returns the rest state for a degenerate box (%s)', (_label, box) => {
    const result = monogramTiltFor(box, 42, 42)
    expect(result).toEqual(MONOGRAM_TILT_REST)
    expect(Number.isNaN(result.rotateX)).toBe(false)
    expect(Number.isNaN(result.rotateY)).toBe(false)
  })

  it('exposes a rest state that is genuinely neutral', () => {
    expect(MONOGRAM_TILT_REST).toEqual({ rotateY: 0, rotateX: 0, glareX: 0, glareY: 0 })
  })
})
