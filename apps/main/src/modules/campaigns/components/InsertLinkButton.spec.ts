/**
 * AH-082 — the shared "Insert link" editor sugar. Covers the five cases the
 * plan-pause named: insert-with-selection, insert-without-selection (incl.
 * the required-text-field rejection), cursor-restore, the http/https client
 * check, and the maxlength guard (compute-before-insert, never truncate).
 *
 * The dialog teleports to <body> (a plain v-dialog), so its fields are
 * queried there, not through `wrapper.find` — same pattern
 * `RelationshipThreadView.spec.ts` uses for its own link dialog.
 */

import { type VueWrapper, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

import InsertLinkButton, { type TextareaHandle } from './InsertLinkButton.vue'

function makeTextareaHandle(overrides: Partial<TextareaHandle> = {}): TextareaHandle {
  return {
    selectionStart: 0,
    selectionEnd: 0,
    focus: vi.fn(),
    setSelectionRange: vi.fn(),
    ...overrides,
  }
}

function mountButton(props: {
  modelValue: string
  textareaRef: TextareaHandle | null
  maxlength?: number
}) {
  const vuetify = createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: enApp } as never })
  return mount(InsertLinkButton, {
    props,
    global: { plugins: [vuetify, i18n] },
    attachTo: document.createElement('div'),
  })
}

async function openDialog(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('[data-test="insert-link-button"]').trigger('click')
  await flushPromises()
}

function urlInput(): HTMLInputElement {
  return document.body.querySelector('[data-test="insert-link-url"] input') as HTMLInputElement
}

function textInput(): HTMLInputElement | null {
  return document.body.querySelector('[data-test="insert-link-text"] input')
}

async function typeInto(el: HTMLInputElement, value: string): Promise<void> {
  el.value = value
  el.dispatchEvent(new Event('input'))
  await flushPromises()
}

function clickInsert(): void {
  ;(document.body.querySelector('[data-test="insert-link-insert"]') as HTMLElement).click()
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('InsertLinkButton — with a selection', () => {
  it('wraps the selection as the markdown link label, with no text field to fill', async () => {
    const ta = makeTextareaHandle({ selectionStart: 6, selectionEnd: 13 }) // "shoot a video please" -> "a video"
    const wrapper = mountButton({ modelValue: 'shoot a video please', textareaRef: ta })
    await openDialog(wrapper)

    expect(textInput()).toBeNull() // the selection IS the label — nothing to fill in

    await typeInto(urlInput(), 'https://example.com/brief')
    clickInsert()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
      'shoot [a video](https://example.com/brief) please',
    ])
    wrapper.unmount()
  })
})

describe('InsertLinkButton — with no selection (cursor only)', () => {
  it('requires the link text, then inserts [text](url) at the cursor once filled', async () => {
    const ta = makeTextareaHandle({ selectionStart: 6, selectionEnd: 6 })
    const wrapper = mountButton({ modelValue: 'brief:', textareaRef: ta })
    await openDialog(wrapper)

    expect(textInput()).not.toBeNull()

    // Rejected without a label — nothing emitted, no silent empty-label insert.
    await typeInto(urlInput(), 'https://example.com')
    clickInsert()
    await flushPromises()
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(document.body.textContent).toContain('Enter the text this link should show.')

    await typeInto(textInput() as HTMLInputElement, 'style guide')
    clickInsert()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
      'brief:[style guide](https://example.com)',
    ])
    wrapper.unmount()
  })
})

describe('InsertLinkButton — cursor restore', () => {
  it('focuses the textarea and places the cursor right after the inserted markdown', async () => {
    const ta = makeTextareaHandle({ selectionStart: 6, selectionEnd: 6 })
    const wrapper = mountButton({ modelValue: 'brief:', textareaRef: ta })
    await openDialog(wrapper)

    await typeInto(urlInput(), 'https://example.com')
    await typeInto(textInput() as HTMLInputElement, 'link')
    clickInsert()
    await flushPromises()

    // "brief:" (6) + "[link](https://example.com)" (27) = cursor at 33.
    expect(ta.focus).toHaveBeenCalled()
    expect(ta.setSelectionRange).toHaveBeenCalledWith(33, 33)
    wrapper.unmount()
  })
})

describe('InsertLinkButton — the http/https client check', () => {
  it('rejects a javascript: URL client-side, without inserting', async () => {
    const ta = makeTextareaHandle({ selectionStart: 0, selectionEnd: 0 })
    const wrapper = mountButton({ modelValue: '', textareaRef: ta })
    await openDialog(wrapper)

    await typeInto(urlInput(), 'javascript:alert(1)')
    clickInsert()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(document.body.textContent).toContain('Enter a valid http or https link.')
    wrapper.unmount()
  })
})

describe('InsertLinkButton — the maxlength guard', () => {
  it('rejects an insert that would exceed maxlength, without truncating anything', async () => {
    const ta = makeTextareaHandle({ selectionStart: 5, selectionEnd: 5 })
    const wrapper = mountButton({ modelValue: 'short', textareaRef: ta, maxlength: 10 })
    await openDialog(wrapper)

    await typeInto(urlInput(), 'https://example.com')
    await typeInto(textInput() as HTMLInputElement, 'a much too long label')
    clickInsert()
    await flushPromises()

    // "short" + the markdown would be far past 10 chars — rejected, not clipped.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(document.body.textContent).toContain('This link is too long to fit here')
    wrapper.unmount()
  })
})
