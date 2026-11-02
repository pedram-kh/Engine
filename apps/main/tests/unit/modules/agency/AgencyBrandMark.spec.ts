/**
 * AH-114 (S3.1) — `AgencyBrandMark` and the tone hash: the mark is the brand's first letter,
 * the colour is a pure function of the seed (the same brand is always the same colour), and
 * only the four tones the colour-parity spec covers can come out. Colour and size are pinned
 * in a real browser by `tools/walk/pins-s31.mjs`.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AgencyBrandMark from '@/modules/agency/components/AgencyBrandMark.vue'
import { BRAND_MARK_TONES, brandMarkTone } from '@/modules/agency/status/agencyBrandTone'

describe('brandMarkTone', () => {
  it('is deterministic', () => {
    expect(brandMarkTone('01HBRANDJACKJILL0000000001')).toBe(
      brandMarkTone('01HBRANDJACKJILL0000000001'),
    )
  })

  it('only ever returns one of the four tones, red and the brand mint excluded', () => {
    const seen = new Set<string>()
    for (let i = 0; i < 400; i += 1) seen.add(brandMarkTone(`brand-${i}`))
    expect([...seen].sort()).toStrictEqual([...BRAND_MARK_TONES].sort())
    expect(seen.has('danger')).toBe(false)
    expect(seen.has('primary')).toBe(false)
  })

  it('spreads brands over every tone (no tone is starved)', () => {
    const counts = new Map<string, number>()
    for (let i = 0; i < 400; i += 1) {
      const tone = brandMarkTone(`01HBRAND${i.toString().padStart(18, '0')}`)
      counts.set(tone, (counts.get(tone) ?? 0) + 1)
    }
    for (const tone of BRAND_MARK_TONES) {
      expect(counts.get(tone) ?? 0, tone).toBeGreaterThan(40)
    }
  })
})

describe('AgencyBrandMark', () => {
  it('draws the first letter, upper case, hidden from assistive tech', () => {
    const wrapper = mount(AgencyBrandMark, { props: { name: 'jack & Jill' } })
    const mark = wrapper.get('[data-test="agency-brand-mark"]')
    expect(mark.text()).toBe('J')
    expect(mark.attributes('aria-hidden')).toBe('true')
  })

  it('takes the first whole character, not half a surrogate pair', () => {
    const wrapper = mount(AgencyBrandMark, { props: { name: '🙂 Smile' } })
    expect(wrapper.text()).toBe('🙂')
  })

  it('renders nothing for an empty or blank name', () => {
    expect(
      mount(AgencyBrandMark, { props: { name: '' } })
        .find('span')
        .exists(),
    ).toBe(false)
    expect(
      mount(AgencyBrandMark, { props: { name: '   ' } })
        .find('span')
        .exists(),
    ).toBe(false)
  })

  it('takes its tone from the seed, falling back to the name', () => {
    const bySeed = mount(AgencyBrandMark, { props: { name: 'Cleo', seed: 'abc' } })
    expect(bySeed.attributes('data-tone')).toBe(brandMarkTone('abc'))
    expect(bySeed.classes()).toContain(`agency-brand-mark--${brandMarkTone('abc')}`)

    const byName = mount(AgencyBrandMark, { props: { name: 'Cleo' } })
    expect(byName.attributes('data-tone')).toBe(brandMarkTone('Cleo'))
  })
})
