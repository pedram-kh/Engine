/**
 * AH-119 (walk) — `MessageImage` + `ImageLightbox`: tap an inline message image → full-screen view.
 *
 * Pins: the thumbnail is a button (no link, no new tab); tap opens the overlay; the close button,
 * Esc and a backdrop click close it; a click on the image or a button does not hit the backdrop
 * rule; focus lands on Close and returns to the thumbnail; Download on the web saves the file,
 * in the Capacitor app it hands the file to the share sheet (the branch is the platform probe);
 * a load error is reported up.
 *
 * Break-revert: drop `@click.self="close"` → the backdrop case fails; swap the native/web branch in
 * `onDownload` → the two download cases fail; wrap the thumbnail in `<a target="_blank">` → the
 * no-link case fails.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { createVuetify } from 'vuetify'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

import enApp from '@/core/i18n/locales/en/app.json'

const hoisted = vi.hoisted(() => ({
  native: { value: false },
  downloadImageOnWeb: vi.fn(),
  shareImageOnNative: vi.fn(),
}))

vi.mock('@/core/platform/isNativePlatform', () => ({
  isNativePlatform: () => hoisted.native.value,
}))
vi.mock('@/core/platform/share/shareImage', () => ({
  downloadImageOnWeb: hoisted.downloadImageOnWeb,
  shareImageOnNative: hoisted.shareImageOnNative,
}))

import MessageImage from './MessageImage.vue'

const SRC = 'https://cdn.test/pic.png'

function mountImage() {
  return mount(MessageImage, {
    props: { src: SRC, alt: 'pic.png', name: 'pic.png' },
    attachTo: document.body,
    global: {
      plugins: [
        createVuetify({ components: vuetifyComponents, directives: vuetifyDirectives }),
        createI18n({ legacy: false, locale: 'en', messages: { en: enApp } }),
      ],
    },
  })
}

const inBody = (id: string): HTMLElement | null =>
  document.body.querySelector(`[data-test="${id}"]`)

async function open(wrapper: ReturnType<typeof mountImage>): Promise<void> {
  await wrapper.get('[data-test="message-image-open"]').trigger('click')
  await flushPromises()
  await new Promise((r) => setTimeout(r, 50))
}

describe('MessageImage / ImageLightbox', () => {
  beforeEach(() => {
    hoisted.native.value = false
    hoisted.downloadImageOnWeb.mockReset()
    hoisted.shareImageOnNative.mockReset()
  })
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('renders the thumbnail as a button around a lazy img: no link, no new tab, overlay closed', () => {
    const w = mountImage()
    expect(w.find('a').exists()).toBe(false)
    expect(w.get('[data-test="message-image-open"]').element.tagName).toBe('BUTTON')
    expect(w.get('[data-test="message-image"]').attributes('loading')).toBe('lazy')
    expect(inBody('message-lightbox')).toBeNull()
    w.unmount()
  })

  it('opens the overlay on tap, with the full image, a labelled Close and Download', async () => {
    const w = mountImage()
    await open(w)
    expect(inBody('message-lightbox')).not.toBeNull()
    expect(inBody('message-lightbox-image')?.getAttribute('src')).toBe(SRC)
    expect(inBody('message-lightbox-close')?.getAttribute('aria-label')).toBe('Close')
    expect(inBody('message-lightbox-download')?.getAttribute('aria-label')).toBe('Download')
    expect(document.body.querySelector('a[target="_blank"]')).toBeNull()
    w.unmount()
  })

  it('closes from the close button', async () => {
    const w = mountImage()
    await open(w)
    inBody('message-lightbox-close')!.click()
    await flushPromises()
    await new Promise((r) => setTimeout(r, 400))
    expect(document.body.querySelector('.v-overlay--active')).toBeNull()
    w.unmount()
  })

  it('closes on Esc', async () => {
    const w = mountImage()
    await open(w)
    document.activeElement?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    await flushPromises()
    await new Promise((r) => setTimeout(r, 400))
    expect(document.body.querySelector('.v-overlay--active')).toBeNull()
    w.unmount()
  })

  it('closes on a backdrop click, but not on a click on the image', async () => {
    const w = mountImage()
    await open(w)
    inBody('message-lightbox-image')!.click()
    await flushPromises()
    expect(inBody('message-lightbox')).not.toBeNull()
    expect(document.body.querySelector('.v-overlay--active')).not.toBeNull()
    inBody('message-lightbox')!.click()
    await flushPromises()
    await new Promise((r) => setTimeout(r, 400))
    expect(document.body.querySelector('.v-overlay--active')).toBeNull()
    w.unmount()
  })

  // jsdom does not run the dialog's focus trap; the Playwright walk asserts real focus + restore.
  it('asks for initial focus on Close when it opens', async () => {
    const w = mountImage()
    await open(w)
    expect(inBody('message-lightbox-close')?.hasAttribute('autofocus')).toBe(true)
    w.unmount()
  })

  it('Download on the web saves the file and does not open the share sheet', async () => {
    const w = mountImage()
    await open(w)
    inBody('message-lightbox-download')!.click()
    await flushPromises()
    expect(hoisted.downloadImageOnWeb).toHaveBeenCalledWith(SRC, 'pic.png')
    expect(hoisted.shareImageOnNative).not.toHaveBeenCalled()
    w.unmount()
  })

  it('Download in the Capacitor app hands the file to the share sheet', async () => {
    hoisted.native.value = true
    const w = mountImage()
    await open(w)
    inBody('message-lightbox-download')!.click()
    await flushPromises()
    expect(hoisted.shareImageOnNative).toHaveBeenCalledWith(SRC, 'pic.png')
    expect(hoisted.downloadImageOnWeb).not.toHaveBeenCalled()
    w.unmount()
  })

  it('reports an image load error upward', async () => {
    const w = mountImage()
    await w.get('[data-test="message-image"]').trigger('error')
    expect(w.emitted('error')).toHaveLength(1)
    w.unmount()
  })
})
