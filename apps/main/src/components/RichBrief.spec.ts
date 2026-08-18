/**
 * Vitest coverage for `RichBrief` (AH-081) — the one `v-html` call site for
 * every converted brief render site. Sanitizer correctness itself is pinned
 * by `useRichBriefRenderer.spec.ts`; this spec covers the component wiring:
 * the prop flows through `renderRichBrief`, attrs fall through to the root
 * (so a caller's existing `data-test` selector survives the conversion),
 * and `null`/`undefined`/empty input renders nothing unsafe.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import RichBrief from './RichBrief.vue'

describe('RichBrief', () => {
  it('renders sanitized markdown as HTML', () => {
    const wrapper = mount(RichBrief, { props: { text: '**bold** and *italic*' } })
    expect(wrapper.html()).toContain('<strong>bold</strong>')
    expect(wrapper.html()).toContain('<em>italic</em>')
  })

  it('forwards data-test / data-testid attrs to the root element (selector-stable conversion)', () => {
    const wrapper = mount(RichBrief, {
      props: { text: 'hello' },
      attrs: { 'data-test': 'overview-description' },
    })
    expect(wrapper.attributes('data-test')).toBe('overview-description')
  })

  it('never renders a script tag, even given raw HTML input', () => {
    const wrapper = mount(RichBrief, { props: { text: '<script>alert(1)</script>' } })
    expect(wrapper.html()).not.toContain('<script')
  })

  it('handles null / undefined / empty text without throwing', () => {
    expect(() => mount(RichBrief, { props: { text: null } })).not.toThrow()
    expect(() => mount(RichBrief, { props: { text: undefined } })).not.toThrow()
    expect(
      mount(RichBrief, { props: { text: '' } })
        .find('.rich-brief')
        .text(),
    ).toBe('')
  })
})
