/**
 * Component tests for the footer monogram.
 *
 * Two things are worth pinning. First, that the artwork is genuinely
 * inlined and carries the hooks the animation depends on — if the asset
 * is ever re-exported and loses a class, the motion silently stops and
 * nothing else would catch it. Second, that the pointer wiring writes the
 * custom properties the stylesheet eases, and parks them on leave.
 *
 * jsdom reports a zero-sized box for every element, so the values written
 * here are the rest state by construction; the geometry itself is covered
 * in monogramTilt.spec.ts. What this file proves is that the handlers are
 * connected and that the engaged flag tracks the pointer.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AuthFooterMonogram from './AuthFooterMonogram.vue'

describe('AuthFooterMonogram', () => {
  it('inlines the artwork rather than linking it as an image', () => {
    const w = mount(AuthFooterMonogram)

    // An <img> would be opaque to the CSS that drives the motion.
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('[data-test="auth-footer-monogram"] svg').exists()).toBe(true)
  })

  it('keeps the artwork out of the accessibility tree', () => {
    const w = mount(AuthFooterMonogram)

    const svg = w.find('svg')
    expect(svg.attributes('aria-hidden')).toBe('true')
    expect(svg.attributes('role')).toBe('presentation')
    expect(svg.attributes('focusable')).toBe('false')
  })

  it('ships every hook the animation targets', () => {
    const w = mount(AuthFooterMonogram)
    const svg = w.find('svg').element

    // Each selector is one the stylesheet animates; a missing class means
    // that effect is dead.
    for (const selector of ['.cm-orbit-purple', '.cm-orbit-teal', '.cm-sheen', '.cm-glare']) {
      expect(svg.querySelectorAll(selector).length, `missing ${selector}`).toBeGreaterThan(0)
    }
    // The orbs are drawn twice — clipped to the card, then unclipped for
    // the outer halo — so both layers must move together.
    expect(svg.querySelectorAll('.cm-orbit-purple')).toHaveLength(2)
    expect(svg.querySelectorAll('.cm-orbit-teal')).toHaveLength(2)
  })

  it('starts square to the viewer with the glare parked', () => {
    const w = mount(AuthFooterMonogram)

    const style = w.find('[data-test="auth-footer-monogram"]').attributes('style') ?? ''
    expect(style).toContain('--monogram-rotate-x: 0deg')
    expect(style).toContain('--monogram-rotate-y: 0deg')
    expect(w.classes()).not.toContain('monogram--engaged')
  })

  it('engages on pointer movement and writes the tilt custom properties', async () => {
    const w = mount(AuthFooterMonogram)

    await w
      .find('[data-test="auth-footer-monogram"]')
      .trigger('pointermove', { clientX: 120, clientY: 90 })

    expect(w.classes()).toContain('monogram--engaged')
    const style = w.find('[data-test="auth-footer-monogram"]').attributes('style') ?? ''
    for (const prop of [
      '--monogram-rotate-x',
      '--monogram-rotate-y',
      '--monogram-glare-x',
      '--monogram-glare-y',
    ]) {
      expect(style).toContain(prop)
    }
  })

  it('returns to rest when the pointer leaves', async () => {
    const w = mount(AuthFooterMonogram)
    const root = w.find('[data-test="auth-footer-monogram"]')

    await root.trigger('pointermove', { clientX: 120, clientY: 90 })
    expect(w.classes()).toContain('monogram--engaged')

    await root.trigger('pointerleave')

    expect(w.classes()).not.toContain('monogram--engaged')
    const style = root.attributes('style') ?? ''
    expect(style).toContain('--monogram-rotate-x: 0deg')
    expect(style).toContain('--monogram-rotate-y: 0deg')
    expect(style).toContain('--monogram-glare-x: 0%')
    expect(style).toContain('--monogram-glare-y: 0%')
  })
})
