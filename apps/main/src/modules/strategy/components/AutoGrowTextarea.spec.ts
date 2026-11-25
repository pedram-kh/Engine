import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import AutoGrowTextarea from './AutoGrowTextarea.vue'

/** jsdom has no layout: `scrollHeight` is faked from the number of lines, 19 px each, like the page's line height. */
function fakeLayout(): void {
  Object.defineProperty(HTMLTextAreaElement.prototype, 'scrollHeight', {
    configurable: true,
    get(this: HTMLTextAreaElement) {
      return this.value.split('\n').length * 19
    },
  })
}

afterEach(() => {
  delete (HTMLTextAreaElement.prototype as unknown as Record<string, unknown>).scrollHeight
  vi.restoreAllMocks()
})

describe('AutoGrowTextarea', () => {
  it('is as tall as its text on load, with a long saved value', () => {
    fakeLayout()
    const w = mount(AutoGrowTextarea, { props: { modelValue: 'a\nb\nc\nd' } })
    expect((w.get('textarea').element as HTMLTextAreaElement).style.height).toBe('76px')
  })

  it('grows while typing a second and a third line, and shrinks back', async () => {
    fakeLayout()
    const w = mount(AutoGrowTextarea, { props: { modelValue: '' } })
    const box = w.get('textarea')
    await box.setValue('one\ntwo')
    expect((box.element as HTMLTextAreaElement).style.height).toBe('38px')
    await box.setValue('one\ntwo\nthree')
    expect((box.element as HTMLTextAreaElement).style.height).toBe('57px')
    await box.setValue('one')
    expect((box.element as HTMLTextAreaElement).style.height).toBe('19px')
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['one'])
  })

  it('refits when the value is replaced from outside (a conflict reload, Use theirs)', async () => {
    fakeLayout()
    const w = mount(AutoGrowTextarea, { props: { modelValue: 'short' } })
    await w.setProps({ modelValue: 'a\nb\nc' })
    await nextTick()
    expect((w.get('textarea').element as HTMLTextAreaElement).style.height).toBe('57px')
  })

  it('never shows an inner scroll bar and keeps the page attributes on its wrapper', () => {
    const w = mount(AutoGrowTextarea, {
      props: { modelValue: 'x', id: 'box-1' },
      attrs: { 'data-test': 'the-box' },
    })
    expect(w.attributes('data-test')).toBe('the-box')
    expect(w.get('textarea').attributes('id')).toBe('box-1')
    expect(w.get('textarea').classes()).toContain('agrow__box')
  })
})
